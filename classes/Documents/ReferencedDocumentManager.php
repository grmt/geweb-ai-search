<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class ReferencedDocumentManager {
    use ReferencedDocumentManagerOptionsTrait;

    private const OPTION_REFERENCED_SELECTION_TARGETS = 'geweb_aisearch_referenced_document_selection_targets';
    private const OPTION_REFERENCED_IMAGE_PROCESSING_MODES = 'geweb_aisearch_referenced_document_image_processing_modes';
    private const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';

    private DocumentStore $documentStore;
    private string $documentsTable;
    private string $refsTable;
    private string $ownerKey;

    public function __construct(?DocumentStore $documentStore = null) {
        global $wpdb;

        $this->documentStore = $documentStore ?? new DocumentStore();
        $this->documentsTable = $wpdb->prefix . 'geweb_ai_documents';
        $this->refsTable = $wpdb->prefix . 'geweb_ai_post_document_refs';
        $this->ownerKey = $this->documentStore->getOwnerKey();
    }

    /**
     * @param array<int,string> $remoteDocumentNames
     * @return int
     */
    public function reconcileTrackedDocumentsWithRemote(array $remoteDocumentNames): int {
        global $wpdb;
        $remoteLookup = $this->buildRemoteLookup($remoteDocumentNames);

        $removedCount = 0;
        foreach ($this->documentStore->getAllDocuments() as $document) {
            $docId = $this->resolveRemovableTrackedDocumentId($document, $remoteLookup);
            if ($docId === 0) {
                continue;
            }

            $wpdb->delete($this->refsTable, ['owner_key' => $this->ownerKey, 'document_id' => $docId], ['%s', '%d']);
            $deleted = $wpdb->delete($this->documentsTable, ['owner_key' => $this->ownerKey, 'id' => $docId], ['%s', '%d']);
            if ($deleted) {
                $removedCount++;
            }
        }

        if ($removedCount > 0) {
            $this->documentStore->clearReferencedDocumentOverviewCache();
        }

        return $removedCount;
    }

    /**
     * @param array<int,string> $remoteDocumentNames
     * @return int
     */
    public function reconcileSelectionTargetsWithRemote(array $remoteDocumentNames): int {
        $remoteLookup = $this->buildRemoteLookup($remoteDocumentNames);

        if (empty($remoteLookup)) {
            return 0;
        }

        $targets = $this->getReferencedDocumentSelectionTargets();
        $corrected = 0;

        foreach ($this->documentStore->getAllDocuments() as $document) {
            $fileHash = $this->resolveExcludedTrackedFileHash($document, $remoteLookup, $targets);
            if ($fileHash === '') {
                continue;
            }

            $removed = $this->removeReferencedDocumentByHash($fileHash);
            if (!$removed) {
                $targets[$fileHash] = true;
                $corrected++;
            }
        }

        if ($corrected > 0) {
            $this->saveReferencedDocumentSelectionTargets($targets);
        }

        return $corrected;
    }

    public function clearAllTrackedDocuments(): void {
        global $wpdb;

        $wpdb->delete($this->refsTable, ['owner_key' => $this->ownerKey], ['%s']);
        $wpdb->delete($this->documentsTable, ['owner_key' => $this->ownerKey], ['%s']);
        UserScope::deleteGroupScopedOption(self::OPTION_REFERENCED_SELECTION_TARGETS);
        UserScope::deleteGroupScopedOption(self::OPTION_REFERENCED_IMAGE_PROCESSING_MODES);
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }

    public function uploadReferencedDocumentByHash(string $fileHash): bool {
        $reference = $this->findReferenceByHash($fileHash);
        if (!$reference || empty($reference['file_path'])) {
            error_log('geweb-ai-search: uploadReferencedDocumentByHash could not resolve file hash ' . $fileHash);
            return false;
        }

        error_log('geweb-ai-search: uploadReferencedDocumentByHash starting for ' . (string) $reference['file_path']);
        $documentId = $this->documentStore->getOrCreateDocument((string) $reference['file_path'], (int) $reference['primary_post_id']);
        if (!$documentId) {
            error_log('geweb-ai-search: uploadReferencedDocumentByHash failed for ' . (string) $reference['file_path']);
            return false;
        }

        if (!empty($reference['post_ids']) && is_array($reference['post_ids'])) {
            $this->associateDocumentWithPosts($documentId, $reference['post_ids']);
        }

        $this->documentStore->clearReferencedDocumentOverviewCache();
        error_log('geweb-ai-search: uploadReferencedDocumentByHash succeeded for ' . (string) $reference['file_path'] . ' (document_id=' . $documentId . ')');

        return true;
    }

    public function removeReferencedDocumentByHash(string $fileHash): bool {
        global $wpdb;

        $document = $wpdb->get_row(
            $wpdb->prepare(self::SQL_SELECT_ALL_FROM . $this->documentsTable . " WHERE owner_key = %s AND file_hash = %s", $this->ownerKey, $fileHash),
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
                return false;
            }
        }

        $wpdb->delete($this->refsTable, ['owner_key' => $this->ownerKey, 'document_id' => $docId], ['%s', '%d']);
        $wpdb->delete($this->documentsTable, ['owner_key' => $this->ownerKey, 'id' => $docId], ['%s', '%d']);
        $this->documentStore->clearReferencedDocumentOverviewCache();

        return true;
    }

    public function updateReferencedDocumentNiceNameByHash(string $fileHash, string $niceName): bool {
        global $wpdb;
        $niceName = trim($niceName);
        if ($fileHash === '' || $niceName === '') {
            return false;
        }

        $filePath = $this->resolveNiceNameFilePath($fileHash);
        $rows = $filePath !== ''
            ? $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL\\_FileList\\_%'",
                ARRAY_A
            )
            : [];

        if (!is_array($rows) || empty($rows)) {
            return false;
        }

        return $this->applyNiceNameToRows($rows, $filePath, $niceName);
    }

    private function applyNiceNameToRows(array $rows, string $filePath, string $niceName): bool {
        $niceNameUpdater = new SimpleFileListNiceNameUpdater();
        foreach ($rows as $row) {
            $updatedOption = $niceNameUpdater->buildUpdatedOption($row, $filePath, $niceName);
            if ($updatedOption === null) {
                continue;
            }

            $saved = update_option((string) $row['option_name'], $updatedOption, false);
            if ($saved || get_option((string) $row['option_name']) === $updatedOption) {
                $this->documentStore->clearReferencedDocumentOverviewCache();
                return true;
            }
        }
        return false;
    }

    public function removeReferencedDocumentFromFileListByHash(string $fileHash): bool {
        $reference = $this->findReferenceByHash($fileHash);
        if (is_array($reference) && !empty($reference['post_ids'])) {
            return false;
        }

        $filePath = $this->resolveNiceNameFilePath($fileHash);
        if ($filePath === '') {
            return false;
        }

        $support = new SimpleFileListSupport();
        $removed = $support->removeSimpleFileListEntryByPath($filePath);
        if ($removed) {
            $this->documentStore->clearReferencedDocumentOverviewCache();
        }

        return $removed;
    }

    /**
     * @param array<string,bool> $targets
     * @return void
     */
    public function applyReferencedDocumentSelectionTargets(array $targets): void {
        $overview = $this->documentStore->getReferencedDocumentOverview(true);
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
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }

    public function refreshReferencedDocumentImageMarkdownCache(string $fileHash): void {
        $fileHash = sanitize_text_field($fileHash);
        if ($fileHash === '') {
            throw new ReferencedDocumentException('Missing file hash.');
        }

        $reference = $this->findReferenceByHash($fileHash);
        if (!is_array($reference) || empty($reference['file_path'])) {
            throw new ReferencedDocumentException('Could not resolve the referenced document.');
        }

        $mode = $this->getReferencedDocumentImageProcessingMode($fileHash);
        if (!in_array($mode, [ImageOcrService::MODE_OCR, ImageOcrService::MODE_DESCRIBE, ImageOcrService::MODE_DOCUMENT_AI_OCR], true)) {
            throw new ReferencedDocumentException('Document processing is disabled for this file.');
        }

        $this->documentStore->refreshReferencedImageMarkdownCache(
            $fileHash,
            (string) $reference['file_path'],
            (string) ($reference['mime_type'] ?? ''),
            $mode
        );
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }

    /**
     * @param array<int,int> $postIds
     * @return void
     */
    private function associateDocumentWithPosts(int $documentId, array $postIds): void {
        global $wpdb;

        foreach ($postIds as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0) {
                continue;
            }

            $exists = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM " . $this->refsTable . " WHERE owner_key = %s AND post_id = %d AND document_id = %d",
                    $this->ownerKey,
                    $postId,
                    $documentId
                )
            );

            if ((int) $exists > 0) {
                continue;
            }

            $wpdb->insert(
                $this->refsTable,
                ['owner_key' => $this->ownerKey, 'post_id' => $postId, 'document_id' => $documentId],
                ['%s', '%d', '%d']
            );
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findReferenceByHash(string $fileHash): ?array {
        foreach ($this->documentStore->getReferencedDocumentOverview(true) as $item) {
            if (!is_array($item) || (($item['file_hash'] ?? '') !== $fileHash)) {
                continue;
            }

            $filePath = $this->resolveExistingOverviewFilePath($item);
            if ($filePath === '') {
                return null;
            }

            $postIds = $this->extractOverviewPostIds($item);

            return [
                'file_path' => $filePath,
                'post_ids' => $postIds,
                'primary_post_id' => !empty($postIds) ? (int) $postIds[0] : 0,
                'display_name' => (string) ($item['display_name'] ?? basename($filePath)),
                'mime_type' => (string) ($item['mime_type'] ?? ''),
            ];
        }

        return null;
    }

    /**
     * @param array<int,string> $remoteDocumentNames
     * @return array<string,bool>
     */
    private function buildRemoteLookup(array $remoteDocumentNames): array {
        $remoteLookup = [];
        foreach ($remoteDocumentNames as $name) {
            if (!is_string($name) || trim($name) === '') {
                continue;
            }
            $remoteLookup[trim($name)] = true;
        }

        return $remoteLookup;
    }

    /**
     * @param mixed $document
     * @param array<string,bool> $remoteLookup
     * @return int
     */
    private function resolveRemovableTrackedDocumentId($document, array $remoteLookup): int {
        if (!is_array($document) || empty($document['id'])) {
            return 0;
        }

        $documentName = isset($document['gemini_doc_name']) ? trim((string) $document['gemini_doc_name']) : '';
        if ($documentName !== '' && isset($remoteLookup[$documentName])) {
            return 0;
        }

        $docId = (int) $document['id'];
        return $docId > 0 ? $docId : 0;
    }

    /**
     * @param mixed $document
     * @param array<string,bool> $remoteLookup
     * @param array<string,bool> $targets
     * @return string
     */
    private function resolveExcludedTrackedFileHash($document, array $remoteLookup, array $targets): string {
        if (!is_array($document)) {
            return '';
        }

        $documentName = isset($document['gemini_doc_name']) ? trim((string) $document['gemini_doc_name']) : '';
        $fileHash = isset($document['file_hash']) ? trim((string) $document['file_hash']) : '';
        if ($documentName === '' || $fileHash === '' || !isset($remoteLookup[$documentName])) {
            return '';
        }

        return (isset($targets[$fileHash]) && $targets[$fileHash] === false) ? $fileHash : '';
    }

    private function resolveNiceNameFilePath(string $fileHash): string {
        $reference = $this->findReferenceByHash($fileHash);
        $filePath = is_array($reference) ? (string) ($reference['file_path'] ?? '') : '';
        if ($filePath !== '') {
            return $filePath;
        }

        foreach ($this->documentStore->getReferencedDocumentOverview() as $item) {
            if (!is_array($item) || ($item['file_hash'] ?? '') !== $fileHash) {
                continue;
            }

            return !empty($item['managed_by_simple_file_list']) ? (string) ($item['file_path'] ?? '') : '';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $item
     * @return string
     */
    private function resolveExistingOverviewFilePath(array $item): string {
        $filePath = (string) ($item['file_path'] ?? '');
        return ($filePath !== '' && file_exists($filePath)) ? $filePath : '';
    }

    /**
     * @param array<string,mixed> $item
     * @return array<int,int>
     */
    private function extractOverviewPostIds(array $item): array {
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

        return array_values(array_unique($postIds));
    }
}
