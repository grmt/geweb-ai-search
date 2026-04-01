<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class ReferencedDocumentManager {
    private const OPTION_REFERENCED_SELECTION_TARGETS = 'geweb_aisearch_referenced_document_selection_targets';
    private const SQL_SELECT_ALL_FROM = 'SELECT * FROM ';
    private const SQL_DELETE_FROM = 'DELETE FROM ';
    private const SQL_WHERE_FILE_HASH = ' WHERE file_hash = %s';

    private DocumentStore $documentStore;
    private string $documentsTable;
    private string $refsTable;

    public function __construct(?DocumentStore $documentStore = null) {
        global $wpdb;

        $this->documentStore = $documentStore ?? new DocumentStore();
        $this->documentsTable = $wpdb->prefix . 'geweb_ai_documents';
        $this->refsTable = $wpdb->prefix . 'geweb_ai_post_document_refs';
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

            $wpdb->delete($this->refsTable, ['document_id' => $docId], ['%d']);
            $deleted = $wpdb->delete($this->documentsTable, ['id' => $docId], ['%d']);
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

        $wpdb->query(self::SQL_DELETE_FROM . $this->refsTable);
        $wpdb->query(self::SQL_DELETE_FROM . $this->documentsTable);
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
            $wpdb->prepare(self::SQL_SELECT_ALL_FROM . $this->documentsTable . self::SQL_WHERE_FILE_HASH, $fileHash),
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

        $wpdb->delete($this->refsTable, ['document_id' => $docId], ['%d']);
        $wpdb->delete($this->documentsTable, ['id' => $docId], ['%d']);
        $this->documentStore->clearReferencedDocumentOverviewCache();

        return true;
    }

    public function updateReferencedDocumentNiceNameByHash(string $fileHash, string $niceName): bool {
        global $wpdb;
        $niceNameUpdater = new SimpleFileListNiceNameUpdater();
        $updatedNiceName = false;

        $niceName = trim($niceName);
        if ($fileHash !== '' && $niceName !== '') {
            $filePath = $this->resolveNiceNameFilePath($fileHash);
            $rows = $filePath !== ''
                ? $wpdb->get_results(
                    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL\\_FileList\\_%'",
                    ARRAY_A
                )
                : [];

            if (is_array($rows) && !empty($rows)) {
                foreach ($rows as $row) {
                    $updatedOption = $niceNameUpdater->buildUpdatedOption($row, $filePath, $niceName);
                    if ($updatedOption === null) {
                        continue;
                    }

                    $saved = update_option((string) $row['option_name'], $updatedOption, false);
                    if ($saved || get_option((string) $row['option_name']) === $updatedOption) {
                        $this->documentStore->clearReferencedDocumentOverviewCache();
                        $updatedNiceName = true;
                        break;
                    }
                }
            }
        }

        return $updatedNiceName;
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
        $this->documentStore->clearReferencedDocumentOverviewCache();
    }

    public function saveReferencedDocumentSelectionTarget(string $fileHash, bool $include): void {
        $targets = $this->getReferencedDocumentSelectionTargets();
        $targets[sanitize_text_field($fileHash)] = $include;
        update_option(self::OPTION_REFERENCED_SELECTION_TARGETS, $targets, false);
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
                    "SELECT COUNT(*) FROM " . $this->refsTable . " WHERE post_id = %d AND document_id = %d",
                    $postId,
                    $documentId
                )
            );

            if ((int) $exists > 0) {
                continue;
            }

            $wpdb->insert(
                $this->refsTable,
                ['post_id' => $postId, 'document_id' => $documentId],
                ['%d', '%d']
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
