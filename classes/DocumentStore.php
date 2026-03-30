<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Manages the custom database tables for documents and their references.
 */
class DocumentStore {
    private const OPTION_REFERENCED_CACHE = 'geweb_aisearch_referenced_documents_cache';
    private const OPTION_REFERENCED_CACHE_TIME = 'geweb_aisearch_referenced_documents_cache_time';
    private const OPTION_REFERENCED_CACHE_DEBUG = 'geweb_aisearch_referenced_documents_cache_debug';
    private const OPTION_REFERENCED_SELECTION_TARGETS = 'geweb_aisearch_referenced_document_selection_targets';
    private const OPTION_CONNECTION_STATUS = 'geweb_aisearch_connection_status';
    private static $documentsTable;
    private static $refsTable;

    /**
     * Initialize table names.
     */
    public static function init(): void {
        global $wpdb;
        self::$documentsTable = $wpdb->prefix . 'geweb_ai_documents';
        self::$refsTable = $wpdb->prefix . 'geweb_ai_post_document_refs';
    }

    /**
     * Create custom database tables on plugin activation.
     */
    public static function install(): void {
        self::init();
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql_docs = "CREATE TABLE " . self::$documentsTable . " (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            file_hash VARCHAR(64) NOT NULL,
            gemini_doc_name VARCHAR(255) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            last_uploaded BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY file_hash (file_hash),
            UNIQUE KEY gemini_doc_name (gemini_doc_name)
        ) $charset_collate;";

