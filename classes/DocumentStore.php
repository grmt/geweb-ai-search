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
            return null;
        }

        $fileHash = hash_file('sha256', $filePath);
        if (!$fileHash) {
            return null;
        }

        // Check if document already exists
        $existingId = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . self::$documentsTable . " WHERE file_hash = %s", $fileHash));
        if ($existingId) {
            return (int) $existingId;
        }

        // If not, upload it
        try {
            $mimeType = $this->resolveMimeType($filePath);
            if ($mimeType === '') {
                return null;
            }

            $gemini = new Gemini();
            $displayName = $postId . '-' . basename($filePath);
            $geminiDocName = $gemini->uploadLocalFile($filePath, $displayName, $mimeType);

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

            return $result ? (int) $wpdb->insert_id : null;
        } catch (\Exception $e) {
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
        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (!is_array($postTypes) || empty($postTypes)) {
            return [];
        }

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
        ];

        $overview = [];
        $hasSimpleFileList = false;
        foreach ($posts as $post) {
            if (!$post instanceof \WP_Post) {
                continue;
            }

            $isSimpleFileListPage = $this->isSimpleFileListPage($post);
            if ($isSimpleFileListPage) {
                $hasSimpleFileList = true;
            }

            $references = HTML2MD::getReferencedAttachmentEntriesForPost($post->ID);
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
                    } elseif (!$uploadsEnabled) {
                        $status = 'Uploads disabled';
                        $color = '#646970';
                    }

                    $overview[$hash] = [
                        'display_name' => basename($filePath),
                        'status' => $status,
                        'status_color' => $color,
                        'mime_type' => $this->resolveMimeType($filePath),
                        'last_uploaded' => $lastUploaded,
                        'file_url' => $fileUrl,
                        'reference_count' => 0,
                        'external_reference_count' => 0,
                        'managed_by_simple_file_list' => false,
                        'posts' => [],
                    ];
                }

                $overview[$hash]['posts'][$post->ID] = [
                    'id' => $post->ID,
                    'title' => get_the_title($post->ID),
                    'edit_url' => (string) get_edit_post_link($post->ID),
                ];
                if ($isSimpleFileListPage) {
                    $overview[$hash]['managed_by_simple_file_list'] = true;
                } else {
                    $overview[$hash]['external_reference_count'] = (int) $overview[$hash]['external_reference_count'] + 1;
                }
            }
        }

        $items = array_values(array_map(static function (array $item): array {
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
            return $item;
        }, $overview));

        foreach ($uploadedByHash as $hash => $document) {
            if (isset($overview[$hash])) {
                continue;
            }

            $items[] = [
                'display_name' => isset($document['display_name']) ? (string) $document['display_name'] : 'Unknown document',
                'status' => 'Uploaded, not referenced',
                'status_color' => '#d63638',
                'mime_type' => '',
                'last_uploaded' => isset($document['last_uploaded']) ? (int) $document['last_uploaded'] : 0,
                'file_url' => '',
                'reference_count' => 0,
                'external_reference_count' => 0,
                'managed_by_simple_file_list' => false,
                'posts' => [],
            ];
        }

        if ($hasSimpleFileList) {
            $items = array_values(array_filter($items, static function (array $item): bool {
                return !empty($item['managed_by_simple_file_list']) || (isset($item['status']) && (string) $item['status'] === 'Uploaded, not referenced');
            }));
        }

        usort($items, static function (array $left, array $right): int {
            return strcasecmp((string) $left['display_name'], (string) $right['display_name']);
        });

        update_option(self::OPTION_REFERENCED_CACHE_DEBUG, $debug, false);

        return $items;
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
        $gemini = new Gemini();

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
}
