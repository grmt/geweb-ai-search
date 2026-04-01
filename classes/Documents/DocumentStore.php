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
    private const SQL_WHERE_FILE_HASH = ' WHERE file_hash = %s';
    private const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
    private const SQL_DELETE_FROM = 'DELETE FROM ';
    private const LOG_DOCUMENT_ID_SUFFIX = ' (document_id=';
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
        $documentId = null;

        if (!file_exists($filePath)) {
            error_log('geweb-ai-search: referenced document upload skipped because file does not exist: ' . $filePath);
        } else {
            $fileHash = hash_file('sha256', $filePath);
            if (!$fileHash) {
                error_log('geweb-ai-search: referenced document upload skipped because file hash could not be computed: ' . $filePath);
            } else {
                $existingId = $this->findTrackedDocumentIdByHash($fileHash);
                if ($existingId > 0) {
                    error_log('geweb-ai-search: referenced document already tracked locally: ' . basename($filePath) . self::LOG_DOCUMENT_ID_SUFFIX . (int) $existingId . ')');
                    $documentId = (int) $existingId;
                } else {
                    $documentId = $this->uploadAndTrackDocument($filePath, $postId, $fileHash);
                }
            }
        }

        return $documentId;
    }

    /**
     * @param string $fileHash
     * @return int
     */
    private function findTrackedDocumentIdByHash(string $fileHash): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::$documentsTable . self::SQL_WHERE_FILE_HASH,
            $fileHash
        ));
    }

    /**
     * @param string $filePath
     * @param int $postId
     * @param string $fileHash
     * @return int|null
     */
    private function uploadAndTrackDocument(string $filePath, int $postId, string $fileHash): ?int {
        global $wpdb;
        $documentId = null;

        $mimeType = $this->resolveMimeType($filePath);
        if ($mimeType === '') {
            error_log('geweb-ai-search: referenced document upload skipped because MIME type could not be resolved: ' . $filePath);
        } else {
            $displayName = $postId . '-' . basename($filePath);

            try {
                $gemini = ProviderFactory::make();
                $geminiDocName = $this->uploadDocumentToGemini($gemini, $filePath, $displayName, $mimeType);
                $insertedId = $this->insertTrackedDocumentRow($wpdb, $fileHash, $geminiDocName, basename($filePath));

                if ($insertedId !== null) {
                    $documentId = $insertedId;
                } else {
                    $documentId = $this->resolveDocumentInsertRace($wpdb, $gemini, $fileHash, $geminiDocName, $displayName);
                }
            } catch (\Exception $e) {
                error_log('geweb-ai-search: referenced document upload failed for ' . basename($filePath) . ': ' . $e->getMessage());
            }
        }

        return $documentId;
    }

    /**
     * @param AIProviderInterface $gemini
     * @return string
     */
    private function uploadDocumentToGemini(AIProviderInterface $gemini, string $filePath, string $displayName, string $mimeType): string {
        error_log('geweb-ai-search: uploading referenced document to Gemini: ' . $displayName . ' (' . $mimeType . ')');
        $geminiDocName = $gemini->uploadLocalFile($filePath, $displayName, $mimeType);
        error_log('geweb-ai-search: uploaded referenced document to Gemini as ' . $geminiDocName);

        return $geminiDocName;
    }

    /**
     * @param \wpdb $wpdb
     * @return int|null
     */
    private function insertTrackedDocumentRow(\wpdb $wpdb, string $fileHash, string $geminiDocName, string $displayName): ?int {
        $result = $wpdb->insert(
            self::$documentsTable,
            [
                'file_hash' => $fileHash,
                'gemini_doc_name' => $geminiDocName,
                'display_name' => $displayName,
                'last_uploaded' => time(),
            ],
            ['%s', '%s', '%s', '%d']
        );

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * @param \wpdb $wpdb
     * @return int|null
     */
    private function resolveDocumentInsertRace(\wpdb $wpdb, AIProviderInterface $gemini, string $fileHash, string $geminiDocName, string $displayName): ?int {
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, gemini_doc_name FROM " . self::$documentsTable . self::SQL_WHERE_FILE_HASH,
            $fileHash
        ), ARRAY_A);

        if (!is_array($existing) || empty($existing['id'])) {
            error_log('geweb-ai-search: failed to insert referenced document tracking row for ' . $displayName);
            return null;
        }

        $this->cleanupDuplicateGeminiUpload($gemini, $geminiDocName, $existing, $displayName);
        error_log('geweb-ai-search: reusing existing referenced document tracking row for ' . $displayName . self::LOG_DOCUMENT_ID_SUFFIX . (int) $existing['id'] . ')');

        return (int) $existing['id'];
    }

    /**
     * @param array<string,mixed> $existing
     * @return void
     */
    private function cleanupDuplicateGeminiUpload(AIProviderInterface $gemini, string $geminiDocName, array $existing, string $displayName): void {
        if (
            $geminiDocName === '' ||
            empty($existing['gemini_doc_name']) ||
            (string) $existing['gemini_doc_name'] === $geminiDocName
        ) {
            return;
        }

        try {
            $gemini->deleteDocument($geminiDocName);
            error_log('geweb-ai-search: removed duplicate Gemini upload after local file_hash race for ' . $displayName);
        } catch (\Exception $cleanupException) {
            error_log('geweb-ai-search: could not remove duplicate Gemini upload after local file_hash race for ' . $displayName . ': ' . $cleanupException->getMessage());
        }
    }

    /**
     * Resolve the MIME type for a local file.
     *
     * @param string $filePath
     * @return string
     */
    public function resolveMimeType(string $filePath): string {
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

        $result = (new ReferencedDocumentOverviewBuilder($this))->build();
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        update_option(self::OPTION_REFERENCED_CACHE, $items, false);
        update_option(self::OPTION_REFERENCED_CACHE_TIME, (string) time(), false);
        update_option(self::OPTION_REFERENCED_CACHE_DEBUG, is_array($result['debug'] ?? null) ? $result['debug'] : [], false);

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
            $wpdb->query($wpdb->prepare(self::SQL_DELETE_FROM . self::$refsTable . " WHERE post_id = %d AND document_id IN ($in)", $postId));
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
                $doc = $wpdb->get_row($wpdb->prepare(self::SQL_SELECT_ALL_FROM . self::$documentsTable . " WHERE id = %d", $docId));
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
        $results = $wpdb->get_results(self::SQL_SELECT_ALL_FROM . self::$documentsTable, ARRAY_A);
        return is_array($results) ? $results : [];
    }

}