        $sql_refs = "CREATE TABLE " . self::$refsTable . " (
            post_id BIGINT(20) UNSIGNED NOT NULL,
            document_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (post_id, document_id),
            KEY document_id (document_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_docs);
        dbDelta($sql_refs);
    }

    /**
     * Get or create a document record, handling deduplication.
     *
     * @param string $filePath Absolute path to the local file.
     * @param int $postId The post ID making the request (for naming).
     * @return int|null The document ID or null on failure.
     */
    public function getOrCreateDocument(string $filePath, int $postId): ?int {
        self::init();
        global $wpdb;

        if (!file_exists($filePath)) {
            error_log('geweb-ai-search: referenced document upload skipped because file does not exist: ' . $filePath);
            return null;
        }

        $fileHash = hash_file('sha256', $filePath);
        if (!$fileHash) {
            error_log('geweb-ai-search: referenced document upload skipped because file hash could not be computed: ' . $filePath);
            return null;
        }

        // Check if document already exists
        $existingId = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::$documentsTable . " WHERE file_hash = %s", $fileHash));
        if ($existingId) {
            error_log('geweb-ai-search: referenced document already tracked locally: ' . basename($filePath) . ' (document_id=' . (int) $existingId . ')');
            return (int) $existingId;
        }

        // If not, upload it
        try {
            $mimeType = $this->resolveMimeType($filePath);
            if ($mimeType === '') {
                error_log('geweb-ai-search: referenced document upload skipped because MIME type could not be resolved: ' . $filePath);
                return null;
            }

            $gemini = ProviderFactory::make();
            $displayName = $postId . '-' . basename($filePath);
            error_log('geweb-ai-search: uploading referenced document to Gemini: ' . $displayName . ' (' . $mimeType . ')');
            $geminiDocName = $gemini->uploadLocalFile($filePath, $displayName, $mimeType);
            error_log('geweb-ai-search: uploaded referenced document to Gemini as ' . $geminiDocName);

            $result = $wpdb->insert(
                self::$documentsTable,
                [
                    'file_hash' => $fileHash,
                    'gemini_doc_name' => $geminiDocName,
                    'display_name' => basename($filePath),
                    'last_uploaded' => time(),
                ],
                ['%s', '%s', '%s', '%d']
            );

            if (!$result) {
                $existing = $wpdb->get_row(
                    $wpdb->prepare("SELECT id, gemini_doc_name FROM " . self::$documentsTable . " WHERE file_hash = %s", $fileHash),
                    ARRAY_A
                );

                if (is_array($existing) && !empty($existing['id'])) {
                    if (
                        !empty($geminiDocName) &&
                        !empty($existing['gemini_doc_name']) &&
                        (string) $existing['gemini_doc_name'] !== $geminiDocName
                    ) {
                        try {
                            $gemini->deleteDocument($geminiDocName);
                            error_log('geweb-ai-search: removed duplicate Gemini upload after local file_hash race for ' . $displayName);
                        } catch (\Exception $cleanupException) {
                            error_log('geweb-ai-search: could not remove duplicate Gemini upload after local file_hash race for ' . $displayName . ': ' . $cleanupException->getMessage());
                        }
                    }

                    error_log('geweb-ai-search: reusing existing referenced document tracking row for ' . $displayName . ' (document_id=' . (int) $existing['id'] . ')');
                    return (int) $existing['id'];
                }

                error_log('geweb-ai-search: failed to insert referenced document tracking row for ' . $displayName);
            }

            return $result ? (int) $wpdb->insert_id : null;
        } catch (\Exception $e) {
            error_log('geweb-ai-search: referenced document upload failed for ' . basename($filePath) . ': ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve the MIME type for a local file.
     *
     * @param string $filePath
     * @return string
     */
    private function resolveMimeType(string $filePath): string {
        $fileInfo = wp_check_filetype(basename($filePath));
        $mimeType = isset($fileInfo['type']) ? (string) $fileInfo['type'] : '';
        if ($mimeType !== '') {
            return $mimeType;
        }

        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($filePath);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }

        return '';
    }

    /**
     * Build a live overview of referenced local documents across managed posts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getReferencedDocumentOverview(bool $forceRefresh = false): array {
        if (!$forceRefresh) {
            $cached = get_option(self::OPTION_REFERENCED_CACHE, null);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $items = $this->buildReferencedDocumentOverview();
        update_option(self::OPTION_REFERENCED_CACHE, $items, false);
        update_option(self::OPTION_REFERENCED_CACHE_TIME, (string) time(), false);

        return $items;
    }

    /**
     * @return bool
     */
    public function hasReferencedDocumentOverviewCache(): bool {
        return is_array(get_option(self::OPTION_REFERENCED_CACHE, null));
    }

    /**
     * @return int
     */
    public function getReferencedDocumentOverviewCacheTime(): int {
        return (int) get_option(self::OPTION_REFERENCED_CACHE_TIME, 0);
    }

    /**
     * @return array<string,int>
     */
    public function getReferencedDocumentOverviewDebug(): array {
        $debug = get_option(self::OPTION_REFERENCED_CACHE_DEBUG, []);
        return is_array($debug) ? $debug : [];
    }

    /**
     * @return void
     */
    public function clearReferencedDocumentOverviewCache(): void {
        delete_option(self::OPTION_REFERENCED_CACHE);
        delete_option(self::OPTION_REFERENCED_CACHE_TIME);
        delete_option(self::OPTION_REFERENCED_CACHE_DEBUG);
    }

    /**
     * Build a live overview of referenced local documents across managed posts.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildReferencedDocumentOverview(): array {
        self::init();

        $uploadsEnabled = get_option('geweb_aisearch_include_referenced_documents', '0') === '1';
        $postTypes = $this->getPostTypesForReferencedDocumentOverview();
        $connectionStatus = get_option(self::OPTION_CONNECTION_STATUS, []);
        $connectionState = is_array($connectionStatus) ? (string) ($connectionStatus['status'] ?? '') : '';

        $uploadedByHash = [];
        foreach ($this->getAllDocuments() as $document) {
            if (!is_array($document) || empty($document['file_hash'])) {
                continue;
            }
            $uploadedByHash[(string) $document['file_hash']] = $document;
        }

        $posts = get_posts([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'numberposts' => -1,
        ]);
        $debug = [
            'managed_posts' => is_array($posts) ? count($posts) : 0,
            'posts_with_document_links' => 0,
            'accepted_documents' => 0,
            'using_all_public_post_types' => (int) ($this->isUsingFallbackPostTypes() ? 1 : 0),
        ];

        $overview = [];
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $isSimpleFileListPage = $this->isSimpleFileListPage($post);
            $references = HTML2MD::getReferencedAttachmentEntriesForPost($post->ID);
            if ($isSimpleFileListPage) {
                $references = $this->expandSimpleFileListReferences($references);
            }
            if (!empty($references)) {
                $debug['posts_with_document_links']++;
            }
            foreach ($references as $reference) {
                $filePath = isset($reference['file_path']) ? (string) $reference['file_path'] : '';
                $fileUrl = isset($reference['file_url']) ? (string) $reference['file_url'] : '';
                if ($filePath === '' || $fileUrl === '') {
                    continue;
                }

                $hash = hash_file('sha256', $filePath);
                if (!$hash) {
                    continue;
                }
                $debug['accepted_documents']++;

                if (!isset($overview[$hash])) {
                    $uploaded = $uploadedByHash[$hash] ?? null;
                    $status = 'Detected, not uploaded';
                    $color = '#996800';
                    $lastUploaded = 0;

                    if (is_array($uploaded)) {
                        $status = 'Uploaded';
                        $color = '#46b450';
                        $lastUploaded = isset($uploaded['last_uploaded']) ? (int) $uploaded['last_uploaded'] : 0;
                    } elseif ($uploadsEnabled && $connectionState === 'failed') {
                        $status = 'Detected, not uploaded';
                        $color = '#d63638';
                    } elseif (!$uploadsEnabled) {
                        $status = 'Uploads disabled';
                        $color = '#646970';
                    }

                    $overview[$hash] = [
                        'file_hash' => $hash,
                        'display_name' => basename($filePath),
                        'nice_name' => $this->resolveOverviewDisplayName($reference, $filePath),
                        'status' => $status,
                        'status_color' => $color,
                        'mime_type' => $this->resolveMimeType($filePath),
                        'last_uploaded' => $lastUploaded,
                        'file_url' => $fileUrl,
                        'file_path' => $filePath,
                        'document_id' => is_array($uploaded) && isset($uploaded['id']) ? (int) $uploaded['id'] : 0,
                        'gemini_doc_name' => is_array($uploaded) && isset($uploaded['gemini_doc_name']) ? (string) $uploaded['gemini_doc_name'] : '',
                        'reference_count' => 0,
                        'external_reference_count' => 0,
                        'managed_by_simple_file_list' => false,
                        'posts' => [],
                    ];
                } elseif (empty($overview[$hash]['nice_name']) || $overview[$hash]['nice_name'] === basename($filePath)) {
                    $overview[$hash]['nice_name'] = $this->resolveOverviewDisplayName($reference, $filePath);
                }

                if ($isSimpleFileListPage) {
                    $overview[$hash]['managed_by_simple_file_list'] = true;
                } else {
                    $overview[$hash]['posts'][$post->ID] = [
                        'id' => $post->ID,
                        'title' => get_the_title($post->ID),
                        'edit_url' => (string) get_edit_post_link($post->ID),
                    ];
                    $overview[$hash]['external_reference_count'] = (int) $overview[$hash]['external_reference_count'] + 1;
                }
            }
        }

        $this->mergeSimpleFileListOptionEntries($overview, $uploadedByHash, $uploadsEnabled, $connectionState);

        $selectionTargets = $this->getReferencedDocumentSelectionTargets();

        $items = array_values(array_map(function (array $item) use ($selectionTargets): array {
            $item['posts'] = array_values($item['posts']);
            $item['reference_count'] = count($item['posts']);
            if (!empty($item['managed_by_simple_file_list'])) {
                $externalReferenceCount = isset($item['external_reference_count']) ? (int) $item['external_reference_count'] : 0;
                if ($externalReferenceCount === 0) {
                    $item['status'] = strpos((string) $item['status'], 'Uploaded') === 0
                        ? 'Uploaded, only in Simple File List'
                        : 'Only in Simple File List';
                    $item['status_color'] = '#996800';
                } else {
                    $item['status'] = strpos((string) $item['status'], 'Uploaded') === 0
                        ? 'Uploaded, referenced elsewhere'
                        : 'Referenced outside Simple File List';
                    $item['status_color'] = '#46b450';
                }
            }

            $fileHash = (string) ($item['file_hash'] ?? '');
            $defaultTarget = $this->getDefaultReferencedDocumentTarget($item);
            $includeTarget = array_key_exists($fileHash, $selectionTargets)
                ? (bool) $selectionTargets[$fileHash]
                : $defaultTarget;

            $item['include_in_store_target'] = $includeTarget;
            $item['default_include_in_store_target'] = $defaultTarget;
            return $item;
        }, $overview));

        foreach ($uploadedByHash as $hash => $document) {
            if (isset($overview[$hash])) {
                continue;
            }

            $items[] = [
                'file_hash' => (string) $hash,
                'display_name' => isset($document['display_name']) ? (string) $document['display_name'] : 'Unknown document',
                'nice_name' => isset($document['display_name']) ? (string) $document['display_name'] : '',
                'status' => 'Uploaded, not referenced',
                'status_color' => '#d63638',
                'mime_type' => '',
                'last_uploaded' => isset($document['last_uploaded']) ? (int) $document['last_uploaded'] : 0,
                'file_url' => '',
                'file_path' => '',
                'document_id' => isset($document['id']) ? (int) $document['id'] : 0,
                'gemini_doc_name' => isset($document['gemini_doc_name']) ? (string) $document['gemini_doc_name'] : '',
                'reference_count' => 0,
                'external_reference_count' => 0,
                'managed_by_simple_file_list' => false,
                'posts' => [],
            ];
        }

        usort($items, static function (array $left, array $right): int {
            return strcasecmp((string) $left['display_name'], (string) $right['display_name']);
        });

        update_option(self::OPTION_REFERENCED_CACHE_DEBUG, $debug, false);

        return $items;
    }

    /**
     * Resolve which post types should be scanned for referenced documents.
     *
     * @return array<int,string>
     */
    private function getPostTypesForReferencedDocumentOverview(): array {
        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (is_array($postTypes) && !empty($postTypes)) {
            return array_values(array_filter(array_map('sanitize_key', $postTypes), static function ($postType): bool {
                return is_string($postType) && $postType !== '';
            }));
        }

        return array_values(get_post_types(['public' => true], 'names'));
    }

    /**
     * @return bool
     */
    private function isUsingFallbackPostTypes(): bool {
        $postTypes = get_option('geweb_aisearch_post_types', []);
        return !is_array($postTypes) || empty($postTypes);
    }

    /**
     * Determine whether a post is rendering a Simple File List shortcode.
     *
     * @param \WP_Post $post
     * @return bool
     */
    private function isSimpleFileListPage(\WP_Post $post): bool {
        if (!shortcode_exists('eeSFL')) {
            return false;
        }

        return has_shortcode((string) $post->post_content, 'eeSFL');
    }

    /**
     * Expand Simple File List references to include additional files in the same list folder.
     *
     * @param array<int,array<string,string>> $references
     * @return array<int,array<string,string>>
     */
    private function expandSimpleFileListReferences(array $references): array {
        $expanded = [];
        $directories = [];

        foreach ($references as $reference) {
            if (!is_array($reference)) {
                continue;
            }

            $filePath = isset($reference['file_path']) ? (string) $reference['file_path'] : '';
            $fileUrl = isset($reference['file_url']) ? (string) $reference['file_url'] : '';
            if ($filePath === '' || $fileUrl === '' || !is_readable($filePath)) {
                continue;
            }

            $expanded[$filePath] = $reference;
            $directory = wp_normalize_path((string) dirname($filePath));
            if ($directory !== '') {
                $directories[$directory] = [
                    'dir_url' => untrailingslashit((string) dirname($fileUrl)),
                ];
            }
        }

        $niceNameMap = $this->getSimpleFileListNiceNameMap(array_keys($directories));

        foreach ($expanded as $filePath => $reference) {
            if (isset($niceNameMap[$filePath]) && $niceNameMap[$filePath] !== '') {
                $expanded[$filePath]['display_name'] = $niceNameMap[$filePath];
            }
        }

        foreach ($directories as $directory => $data) {
            foreach ($this->getSupportedFilesInDirectory($directory) as $filePath) {
                $baseName = basename($filePath);
                $dirUrl = isset($data['dir_url']) ? (string) $data['dir_url'] : '';
                $fileUrl = $dirUrl !== '' ? $dirUrl . '/' . rawurlencode($baseName) : '';
                if ($fileUrl === '') {
                    continue;
                }

                $niceName = $niceNameMap[$filePath] ?? $this->prettifyFileName($baseName);
                if (isset($expanded[$filePath])) {
                    if ($niceName !== '') {
                        $expanded[$filePath]['display_name'] = $niceName;
                    }
                    continue;
                }

                $expanded[$filePath] = [
                    'file_path' => $filePath,
                    'file_url' => $fileUrl,
                    'display_name' => $niceName,
                ];
            }
        }

        return array_values($expanded);
    }

    /**
     * Merge Simple File List database entries so SFL-only files appear even without website references.
     *
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     * @param bool $uploadsEnabled
     * @param string $connectionState
     * @return void
     */
    private function mergeSimpleFileListOptionEntries(array &$overview, array $uploadedByHash, bool $uploadsEnabled, string $connectionState): void {
        foreach ($this->getSimpleFileListEntries() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $filePath = isset($entry['file_path']) ? (string) $entry['file_path'] : '';
            $fileUrl = isset($entry['file_url']) ? (string) $entry['file_url'] : '';
            if ($filePath === '' || $fileUrl === '' || !is_readable($filePath)) {
                continue;
            }

            $hash = hash_file('sha256', $filePath);
            if (!$hash) {
                continue;
            }

            $displayName = isset($entry['display_name']) ? trim((string) $entry['display_name']) : basename($filePath);
            if (isset($overview[$hash])) {
                $overview[$hash]['managed_by_simple_file_list'] = true;
                if ($displayName !== '' && (empty($overview[$hash]['nice_name']) || $overview[$hash]['nice_name'] === basename($filePath))) {
                    $overview[$hash]['nice_name'] = $displayName;
                }
                continue;
            }

            $uploaded = $uploadedByHash[$hash] ?? null;
            $status = 'Detected, not uploaded';
            $color = '#996800';
            $lastUploaded = 0;

            if (is_array($uploaded)) {
                $status = 'Uploaded';
                $color = '#46b450';
                $lastUploaded = isset($uploaded['last_uploaded']) ? (int) $uploaded['last_uploaded'] : 0;
            } elseif ($uploadsEnabled && $connectionState === 'failed') {
                $status = 'Detected, not uploaded';
                $color = '#d63638';
            } elseif (!$uploadsEnabled) {
                $status = 'Uploads disabled';
                $color = '#646970';
            }

            $overview[$hash] = [
                'file_hash' => $hash,
                'display_name' => basename($filePath),
                'nice_name' => $displayName,
                'status' => $status,
                'status_color' => $color,
                'mime_type' => $this->resolveMimeType($filePath),
                'last_uploaded' => $lastUploaded,
                'file_url' => $fileUrl,
                'file_path' => $filePath,
                'document_id' => is_array($uploaded) && isset($uploaded['id']) ? (int) $uploaded['id'] : 0,
                'gemini_doc_name' => is_array($uploaded) && isset($uploaded['gemini_doc_name']) ? (string) $uploaded['gemini_doc_name'] : '',
                'reference_count' => 0,
                'external_reference_count' => 0,
                'managed_by_simple_file_list' => true,
                'posts' => [],
            ];
        }
    }

    /**
     * Read Simple File List file entries directly from eeSFL_FileList_* options.
     *
     * @return array<int,array<string,string>>
     */
    private function getSimpleFileListEntries(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL\\_FileList\\_%'",
            ARRAY_A
        );
        if (!is_array($rows)) {
            error_log('geweb-ai-search: SFL entry lookup found no eeSFL_FileList_* option rows.');
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['option_value'])) {
                continue;
            }

            $optionName = isset($row['option_name']) ? (string) $row['option_name'] : 'unknown-option';
            $value = maybe_unserialize($row['option_value']);
            if (!is_array($value)) {
                error_log('geweb-ai-search: SFL option ' . $optionName . ' did not unserialize to an array.');
                continue;
            }

            foreach ($value as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
                if ($filePathValue === '') {
                    continue;
                }

                $resolved = $this->resolveSimpleFileListRecordPath($filePathValue);
                if ($resolved === null) {
                    continue;
                }

                $entries[$resolved['file_path']] = [
                    'file_path' => $resolved['file_path'],
                    'file_url' => $resolved['file_url'],
                    'display_name' => isset($record['FileNiceName']) && trim((string) $record['FileNiceName']) !== ''
                        ? trim((string) $record['FileNiceName'])
                        : $this->prettifyFileName(basename($resolved['file_path'])),
                ];
            }
        }

        error_log('geweb-ai-search: SFL entry lookup resolved ' . count($entries) . ' file(s).');

        return array_values($entries);
    }

    /**
     * @param string $directory
     * @return array<int,string>
     */
    private function getSupportedFilesInDirectory(string $directory): array {
        if ($directory === '' || !is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        $files = [];
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $filePath = wp_normalize_path(trailingslashit($directory) . $entry);
            if (!is_file($filePath) || !is_readable($filePath)) {
                continue;
            }

            $fileType = wp_check_filetype($entry);
            $extension = isset($fileType['ext']) ? (string) $fileType['ext'] : '';
            $typeGroup = $extension !== '' ? wp_ext2type($extension) : false;
            if (in_array($typeGroup, ['image', 'audio', 'video'], true)) {
                continue;
            }

            $files[] = $filePath;
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /**
     * @param array<string,mixed> $reference
     * @param string $filePath
     * @return string
     */
    private function resolveOverviewDisplayName(array $reference, string $filePath): string {
        $displayName = isset($reference['display_name']) ? trim((string) $reference['display_name']) : '';
        if ($displayName !== '') {
            return $displayName;
        }

        return $this->prettifyFileName(basename($filePath));
    }

    /**
     * @param string $fileName
     * @return string
     */
    private function prettifyFileName(string $fileName): string {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        if ($name === '') {
            return $fileName;
        }

        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim((string) $name);

        return $name !== '' ? $name : $fileName;
    }

    /**
     * Best-effort lookup of Simple File List nice names from WordPress options and custom tables.
     *
     * @param array<int,string> $directories
     * @return array<string,string>
     */
    private function getSimpleFileListNiceNameMap(array $directories): array {
        global $wpdb;

        $targets = [];
        $uploads = wp_get_upload_dir();
        $baseDir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));

        foreach ($directories as $directory) {
            foreach ($this->getSupportedFilesInDirectory($directory) as $filePath) {
                $normalizedPath = wp_normalize_path($filePath);
                $targets[$normalizedPath] = [
                    'basename' => basename($normalizedPath),
                    'relative_path' => $baseDir !== '' && strpos($normalizedPath, $baseDir) === 0
                        ? ltrim(substr($normalizedPath, strlen($baseDir)), '/')
                        : basename($normalizedPath),
                ];
            }
        }

        if (empty($targets)) {
            return [];
        }

        $niceNames = $this->getSimpleFileListNiceNamesFromFileListOptions($targets);

        $optionRows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL%' OR option_name LIKE '%simple_file_list%'",
            ARRAY_A
        );
        if (is_array($optionRows)) {
            foreach ($optionRows as $row) {
                if (!is_array($row) || !isset($row['option_value'])) {
                    continue;
                }

                $value = maybe_unserialize($row['option_value']);
                $this->scanSimpleFileListMetadata($value, $targets, $niceNames);
            }
        }

        $candidateTables = array_unique(array_filter(array_merge(
            $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'eeSFL') . '%')),
            $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'simple_file_list') . '%')),
            $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'eesfl') . '%'))
        )));

        foreach ($candidateTables as $tableName) {
            $tableName = (string) $tableName;
            if ($tableName === '') {
                continue;
            }

            $rows = $wpdb->get_results("SELECT * FROM {$tableName}", ARRAY_A);
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $this->scanSimpleFileListMetadata($row, $targets, $niceNames);
            }
        }

        return $niceNames;
    }

    /**
     * Read exact Simple File List file metadata from eeSFL_FileList_* options.
     *
     * @param array<string,array<string,string>> $targets
     * @return array<string,string>
     */
    private function getSimpleFileListNiceNamesFromFileListOptions(array $targets): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL\\_FileList\\_%'",
            ARRAY_A
        );
        if (!is_array($rows)) {
            error_log('geweb-ai-search: SFL nice-name lookup found no eeSFL_FileList_* option rows.');
            return [];
        }

        error_log('geweb-ai-search: SFL nice-name lookup scanning ' . count($rows) . ' eeSFL_FileList_* option row(s) for ' . count($targets) . ' target file(s).');
        $niceNames = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['option_value'])) {
                continue;
            }

            $optionName = isset($row['option_name']) ? (string) $row['option_name'] : 'unknown-option';
            $value = maybe_unserialize($row['option_value']);
            if (!is_array($value)) {
                error_log('geweb-ai-search: SFL option ' . $optionName . ' did not unserialize to an array.');
                continue;
            }

            foreach ($value as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
                $niceName = isset($record['FileNiceName']) ? trim((string) $record['FileNiceName']) : '';
                if ($filePathValue === '' || $niceName === '') {
                    continue;
                }

                $matchedPath = $this->matchSimpleFileListRecordPath($filePathValue, $targets);
                if ($matchedPath === null || isset($niceNames[$matchedPath])) {
                    continue;
                }

                $niceNames[$matchedPath] = $niceName;
                error_log('geweb-ai-search: SFL nice-name match from ' . $optionName . ' for ' . basename($matchedPath) . ' => ' . $niceName);
            }
        }

        error_log('geweb-ai-search: SFL nice-name lookup resolved ' . count($niceNames) . ' file(s).');
        return $niceNames;
    }

    /**
     * Resolve a Simple File List FilePath value to a real uploads file path and URL.
     *
     * @param string $filePathValue
     * @return array<string,string>|null
     */
    private function resolveSimpleFileListRecordPath(string $filePathValue): ?array {
        $uploads = wp_get_upload_dir();
        $baseDir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
        $baseUrl = (string) ($uploads['baseurl'] ?? '');
        if ($baseDir === '' || $baseUrl === '') {
            return null;
        }

        $normalizedValue = wp_normalize_path(ltrim(trim($filePathValue), '/'));
        $candidates = [];

        if ($normalizedValue !== '') {
            $directCandidate = wp_normalize_path(trailingslashit($baseDir) . $normalizedValue);
            $candidates[] = $directCandidate;
        }

        $baseName = basename($normalizedValue);
        if ($baseName !== '') {
            $searched = $this->findFileInUploadsByBasename($baseName, $baseDir);
            if ($searched !== null) {
                $candidates[] = $searched;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === '' || !is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            if (strpos($candidate, $baseDir) !== 0) {
                continue;
            }

            $relativePath = ltrim(substr($candidate, strlen($baseDir)), '/');
            return [
                'file_path' => $candidate,
                'file_url' => trailingslashit($baseUrl) . str_replace('%2F', '/', rawurlencode($relativePath)),
            ];
        }

        return null;
    }

    /**
     * Find a file anywhere under uploads by basename.
     *
     * @param string $baseName
     * @param string $baseDir
     * @return string|null
     */
    private function findFileInUploadsByBasename(string $baseName, string $baseDir): ?string {
        static $indexedFiles = null;

        if ($indexedFiles === null) {
            $indexedFiles = [];
            if (is_dir($baseDir) && is_readable($baseDir)) {
                try {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
                    );

                    foreach ($iterator as $fileInfo) {
                        if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                            continue;
                        }

                        $indexedFiles[$fileInfo->getBasename()][] = wp_normalize_path($fileInfo->getPathname());
                    }
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        if (empty($indexedFiles[$baseName])) {
            return null;
        }

        return (string) $indexedFiles[$baseName][0];
    }

    /**
     * Recursively scan mixed metadata for file-name to nice-name matches.
     *
     * @param mixed $value
     * @param array<string,array<string,string>> $targets
     * @param array<string,string> $niceNames
     * @return void
     */
    private function scanSimpleFileListMetadata($value, array $targets, array &$niceNames): void {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return;
        }

        $match = $this->extractSimpleFileListRecordMatch($value, $targets);
        if ($match !== null) {
            $filePath = $match['file_path'];
            $niceName = $match['nice_name'];
            if ($filePath !== '' && $niceName !== '' && !isset($niceNames[$filePath])) {
                $niceNames[$filePath] = $niceName;
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested) || is_object($nested)) {
                $this->scanSimpleFileListMetadata($nested, $targets, $niceNames);
            }
        }
    }

    /**
     * Try to match a metadata record to one of the files we discovered in Simple File List.
     *
     * @param array<string,mixed> $record
     * @param array<string,array<string,string>> $targets
     * @return array<string,string>|null
     */
    private function extractSimpleFileListRecordMatch(array $record, array $targets): ?array {
        $stringFields = [];
        foreach ($record as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }

            $stringFields[(string) $key] = $stringValue;
        }

        if (empty($stringFields)) {
            return null;
        }

        foreach ($targets as $filePath => $target) {
            $basename = $target['basename'];
            $relativePath = $target['relative_path'];
            $matched = false;

            foreach ($stringFields as $fieldValue) {
                $normalizedField = wp_normalize_path($fieldValue);
                if ($fieldValue === $basename
                    || $normalizedField === wp_normalize_path($relativePath)
                    || str_ends_with($normalizedField, '/' . $basename)
                    || strpos($normalizedField, $basename) !== false
                ) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            foreach ($stringFields as $fieldKey => $fieldValue) {
                if (!preg_match('/nice|display|title|label/i', $fieldKey)) {
                    continue;
                }

                $niceName = trim($fieldValue);
                if ($this->isUsableSimpleFileListNiceName($niceName, $basename)) {
                    return [
                        'file_path' => $filePath,
                        'nice_name' => $niceName,
                    ];
                }
            }

            foreach ($stringFields as $fieldKey => $fieldValue) {
                if (!preg_match('/name/i', $fieldKey) || preg_match('/file|path|url|ext|type/i', $fieldKey)) {
                    continue;
                }

                $niceName = trim($fieldValue);
                if ($this->isUsableSimpleFileListNiceName($niceName, $basename)) {
                    return [
                        'file_path' => $filePath,
                        'nice_name' => $niceName,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Match a Simple File List FilePath value to one of our discovered files.
     *
     * @param string $filePathValue
     * @param array<string,array<string,string>> $targets
     * @return string|null
     */
    private function matchSimpleFileListRecordPath(string $filePathValue, array $targets): ?string {
        $normalizedValue = wp_normalize_path(ltrim(trim($filePathValue), '/'));

        foreach ($targets as $filePath => $target) {
            $basename = wp_normalize_path((string) ($target['basename'] ?? basename($filePath)));
            $relativePath = wp_normalize_path(ltrim((string) ($target['relative_path'] ?? ''), '/'));

            if ($normalizedValue === $basename || $normalizedValue === $relativePath || str_ends_with($relativePath, '/' . $normalizedValue)) {
                return $filePath;
            }
        }

        return null;
    }

    /**
     * @param string $niceName
     * @param string $basename
     * @return bool
     */
    private function isUsableSimpleFileListNiceName(string $niceName, string $basename): bool {
        if ($niceName === '' || $niceName === $basename) {
            return false;
        }

        if (strpos($niceName, '/') !== false || strpos($niceName, '\\') !== false) {
            return false;
        }

        return true;
    }

    /**
     * Update the associations between a post and its documents.
     *
     * @param int $postId The post ID.
     * @param array $documentIds An array of document IDs to associate.
     */
    public function updatePostAssociations(int $postId, array $documentIds): void {
        self::init();
        global $wpdb;
        $this->clearReferencedDocumentOverviewCache();

        // First, find out which documents are currently associated
        $currentDocIds = $wpdb->get_col($wpdb->prepare("SELECT document_id FROM " . self::$refsTable . " WHERE post_id = %d", $postId));

        // Find which associations to remove
        $toRemove = array_diff($currentDocIds, $documentIds);
        if (!empty($toRemove)) {
            $in = implode(',', array_map('intval', $toRemove));
            $wpdb->query($wpdb->prepare("DELETE FROM " . self::$refsTable . " WHERE post_id = %d AND document_id IN ($in)", $postId));
            $this->cleanupOrphanedDocuments($toRemove);
        }

        // Find which associations to add
        $toAdd = array_diff($documentIds, $currentDocIds);
        foreach ($toAdd as $docId) {
            $wpdb->insert(
                self::$refsTable,
                ['post_id' => $postId, 'document_id' => (int) $docId],
                ['%d', '%d']
            );
        }
    }

    /**
     * Disassociate a post from all its documents and clean up orphans.
     *
     * @param int $postId The post ID.
     */
    public function disassociatePost(int $postId): void {
        $this->clearReferencedDocumentOverviewCache();
        $this->updatePostAssociations($postId, []);
    }

    /**
     * Check a list of document IDs and delete any that are no longer referenced by any post.
     *
     * @param array $documentIds
     */
    private function cleanupOrphanedDocuments(array $documentIds): void {
        self::init();
        global $wpdb;
        $gemini = ProviderFactory::make();

        foreach ($documentIds as $docId) {
            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::$refsTable . " WHERE document_id = %d", $docId));
            if ((int) $count === 0) {
                // This document is an orphan, delete it from Gemini and our DB
                $doc = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::$documentsTable . " WHERE id = %d", $docId));
                if ($doc) {
                    try {
                        $gemini->deleteDocument($doc->gemini_doc_name);
                    } catch (\Exception $e) {
                        // Ignore Gemini errors, we still want to remove it from our DB
                    }
                    $wpdb->delete(self::$documentsTable, ['id' => $docId], ['%d']);
                }
            }
        }
    }

    /**
     * Get all documents for the list table.
     *
     * @return array
     */
    public function getAllDocuments(): array {
        self::init();
        global $wpdb;
        $results = $wpdb->get_results("SELECT * FROM " . self::$documentsTable, ARRAY_A);
        return is_array($results) ? $results : [];
    }

    /**
     * Remove local referenced-document tracking entries that no longer exist
     * in the active Gemini store.
     *
     * @param array<int,string> $remoteDocumentNames
     * @return int Number of removed local document rows.
     */
    public function reconcileTrackedDocumentsWithRemote(array $remoteDocumentNames): int {
        self::init();
        global $wpdb;

        $remoteLookup = [];
        foreach ($remoteDocumentNames as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $remoteLookup[trim($name)] = true;
        }

        $removedCount = 0;
        foreach ($this->getAllDocuments() as $document) {
            if (!is_array($document) || empty($document['id'])) {
                continue;
            }

            $documentName = isset($document['gemini_doc_name']) ? trim((string) $document['gemini_doc_name']) : '';
            if ($documentName !== '' && isset($remoteLookup[$documentName])) {
                continue;
            }

            $docId = (int) $document['id'];
            if ($docId <= 0) {
                continue;
            }

            $wpdb->delete(self::$refsTable, ['document_id' => $docId], ['%d']);
            $deleted = $wpdb->delete(self::$documentsTable, ['id' => $docId], ['%d']);
            if ($deleted) {
                $removedCount++;
            }
        }

        if ($removedCount > 0) {
            $this->clearReferencedDocumentOverviewCache();
        }

        return $removedCount;
    }

    /**
     * Enforce selection targets against remote reality for tracked documents.
     *
     * If a tracked file is marked as excluded but still exists in the active
     * Gemini store, attempt to remove it from the store immediately.
     *
     * @param array<int,string> $remoteDocumentNames
     * @return int Number of targets corrected to "included" after failed removals.
     */
    public function reconcileSelectionTargetsWithRemote(array $remoteDocumentNames): int {
        $remoteLookup = [];
        foreach ($remoteDocumentNames as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $remoteLookup[trim($name)] = true;
        }

        if (empty($remoteLookup)) {
            return 0;
        }

        $targets = $this->getReferencedDocumentSelectionTargets();
        $corrected = 0;

        foreach ($this->getAllDocuments() as $document) {
            if (!is_array($document)) {
                continue;
            }

            $documentName = isset($document['gemini_doc_name']) ? trim((string) $document['gemini_doc_name']) : '';
            $fileHash = isset($document['file_hash']) ? trim((string) $document['file_hash']) : '';
            if ($documentName === '' || $fileHash === '') {
                continue;
            }

            if (!isset($remoteLookup[$documentName])) {
                continue;
            }

            if (isset($targets[$fileHash]) && $targets[$fileHash] === false) {
                $removed = $this->removeReferencedDocumentByHash($fileHash);
                if (!$removed) {
                    // Remote removal failed; keep local status truthful.
                    $targets[$fileHash] = true;
                    $corrected++;
                }
            }
        }

        if ($corrected > 0) {
            $this->saveReferencedDocumentSelectionTargets($targets);
        }

        return $corrected;
    }

    /**
     * Clear all locally tracked referenced documents and associations.
     *
     * This is used when the active Gemini store changes, because document
     * names from the previous store are no longer valid in the new one.
     *
     * @return void
     */
    public function clearAllTrackedDocuments(): void {
        self::init();
        global $wpdb;

        $wpdb->query("DELETE FROM " . self::$refsTable);
        $wpdb->query("DELETE FROM " . self::$documentsTable);
        $this->clearReferencedDocumentOverviewCache();
    }

    /**
     * Get all posts that reference a specific document.
     *
     * @param int $documentId
     * @return array
     */
    public function getPostsForDocument(int $documentId): array {
        self::init();
        global $wpdb;
        $post_ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM " . self::$refsTable . " WHERE document_id = %d", $documentId));

        if (empty($post_ids)) {
            return [];
        }

        $posts = get_posts([
            'post__in' => $post_ids,
            'post_type' => 'any',
            'numberposts' => -1,
        ]);

        return is_array($posts) ? $posts : [];
    }

    /**
     * Upload a referenced document by file hash and associate it with all referencing posts.
     *
     * @param string $fileHash
     * @return bool
     */
    public function uploadReferencedDocumentByHash(string $fileHash): bool {
        $reference = $this->findReferenceByHash($fileHash);
        if (!$reference || empty($reference['file_path'])) {
            error_log('geweb-ai-search: uploadReferencedDocumentByHash could not resolve file hash ' . $fileHash);
            return false;
        }

        error_log('geweb-ai-search: uploadReferencedDocumentByHash starting for ' . (string) $reference['file_path']);
        $documentId = $this->getOrCreateDocument((string) $reference['file_path'], (int) $reference['primary_post_id']);
        if (!$documentId) {
            error_log('geweb-ai-search: uploadReferencedDocumentByHash failed for ' . (string) $reference['file_path']);
            return false;
        }

        if (!empty($reference['post_ids']) && is_array($reference['post_ids'])) {
            $this->associateDocumentWithPosts($documentId, $reference['post_ids']);
        }
        $this->clearReferencedDocumentOverviewCache();
        error_log('geweb-ai-search: uploadReferencedDocumentByHash succeeded for ' . (string) $reference['file_path'] . ' (document_id=' . $documentId . ')');

        return true;
    }

    /**
     * Remove a referenced document from the Gemini store and local DB by file hash.
     *
     * @param string $fileHash
     * @return bool
     */
    public function removeReferencedDocumentByHash(string $fileHash): bool {
        self::init();
        global $wpdb;

        $document = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::$documentsTable . " WHERE file_hash = %s", $fileHash),
            ARRAY_A
        );

        if (!is_array($document) || empty($document['id'])) {
            return false;
        }

        $docId = (int) $document['id'];
        $gemini = ProviderFactory::make();

        if (!empty($document['gemini_doc_name'])) {
            try {
                $gemini->deleteDocument((string) $document['gemini_doc_name']);
            } catch (\Exception $e) {
                // Exclusion/removal must reflect the real remote state.
                return false;
            }
        }

        $wpdb->delete(self::$refsTable, ['document_id' => $docId], ['%d']);
        $wpdb->delete(self::$documentsTable, ['id' => $docId], ['%d']);
        $this->clearReferencedDocumentOverviewCache();

        return true;
    }

    /**
     * Update the Simple File List nice name for a referenced document by file hash.
     *
     * @param string $fileHash
     * @param string $niceName
     * @return bool
     */
    public function updateReferencedDocumentNiceNameByHash(string $fileHash, string $niceName): bool {
        global $wpdb;

        $niceName = trim($niceName);
        if ($fileHash === '' || $niceName === '') {
            return false;
        }

        $reference = $this->findReferenceByHash($fileHash);
        $filePath = is_array($reference) ? (string) ($reference['file_path'] ?? '') : '';

        if ($filePath === '') {
            $overview = $this->getReferencedDocumentOverview();
            foreach ($overview as $item) {
                if (!is_array($item) || ($item['file_hash'] ?? '') !== $fileHash) {
                    continue;
                }

                $filePath = (string) ($item['file_path'] ?? '');
                if (!empty($item['managed_by_simple_file_list'])) {
                    break;
                }

                $filePath = '';
                break;
            }
        }

        if ($filePath === '') {
            return false;
        }

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL\\_FileList\\_%'",
            ARRAY_A
        );

        if (!is_array($rows) || empty($rows)) {
            return false;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['option_name'], $row['option_value'])) {
                continue;
            }

            $value = maybe_unserialize($row['option_value']);
            if (!is_array($value)) {
                continue;
            }

            $updated = false;
            foreach ($value as $index => $record) {
                if (!is_array($record)) {
                    continue;
                }

                $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
                if ($filePathValue === '') {
                    continue;
                }

                $resolved = $this->resolveSimpleFileListRecordPath($filePathValue);
                if (!is_array($resolved) || (($resolved['file_path'] ?? '') !== $filePath)) {
                    continue;
                }

                $value[$index]['FileNiceName'] = $niceName;
                $updated = true;
            }

            if (!$updated) {
                continue;
            }

            $saved = update_option((string) $row['option_name'], $value, false);
            if ($saved || get_option((string) $row['option_name']) === $value) {
                $this->clearReferencedDocumentOverviewCache();
                return true;
            }
        }

        return false;
    }

    /**
     * Apply staged include/remove selections for referenced documents.
     *
     * @param array<string,bool> $targets
     * @return void
     */
    public function applyReferencedDocumentSelectionTargets(array $targets): void {
        $overview = $this->getReferencedDocumentOverview(true);
        $effectiveTargets = $this->getReferencedDocumentSelectionTargets();
        $processed = [];

        foreach ($overview as $item) {
            if (!is_array($item)) {
                continue;
            }

            $fileHash = (string) ($item['file_hash'] ?? '');
            if ($fileHash === '' || !array_key_exists($fileHash, $targets)) {
                continue;
            }

            $isUploaded = strpos((string) ($item['status'] ?? ''), 'Uploaded') === 0;
            $shouldBeIncluded = (bool) $targets[$fileHash];

            if ($shouldBeIncluded === $isUploaded) {
                $effectiveTargets[$fileHash] = $shouldBeIncluded;
                $processed[$fileHash] = true;
                continue;
            }

            if ($shouldBeIncluded) {
                $uploaded = $this->uploadReferencedDocumentByHash($fileHash);
                $effectiveTargets[$fileHash] = $uploaded;
            } else {
                $removed = $this->removeReferencedDocumentByHash($fileHash);
                // If removal fails, keep it included because it still exists remotely.
                $effectiveTargets[$fileHash] = !$removed;
            }

            $processed[$fileHash] = true;
        }

        foreach ($targets as $fileHash => $target) {
            if (!is_string($fileHash) || $fileHash === '' || isset($processed[$fileHash])) {
                continue;
            }

            $effectiveTargets[$fileHash] = (bool) $target;
        }

        $this->saveReferencedDocumentSelectionTargets($effectiveTargets);
        $this->clearReferencedDocumentOverviewCache();
    }

    /**
     * @return array<string,bool>
     */
    public function getReferencedDocumentSelectionTargets(): array {
        $stored = get_option(self::OPTION_REFERENCED_SELECTION_TARGETS, []);
        if (!is_array($stored)) {
            return [];
        }

        $targets = [];
        foreach ($stored as $fileHash => $target) {
            if (!is_string($fileHash) || $fileHash === '') {
                continue;
            }

            $targets[$fileHash] = (bool) $target;
        }

        return $targets;
    }

    /**
     * @param array<string,bool> $targets
     * @return void
     */
    public function saveReferencedDocumentSelectionTargets(array $targets): void {
        $normalized = [];
        foreach ($targets as $fileHash => $target) {
            if (!is_string($fileHash) || $fileHash === '') {
                continue;
            }

            $normalized[sanitize_text_field($fileHash)] = (bool) $target;
        }

        update_option(self::OPTION_REFERENCED_SELECTION_TARGETS, $normalized, false);
        $this->clearReferencedDocumentOverviewCache();
    }

    /**
     * Save a single include/exclude target for a referenced document.
     *
     * @param string $fileHash
     * @param bool $include
     * @return void
     */
    public function saveReferencedDocumentSelectionTarget(string $fileHash, bool $include): void {
        $targets = $this->getReferencedDocumentSelectionTargets();
        $targets[sanitize_text_field($fileHash)] = $include;
        update_option(self::OPTION_REFERENCED_SELECTION_TARGETS, $targets, false);
        $this->clearReferencedDocumentOverviewCache();
    }

    /**
     * @param array<string,mixed> $item
     * @return bool
     */
    private function getDefaultReferencedDocumentTarget(array $item): bool {
        $externalReferenceCount = isset($item['external_reference_count']) ? (int) $item['external_reference_count'] : 0;
        return $externalReferenceCount > 0;
    }

    /**
     * Associate a document with many posts without removing their other document links.
     *
     * @param int $documentId
     * @param array<int,int> $postIds
     * @return void
     */
    private function associateDocumentWithPosts(int $documentId, array $postIds): void {
        self::init();
        global $wpdb;

        foreach ($postIds as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0) {
                continue;
            }

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM " . self::$refsTable . " WHERE post_id = %d AND document_id = %d",
                    $postId,
                    $documentId
                )
            );

            if ((int) $exists > 0) {
                continue;
            }

            $wpdb->insert(
                self::$refsTable,
                ['post_id' => $postId, 'document_id' => $documentId],
                ['%d', '%d']
            );
        }
    }

    /**
     * Find a referenced file by hash across managed posts.
     *
     * @param string $fileHash
     * @return array<string,mixed>|null
     */
    private function findReferenceByHash(string $fileHash): ?array {
        foreach ($this->getReferencedDocumentOverview(true) as $item) {
            if (!is_array($item) || (($item['file_hash'] ?? '') !== $fileHash)) {
                continue;
            }

            $filePath = (string) ($item['file_path'] ?? '');
            if ($filePath === '' || !file_exists($filePath)) {
                return null;
            }

            $postIds = [];
            $posts = isset($item['posts']) && is_array($item['posts']) ? $item['posts'] : [];
            foreach ($posts as $post) {
                if (!is_array($post)) {
                    continue;
                }

                $postId = isset($post['id']) ? (int) $post['id'] : 0;
                if ($postId > 0) {
                    $postIds[] = $postId;
                }
            }

            $postIds = array_values(array_unique($postIds));

            return [
                'file_path' => $filePath,
                'post_ids' => $postIds,
                'primary_post_id' => !empty($postIds) ? (int) $postIds[0] : 0,
            ];
        }

        return null;
    }
}
