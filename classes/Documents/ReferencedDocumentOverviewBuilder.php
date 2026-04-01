<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Builds the referenced-document overview shown in admin.
 */
class ReferencedDocumentOverviewBuilder {
    private const OPTION_CONNECTION_STATUS = 'geweb_aisearch_connection_status';
    private const STATUS_DETECTED_NOT_UPLOADED = 'Detected, not uploaded';
    private const STATUS_UPLOADED = 'Uploaded';
    private const STATUS_UPLOADED_NOT_REFERENCED = 'Uploaded, not referenced';
    private const STATUS_UPLOADS_DISABLED = 'Uploads disabled';
    private const STATUS_ONLY_IN_SIMPLE_FILE_LIST = 'Only in Simple File List';
    private const STATUS_UPLOADED_ONLY_IN_SIMPLE_FILE_LIST = 'Uploaded, only in Simple File List';
    private const STATUS_REFERENCED_OUTSIDE_SIMPLE_FILE_LIST = 'Referenced outside Simple File List';
    private const STATUS_UPLOADED_REFERENCED_ELSEWHERE = 'Uploaded, referenced elsewhere';
    private const COLOR_WARNING = '#996800';
    private const COLOR_SUCCESS = '#46b450';
    private const COLOR_DANGER = '#d63638';
    private const COLOR_MUTED = '#646970';

    private DocumentStore $documentStore;
    private SimpleFileListSupport $simpleFileListSupport;

