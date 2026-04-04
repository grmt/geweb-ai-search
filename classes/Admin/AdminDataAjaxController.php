<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminDataAjaxController {
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';

    private AdminPageSections $adminPageSections;

    public function __construct(AdminPageSections $adminPageSections) {
        $this->adminPageSections = $adminPageSections;
    }

    public function ajaxRefreshReferencedDocuments(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $documentStore = new DocumentStore();
        $items = $documentStore->getReferencedDocumentOverview(true);

        ob_start();
        $this->adminPageSections->renderReferencedDocumentsTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'group_revision' => GroupDataRevision::ensureCurrentRevision(),
        ]);
    }

    public function ajaxUpdateReferencedDocument(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        try {
            GroupDataRevision::assertExpectedRevision(GroupDataRevision::extractExpectedRevisionFromRequest());
        } catch (OptimisticLockException $e) {
            $this->sendConflictResponse($e);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        $actionName = isset($_POST['document_action']) ? sanitize_key(wp_unslash($_POST['document_action'])) : '';

        if ($fileHash === '' || !in_array($actionName, ['upload', 'remove'], true)) {
            wp_send_json_error(['message' => 'Invalid document action.'], 400);
        }

        $documentStore = new DocumentStore();
        $documentManager = new ReferencedDocumentManager($documentStore);
        $shouldInclude = $actionName === 'upload';
        $success = $shouldInclude
            ? $documentManager->uploadReferencedDocumentByHash($fileHash)
            : $documentManager->removeReferencedDocumentByHash($fileHash);

        if ($success) {
            $documentManager->saveReferencedDocumentSelectionTarget($fileHash, $shouldInclude);
        }

        if (!$success) {
            wp_send_json_error(['message' => 'The document action could not be completed.'], 500);
        }

        $items = $documentStore->getReferencedDocumentOverview(true);
        $updatedItems = array_values(array_filter($items, static function ($item) use ($fileHash): bool {
            return is_array($item) && (($item['file_hash'] ?? '') === $fileHash);
        }));
        $updatedItem = $updatedItems[0] ?? null;

        $table = new ReferencedDocumentListTable();

        ob_start();
        $this->adminPageSections->renderReferencedDocumentsTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'message' => $actionName === 'upload' ? 'Document uploaded.' : 'Document removed from store.',
            'row_exists' => is_array($updatedItem),
            'status_html' => is_array($updatedItem) ? $table->renderStatusCell($updatedItem) : '',
            'actions_html' => is_array($updatedItem) ? $table->renderActionsCell($updatedItem) : '',
            'group_revision' => GroupDataRevision::touch(),
        ]);
    }

    public function ajaxToggleReferencedDocumentExclude(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        try {
            GroupDataRevision::assertExpectedRevision(GroupDataRevision::extractExpectedRevisionFromRequest());
        } catch (OptimisticLockException $e) {
            $this->sendConflictResponse($e);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        $exclude = !empty($_POST['exclude']);

        if ($fileHash === '') {
            wp_send_json_error(['message' => 'Missing file hash.'], 400);
        }

        $documentStore = new DocumentStore();
        $documentManager = new ReferencedDocumentManager($documentStore);
        if ($exclude) {
            $removed = $documentManager->removeReferencedDocumentByHash($fileHash);
            if (!$removed) {
                wp_send_json_error(['message' => 'Could not remove this source from the Gemini store. It is still included.'], 500);
            }
            $documentManager->saveReferencedDocumentSelectionTarget($fileHash, false);
        } else {
            $documentManager->saveReferencedDocumentSelectionTarget($fileHash, true);
        }

        $items = $documentStore->getReferencedDocumentOverview(true);
        $updatedItem = null;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['file_hash'] ?? '') === $fileHash) {
                $updatedItem = $item;
                break;
            }
        }

        $table = new ReferencedDocumentListTable();

        wp_send_json_success([
            'message' => $exclude ? 'Excluded from indexing.' : 'Included for indexing.',
            'row_exists' => is_array($updatedItem),
            'status_html' => is_array($updatedItem) ? $table->renderStatusCell($updatedItem) : '',
            'actions_html' => is_array($updatedItem) ? $table->renderActionsCell($updatedItem) : '',
            'group_revision' => GroupDataRevision::touch(),
        ]);
    }

    public function ajaxUpdateReferencedDocumentNiceName(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        try {
            GroupDataRevision::assertExpectedRevision(GroupDataRevision::extractExpectedRevisionFromRequest());
        } catch (OptimisticLockException $e) {
            $this->sendConflictResponse($e);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        $niceName = isset($_POST['nice_name']) ? sanitize_text_field(wp_unslash($_POST['nice_name'])) : '';

        if ($fileHash === '' || $niceName === '') {
            wp_send_json_error(['message' => 'Missing file or nice name.'], 400);
        }

        $documentStore = new DocumentStore();
        $documentManager = new ReferencedDocumentManager($documentStore);
        $success = $documentManager->updateReferencedDocumentNiceNameByHash($fileHash, $niceName);
        if (!$success) {
            wp_send_json_error(['message' => 'The nice name could not be updated.'], 500);
        }

        $items = $documentStore->getReferencedDocumentOverview(true);
        $updatedItem = null;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (($item['file_hash'] ?? '') === $fileHash) {
                $updatedItem = $item;
                break;
            }
        }

        $table = new ReferencedDocumentListTable();

        ob_start();
        $this->adminPageSections->renderReferencedDocumentsTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'message' => 'Nice name updated.',
            'row_exists' => is_array($updatedItem),
            'nice_name_html' => is_array($updatedItem) ? $table->renderNiceNameCell($updatedItem) : '',
            'group_revision' => GroupDataRevision::touch(),
        ]);
    }

    public function ajaxRefreshGeminiStores(): void {
        $startedAt = microtime(true);
        error_log('geweb-ai-search: ajaxRefreshGeminiStores started.');
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            error_log('geweb-ai-search: ajaxRefreshGeminiStores denied due to insufficient permissions.');
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $provider = ProviderFactory::make();
        if (!$provider instanceof Gemini) {
            error_log('geweb-ai-search: ajaxRefreshGeminiStores aborted because current provider is not Gemini.');
            wp_send_json_error(['message' => 'Gemini store overview is only available for the Gemini provider.'], 400);
        }

        error_log('geweb-ai-search: ajaxRefreshGeminiStores requesting fresh store overview.');
        $items = $provider->getStoreOverview(true);
        error_log('geweb-ai-search: ajaxRefreshGeminiStores received ' . count($items) . ' store(s).');
        $this->syncLocalIndexedStatusWithActiveStore($items);
        error_log('geweb-ai-search: ajaxRefreshGeminiStores finished local sync.');

        ob_start();
        $this->adminPageSections->renderGeminiStoresTable();
        $html = ob_get_clean();

        error_log('geweb-ai-search: ajaxRefreshGeminiStores completed in ' . number_format(microtime(true) - $startedAt, 3) . 's.');

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'error' => $provider->getStoreOverviewError(),
            'group_revision' => GroupDataRevision::ensureCurrentRevision(),
        ]);
    }

    public function ajaxRefreshGeminiStoreDocuments(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $provider = ProviderFactory::make();
        if (!$provider instanceof Gemini) {
            wp_send_json_error(['message' => 'Gemini store overview is only available for the Gemini provider.'], 400);
        }

        $storeName = isset($_POST['store_name']) ? sanitize_text_field(wp_unslash($_POST['store_name'])) : '';
        if ($storeName === '') {
            wp_send_json_error(['message' => 'Missing store name.'], 400);
        }

        $storeLabel = isset($_POST['store_label']) ? sanitize_text_field(wp_unslash($_POST['store_label'])) : $storeName;

        try {
            $documents = $provider->getStoreDocuments($storeName);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        try {
            $html = GeminiStoreListTable::renderDocumentList($documents);
        } catch (\Throwable $e) {
            error_log('geweb-ai-search: ajaxRefreshGeminiStoreDocuments render failed for ' . $storeName . ': ' . $e->getMessage());
            wp_send_json_error(['message' => 'Could not render the uploaded items list. Check the WordPress error log for details.'], 500);
        }

        wp_send_json_success([
            'html' => $html,
            'store_name' => $storeName,
            'store_label' => $storeLabel,
            'count' => count($documents),
            'message' => 'Uploaded items refreshed.',
            'group_revision' => GroupDataRevision::ensureCurrentRevision(),
        ]);
    }

    public function ajaxDeleteGeminiStore(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        try {
            GroupDataRevision::assertExpectedRevision(GroupDataRevision::extractExpectedRevisionFromRequest());
        } catch (OptimisticLockException $e) {
            $this->sendConflictResponse($e);
        }

        $provider = ProviderFactory::make();
        if (!$provider instanceof Gemini) {
            wp_send_json_error(['message' => 'Gemini store management is only available for the Gemini provider.'], 400);
        }

        $storeName = isset($_POST['store_name']) ? sanitize_text_field(wp_unslash($_POST['store_name'])) : '';
        if ($storeName === '') {
            wp_send_json_error(['message' => 'Missing store name.'], 400);
        }

        $wasActiveStore = $storeName === $provider->getStoreData();

        try {
            $provider->deleteStoreByResourceName($storeName);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        if ($wasActiveStore) {
            $this->clearLocalIndexTracking();
        }

        $items = $provider->getStoreOverview(true);

        ob_start();
        $this->adminPageSections->renderGeminiStoresTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'message' => 'Gemini store deleted.',
            'deleted_store_name' => $storeName,
            'error' => $provider->getStoreOverviewError(),
            'group_revision' => GroupDataRevision::touch(),
        ]);
    }

    public function ajaxRefreshModels(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $provider = ProviderFactory::make();
        $models = $provider->getModels(true);
        $selectedModel = $provider->getModel();
        $connectionStatus = $provider->getConnectionStatus();

        wp_send_json_success([
            'models' => array_values($models),
            'selected_model' => $selectedModel,
            'connection_status' => $connectionStatus,
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $storeItems
     * @return void
     */
    private function syncLocalIndexedStatusWithActiveStore(array $storeItems): void {
        $activeStoreFound = false;
        $activeStoreDocuments = [];
        foreach ($storeItems as $storeItem) {
            if (!is_array($storeItem) || empty($storeItem['is_active'])) {
                continue;
            }

            $activeStoreFound = true;
            if (!isset($storeItem['documents']) || !is_array($storeItem['documents'])) {
                break;
            }

            foreach ($storeItem['documents'] as $document) {
                if (!is_array($document) || empty($document['name'])) {
                    continue;
                }

                $activeStoreDocuments[] = (string) $document['name'];
            }

            break;
        }

        if (!$activeStoreFound) {
            return;
        }

        PostIndexManager::reconcileIndexedPostsWithRemoteDocuments($activeStoreDocuments);

        $documentStore = new DocumentStore();
        $documentManager = new ReferencedDocumentManager($documentStore);
        $documentManager->reconcileSelectionTargetsWithRemote($activeStoreDocuments);
        $documentManager->reconcileTrackedDocumentsWithRemote($activeStoreDocuments);
    }

    private function clearLocalIndexTracking(): void {
        $documentManager = new ReferencedDocumentManager();
        $documentManager->clearAllTrackedDocuments();
        PostIndexManager::clearAllIndexedState();
    }

    private function sendConflictResponse(OptimisticLockException $e): void {
        wp_send_json_error([
            'message' => $e->getMessage(),
            'current_revision' => $e->getCurrentRevision(),
        ], 409);
    }
}
