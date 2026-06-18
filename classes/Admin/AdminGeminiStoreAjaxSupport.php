<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminGeminiStoreAjaxSupport {
    public const MESSAGE_GEMINI_PROVIDER_INACTIVE = 'Gemini provider is not active';
    public const MESSAGE_STORE_NAME_MISSING = 'Missing store name';
    public const PRELOAD_STEP_STORE_DOCUMENTS = 'store_documents';

    public static function requireGeminiProviderForStoreDocuments(string $preloadJobId): Gemini {
        $provider = ProviderFactory::make();
        if ($provider instanceof Gemini) {
            return $provider;
        }

        if ($preloadJobId !== '') {
            AdminPreloadProgress::markFailed($preloadJobId, self::PRELOAD_STEP_STORE_DOCUMENTS, self::MESSAGE_GEMINI_PROVIDER_INACTIVE);
        }
        wp_send_json_error(['message' => 'Gemini store overview is only available for the Gemini provider.'], 400);
    }

    public static function requireStoreNameFromRequest(string $preloadJobId): string {
        $storeName = isset($_POST['store_name']) ? sanitize_text_field(wp_unslash($_POST['store_name'])) : '';
        if ($storeName !== '') {
            return $storeName;
        }

        if ($preloadJobId !== '') {
            AdminPreloadProgress::markFailed($preloadJobId, self::PRELOAD_STEP_STORE_DOCUMENTS, self::MESSAGE_STORE_NAME_MISSING);
        }
        wp_send_json_error(['message' => 'Missing store name.'], 400);
    }

    /**
     * @return array<int,mixed>
     */
    public static function getStoreDocumentsOrSendError(Gemini $provider, string $storeName, bool $forceRefresh, string $preloadJobId): array {
        try {
            return $provider->getStoreDocuments($storeName, $forceRefresh);
        } catch (\Exception $e) {
            if ($preloadJobId !== '') {
                AdminPreloadProgress::markFailed($preloadJobId, self::PRELOAD_STEP_STORE_DOCUMENTS, $e->getMessage());
            }
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @param array<int,mixed> $documents
     */
    public static function renderDocumentListOrSendError(array $documents, string $storeName, string $preloadJobId): string {
        try {
            return GeminiStoreListTable::renderDocumentList($documents);
        } catch (\Throwable $e) {
            error_log('geweb-ai-search: ajaxRefreshGeminiStoreDocuments render failed for ' . $storeName . ': ' . $e->getMessage());
            if ($preloadJobId !== '') {
                AdminPreloadProgress::markFailed($preloadJobId, self::PRELOAD_STEP_STORE_DOCUMENTS, 'Could not render uploaded items list');
            }
            wp_send_json_error(['message' => 'Could not render the uploaded items list. Check the WordPress error log for details.'], 500);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $storeItems
     */
    public static function syncLocalIndexedStatusWithActiveStore(array $storeItems): void {
        $activeStoreDocuments = self::getActiveStoreDocumentNames($storeItems);
        if ($activeStoreDocuments === null) {
            return;
        }

        PostIndexManager::reconcileIndexedPostsWithRemoteDocuments($activeStoreDocuments);

        $documentStore = new DocumentStore();
        $documentManager = new ReferencedDocumentManager($documentStore);
        $documentManager->reconcileSelectionTargetsWithRemote($activeStoreDocuments);
        $documentManager->reconcileTrackedDocumentsWithRemote($activeStoreDocuments);
    }

    public static function clearLocalIndexTracking(): void {
        $documentManager = new ReferencedDocumentManager();
        $documentManager->clearAllTrackedDocuments();
        PostIndexManager::clearAllIndexedState();
    }

    /**
     * @param array<int,array<string,mixed>> $storeItems
     * @return array<int,string>|null
     */
    private static function getActiveStoreDocumentNames(array $storeItems): ?array {
        foreach ($storeItems as $storeItem) {
            if (!is_array($storeItem) || empty($storeItem['is_active'])) {
                continue;
            }

            if (!isset($storeItem['documents']) || !is_array($storeItem['documents'])) {
                return [];
            }

            return self::extractDocumentNames($storeItem['documents']);
        }

        return null;
    }

    /**
     * @param array<int,mixed> $documents
     * @return array<int,string>
     */
    private static function extractDocumentNames(array $documents): array {
        $documentNames = [];
        foreach ($documents as $document) {
            if (is_array($document) && !empty($document['name'])) {
                $documentNames[] = (string) $document['name'];
            }
        }

        return $documentNames;
    }
}