    public function __construct(DocumentStore $documentStore, ?SimpleFileListSupport $simpleFileListSupport = null) {
        $this->documentStore = $documentStore;
        $this->simpleFileListSupport = $simpleFileListSupport ?? new SimpleFileListSupport();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function build(): array {
        DocumentStore::init();

        $uploadsEnabled = get_option('geweb_aisearch_include_referenced_documents', '0') === '1';
        $postTypes = $this->getPostTypesForReferencedDocumentOverview();
        $connectionStatus = get_option(self::OPTION_CONNECTION_STATUS, []);
        $connectionState = is_array($connectionStatus) ? (string) ($connectionStatus['status'] ?? '') : '';
        $uploadedByHash = $this->indexUploadedDocumentsByHash();

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
            $this->collectPostReferencedDocuments($post, $overview, $uploadedByHash, $uploadsEnabled, $connectionState, $debug);
        }

        $this->simpleFileListSupport->mergeSimpleFileListOptionEntries(
            $overview,
            $uploadedByHash,
            $uploadsEnabled,
            $connectionState,
            [$this, 'buildOverviewEntry']
        );

        $items = $this->finalizeReferencedDocumentOverviewItems($overview, $uploadedByHash);
        usort($items, static function (array $left, array $right): int {
            return strcasecmp((string) $left['display_name'], (string) $right['display_name']);
        });

        return [
            'items' => $items,
            'debug' => $debug,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function indexUploadedDocumentsByHash(): array {
        $uploadedByHash = [];

        foreach ($this->documentStore->getAllDocuments() as $document) {
            if (!is_array($document) || empty($document['file_hash'])) {
                continue;
            }

            $uploadedByHash[(string) $document['file_hash']] = $document;
        }

        return $uploadedByHash;
    }

    /**
     * @param mixed $post
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     * @param array<string,int> $debug
     */
    private function collectPostReferencedDocuments($post, array &$overview, array $uploadedByHash, bool $uploadsEnabled, string $connectionState, array &$debug): void {
        if (!$post instanceof \WP_Post) {
            return;
        }

        $isSimpleFileListPage = $this->simpleFileListSupport->isSimpleFileListPage($post);
        $references = ReferencedAttachmentResolver::getReferencedAttachmentEntriesForPost($post->ID);
        if ($isSimpleFileListPage) {
            $references = $this->simpleFileListSupport->expandSimpleFileListReferences($references);
        }
        if (!empty($references)) {
            $debug['posts_with_document_links']++;
        }

        foreach ($references as $reference) {
            $this->collectReferencedOverviewItem(
                $reference,
                $post,
                $isSimpleFileListPage,
                $overview,
                $uploadedByHash,
                $uploadsEnabled,
                $connectionState,
                $debug
            );
        }
    }

    /**
     * @param array<string,mixed> $reference
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     * @param array<string,int> $debug
     */
    private function collectReferencedOverviewItem(array $reference, \WP_Post $post, bool $isSimpleFileListPage, array &$overview, array $uploadedByHash, bool $uploadsEnabled, string $connectionState, array &$debug): void {
        $filePath = isset($reference['file_path']) ? (string) $reference['file_path'] : '';
        $fileUrl = isset($reference['file_url']) ? (string) $reference['file_url'] : '';
        if ($filePath === '' || $fileUrl === '') {
            return;
        }

        $hash = hash_file('sha256', $filePath);
        if (!$hash) {
            return;
        }

        $debug['accepted_documents']++;
        $this->ensureOverviewItemExists($overview, $hash, $reference, $filePath, $fileUrl, $uploadedByHash, $uploadsEnabled, $connectionState);

        if ($isSimpleFileListPage) {
            $overview[$hash]['managed_by_simple_file_list'] = true;
            return;
        }

        $overview[$hash]['posts'][$post->ID] = [
            'id' => $post->ID,
            'title' => get_the_title($post->ID),
            'edit_url' => (string) get_edit_post_link($post->ID),
        ];
        $overview[$hash]['external_reference_count'] = (int) $overview[$hash]['external_reference_count'] + 1;
    }

    /**
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,mixed> $reference
     * @param array<string,array<string,mixed>> $uploadedByHash
     */
    private function ensureOverviewItemExists(array &$overview, string $hash, array $reference, string $filePath, string $fileUrl, array $uploadedByHash, bool $uploadsEnabled, string $connectionState): void {
        if (!isset($overview[$hash])) {
            $uploaded = $uploadedByHash[$hash] ?? null;
            $overview[$hash] = $this->buildOverviewEntry(
                $hash,
                $filePath,
                $fileUrl,
                $this->simpleFileListSupport->resolveOverviewDisplayName($reference, $filePath),
                $uploaded,
                $uploadsEnabled,
                $connectionState,
                false
            );
            return;
        }

        if (empty($overview[$hash]['nice_name']) || $overview[$hash]['nice_name'] === basename($filePath)) {
            $overview[$hash]['nice_name'] = $this->simpleFileListSupport->resolveOverviewDisplayName($reference, $filePath);
        }
    }

    /**
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     * @return array<int,array<string,mixed>>
     */
    private function finalizeReferencedDocumentOverviewItems(array $overview, array $uploadedByHash): array {
        $selectionTargets = (new ReferencedDocumentManager($this->documentStore))->getReferencedDocumentSelectionTargets();
        $items = array_values(array_map(function (array $item) use ($selectionTargets): array {
            return $this->finalizeReferencedDocumentOverviewItem($item, $selectionTargets);
        }, $overview));

        foreach ($uploadedByHash as $hash => $document) {
            if (!isset($overview[$hash])) {
                $items[] = $this->buildUploadedNotReferencedItem((string) $hash, $document);
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,bool> $selectionTargets
     * @return array<string,mixed>
     */
    private function finalizeReferencedDocumentOverviewItem(array $item, array $selectionTargets): array {
        $item['posts'] = array_values($item['posts']);
        $item['reference_count'] = count($item['posts']);
        if (!empty($item['managed_by_simple_file_list'])) {
            $item = $this->applySimpleFileListStatus($item);
        }

        $fileHash = (string) ($item['file_hash'] ?? '');
        $defaultTarget = ((int) ($item['external_reference_count'] ?? 0)) > 0;
        $includeTarget = array_key_exists($fileHash, $selectionTargets)
            ? (bool) $selectionTargets[$fileHash]
            : $defaultTarget;

        $item['include_in_store_target'] = $includeTarget;
        $item['default_include_in_store_target'] = $defaultTarget;

        return $item;
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private function buildUploadedNotReferencedItem(string $hash, array $document): array {
        return [
            'file_hash' => $hash,
            'display_name' => isset($document['display_name']) ? (string) $document['display_name'] : 'Unknown document',
            'nice_name' => isset($document['display_name']) ? (string) $document['display_name'] : '',
            'status' => self::STATUS_UPLOADED_NOT_REFERENCED,
            'status_color' => self::COLOR_DANGER,
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

    /**
     * @param mixed $uploaded
     * @return array<string,mixed>
     */
    private function buildOverviewEntry(string $hash, string $filePath, string $fileUrl, string $niceName, $uploaded, bool $uploadsEnabled, string $connectionState, bool $managedBySimpleFileList): array {
        $statusMeta = $this->resolveOverviewStatus($uploaded, $uploadsEnabled, $connectionState);

        return [
            'file_hash' => $hash,
            'display_name' => basename($filePath),
            'nice_name' => $niceName,
            'status' => $statusMeta['status'],
            'status_color' => $statusMeta['status_color'],
            'mime_type' => $this->documentStore->resolveMimeType($filePath),
            'last_uploaded' => $statusMeta['last_uploaded'],
            'file_url' => $fileUrl,
            'file_path' => $filePath,
            'document_id' => is_array($uploaded) && isset($uploaded['id']) ? (int) $uploaded['id'] : 0,
            'gemini_doc_name' => is_array($uploaded) && isset($uploaded['gemini_doc_name']) ? (string) $uploaded['gemini_doc_name'] : '',
            'reference_count' => 0,
            'external_reference_count' => 0,
            'managed_by_simple_file_list' => $managedBySimpleFileList,
            'posts' => [],
        ];
    }

    /**
     * @param mixed $uploaded
     * @return array{status:string,status_color:string,last_uploaded:int}
     */
    private function resolveOverviewStatus($uploaded, bool $uploadsEnabled, string $connectionState): array {
        $status = self::STATUS_DETECTED_NOT_UPLOADED;
        $statusColor = self::COLOR_WARNING;
        $lastUploaded = 0;

        if (is_array($uploaded)) {
            $status = self::STATUS_UPLOADED;
            $statusColor = self::COLOR_SUCCESS;
            $lastUploaded = isset($uploaded['last_uploaded']) ? (int) $uploaded['last_uploaded'] : 0;
        } elseif ($uploadsEnabled && $connectionState === 'failed') {
            $statusColor = self::COLOR_DANGER;
        } elseif (!$uploadsEnabled) {
            $status = self::STATUS_UPLOADS_DISABLED;
            $statusColor = self::COLOR_MUTED;
        }

        return [
            'status' => $status,
            'status_color' => $statusColor,
            'last_uploaded' => $lastUploaded,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function applySimpleFileListStatus(array $item): array {
        $externalReferenceCount = isset($item['external_reference_count']) ? (int) $item['external_reference_count'] : 0;
        $isUploaded = strpos((string) $item['status'], self::STATUS_UPLOADED) === 0;

        if ($externalReferenceCount === 0) {
            $item['status'] = $isUploaded
                ? self::STATUS_UPLOADED_ONLY_IN_SIMPLE_FILE_LIST
                : self::STATUS_ONLY_IN_SIMPLE_FILE_LIST;
            $item['status_color'] = self::COLOR_WARNING;
            return $item;
        }

        $item['status'] = $isUploaded
            ? self::STATUS_UPLOADED_REFERENCED_ELSEWHERE
            : self::STATUS_REFERENCED_OUTSIDE_SIMPLE_FILE_LIST;
        $item['status_color'] = self::COLOR_SUCCESS;

        return $item;
    }

    /**
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

    private function isUsingFallbackPostTypes(): bool {
        $postTypes = get_option('geweb_aisearch_post_types', []);
        return !is_array($postTypes) || empty($postTypes);
    }
}
