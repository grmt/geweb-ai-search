<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Manages the custom database tables for documents and their references.
 */
class DocumentStore {
    use DocumentStoreCacheTrait;

    private const OPTION_REFERENCED_CACHE = 'geweb_aisearch_referenced_documents_cache';
    private const OPTION_REFERENCED_CACHE_TIME = 'geweb_aisearch_referenced_documents_cache_time';
    private const OPTION_REFERENCED_CACHE_DEBUG = 'geweb_aisearch_referenced_documents_cache_debug';
    private const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
    private const SQL_DELETE_FROM = 'DELETE FROM ';
    private const LOG_DOCUMENT_ID_SUFFIX = ' (document_id=';
    private static string $documentsTable;
    private static string $refsTable;
    private static bool $schemaEnsured = false;
    private string $ownerKey;

    public function __construct() {
        $this->ownerKey = UserScope::getCurrentGroupScopeKey();
    }

    /**
     * Initialize table names.
     */
    public static function init(): void {
        global $wpdb;
        self::$documentsTable = $wpdb->prefix . 'geweb_ai_documents';
        self::$refsTable = $wpdb->prefix . 'geweb_ai_post_document_refs';
        self::ensureSchema();
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
            owner_key VARCHAR(191) NOT NULL,
            file_hash VARCHAR(64) NOT NULL,
            gemini_doc_name VARCHAR(255) NOT NULL,
            display_name VARCHAR(255) NOT NULL,
            last_uploaded BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY owner_file_hash (owner_key, file_hash),
            UNIQUE KEY owner_gemini_doc_name (owner_key, gemini_doc_name),
            KEY owner_key (owner_key)
        ) $charset_collate;";

        $sql_refs = "CREATE TABLE " . self::$refsTable . " (
            owner_key VARCHAR(191) NOT NULL,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            document_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (owner_key, post_id, document_id),
            KEY owner_document_id (owner_key, document_id),
            KEY document_id (document_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_docs);
        dbDelta($sql_refs);
    }

    private static function ensureSchema(): void {
        if (self::$schemaEnsured) {
            return;
        }

        self::$schemaEnsured = true;
        global $wpdb;

        $docsHasOwnerKey = $wpdb->get_var("SHOW COLUMNS FROM " . self::$documentsTable . " LIKE 'owner_key'");
        $refsHasOwnerKey = $wpdb->get_var("SHOW COLUMNS FROM " . self::$refsTable . " LIKE 'owner_key'");

        if ($docsHasOwnerKey && $refsHasOwnerKey) {
            return;
        }

        self::install();
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
            "SELECT id FROM " . self::$documentsTable . " WHERE owner_key = %s AND file_hash = %s",
            $this->ownerKey,
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
        $documentMarkdownCacheStore = new ReferencedDocumentMarkdownCacheStore();

        $mimeType = $this->resolveMimeType($filePath);
        if ($mimeType === '') {
            error_log('geweb-ai-search: referenced document upload skipped because MIME type could not be resolved: ' . $filePath);
        } else {
            $displayName = $postId . '-' . basename($filePath);

            try {
                $gemini = ProviderFactory::make();
                $geminiDocName = (new ReferencedDocumentGeminiUploadService())->upload($gemini, $filePath, $displayName, $mimeType, $fileHash);
                $insertedId = $this->insertTrackedDocumentRow($wpdb, $fileHash, $geminiDocName, basename($filePath));

                if ($insertedId !== null) {
                    $documentId = $insertedId;
                } else {
                    $documentId = $this->resolveDocumentInsertRace($wpdb, $gemini, $fileHash, $geminiDocName, $displayName);
                }
            } catch (\Exception $e) {
                $documentMarkdownCacheStore->deleteMarkdown($fileHash);
                error_log('geweb-ai-search: referenced document upload failed for ' . basename($filePath) . ': ' . $e->getMessage());
            }
        }

        return $documentId;
    }

    public function refreshReferencedImageMarkdownCache(string $fileHash, string $filePath, string $mimeType, string $mode): void {
        $fileHash = sanitize_text_field($fileHash);
        $filePath = (string) $filePath;
        $mimeType = strtolower(trim((string) $mimeType));
        if ($fileHash === '' || $filePath === '' || $mimeType === '') {
            throw new ReferencedDocumentException('Missing document data for markdown cache refresh.');
        }

        if (
            strpos($mimeType, 'image/') !== 0
            && $mimeType !== 'application/pdf'
        ) {
            throw new ReferencedDocumentException('Markdown cache refresh is only supported for images and PDFs.');
        }

        if (!in_array($mode, [ImageOcrService::MODE_OCR, ImageOcrService::MODE_DESCRIBE, ImageOcrService::MODE_DOCUMENT_AI_OCR], true)) {
            throw new ReferencedDocumentException('Document processing is disabled for this file.');
        }

        (new ReferencedDocumentGeminiUploadService())->refreshImageMarkdownCache($fileHash, $filePath, $mimeType, $mode);
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
                'owner_key' => $this->ownerKey,
                'last_uploaded' => time(),
            ],
            ['%s', '%s', '%s', '%s', '%d']
        );

        return $result ? (int) $wpdb->insert_id : null;
    }

    /**
     * @param \wpdb $wpdb
     * @return int|null
     */
    private function resolveDocumentInsertRace(\wpdb $wpdb, AIProviderInterface $gemini, string $fileHash, string $geminiDocName, string $displayName): ?int {
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, gemini_doc_name FROM " . self::$documentsTable . " WHERE owner_key = %s AND file_hash = %s",
            $this->ownerKey,
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
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        $canonicalMimeTypes = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'doc' => 'application/msword',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'ppt' => 'application/vnd.ms-powerpoint',
        ];
        $resolvedMimeType = '';

        if (isset($canonicalMimeTypes[$extension])) {
            $resolvedMimeType = $canonicalMimeTypes[$extension];
        } else {
            $fileInfo = wp_check_filetype(basename($filePath));
            $resolvedMimeType = isset($fileInfo['type']) ? trim((string) $fileInfo['type']) : '';
        }

        if ($resolvedMimeType === '' && function_exists('mime_content_type')) {
            $detected = mime_content_type($filePath);
            if (is_string($detected) && trim($detected) !== '') {
                $resolvedMimeType = trim($detected);
            }
        }

        return $resolvedMimeType;
    }

    /**
     * Build a live overview of referenced local documents across managed posts.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getReferencedDocumentOverview(bool $forceRefresh = false): array {
        if (!$forceRefresh) {
            $cached = UserScope::getGroupScopedOption(self::OPTION_REFERENCED_CACHE, null);
            if (is_array($cached) && $this->isReferencedDocumentOverviewCacheFresh()) {
                return $cached;
            }
        }

        $result = (new ReferencedDocumentOverviewBuilder($this))->build();
        $items = is_array($result['items'] ?? null) ? $result['items'] : [];
        UserScope::updateGroupScopedOption(self::OPTION_REFERENCED_CACHE, $items, false);
        UserScope::updateGroupScopedOption(self::OPTION_REFERENCED_CACHE_TIME, (string) time(), false);
        UserScope::updateGroupScopedOption(self::OPTION_REFERENCED_CACHE_DEBUG, is_array($result['debug'] ?? null) ? $result['debug'] : [], false);

        return $items;
    }

    /**
     * @return void
     */
    public function clearReferencedDocumentOverviewCache(): void {
        UserScope::deleteGroupScopedOption(self::OPTION_REFERENCED_CACHE);
        UserScope::deleteGroupScopedOption(self::OPTION_REFERENCED_CACHE_TIME);
        UserScope::deleteGroupScopedOption(self::OPTION_REFERENCED_CACHE_DEBUG);
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

        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));

        // First, find out which documents are currently associated
        $currentDocIds = $wpdb->get_col($wpdb->prepare("SELECT document_id FROM " . self::$refsTable . " WHERE owner_key = %s AND post_id = %d", $this->ownerKey, $postId));
        $currentDocIds = array_values(array_unique(array_map('intval', is_array($currentDocIds) ? $currentDocIds : [])));

        // Find which associations to remove
        $toRemove = array_diff($currentDocIds, $documentIds);
        if (!empty($toRemove)) {
            $placeholders = implode(', ', array_fill(0, count($toRemove), '%d'));
            $sql = self::SQL_DELETE_FROM . self::$refsTable . " WHERE owner_key = %s AND post_id = %d AND document_id IN ({$placeholders})";
            $args = array_merge([$this->ownerKey, $postId], array_map('intval', array_values($toRemove)));
            $wpdb->query($wpdb->prepare($sql, $args));
            $this->cleanupOrphanedDocuments($toRemove);
        }

        // Find which associations to add
        $toAdd = array_diff($documentIds, $currentDocIds);
        foreach ($toAdd as $docId) {
            $wpdb->insert(
                self::$refsTable,
                ['owner_key' => $this->ownerKey, 'post_id' => $postId, 'document_id' => (int) $docId],
                ['%s', '%d', '%d']
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
            $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . self::$refsTable . " WHERE owner_key = %s AND document_id = %d", $this->ownerKey, $docId));
            if ((int) $count === 0) {
                // This document is an orphan, delete it from Gemini and our DB
                $doc = $wpdb->get_row($wpdb->prepare(self::SQL_SELECT_ALL_FROM . self::$documentsTable . " WHERE owner_key = %s AND id = %d", $this->ownerKey, $docId));
                if ($doc) {
                    try {
                        $gemini->deleteDocument($doc->gemini_doc_name);
                    } catch (\Exception $e) {
                        // Ignore Gemini errors, we still want to remove it from our DB
                    }
                    $wpdb->delete(self::$documentsTable, ['owner_key' => $this->ownerKey, 'id' => $docId], ['%s', '%d']);
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
        $results = $wpdb->get_results(
            $wpdb->prepare(self::SQL_SELECT_ALL_FROM . self::$documentsTable . " WHERE owner_key = %s", $this->ownerKey),
            ARRAY_A
        );
        return is_array($results) ? $results : [];
    }

    public function getOwnerKey(): string {
        return $this->ownerKey;
    }

}
