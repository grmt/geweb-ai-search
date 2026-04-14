<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminDataAjaxController {
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private const MODEL_REFRESH_COOLDOWN_SECONDS = DAY_IN_SECONDS;

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
            'refreshed_at' => DateDisplay::formatDateTime(time()),
            'count' => count($items),
            'group_revision' => GroupDataRevision::ensureCurrentRevision(),
        ]);
    }

    public function ajaxGetMarkdownCache(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $postId = isset($_POST['post_id']) ? (int) wp_unslash($_POST['post_id']) : 0;
        if ($postId <= 0) {
            wp_send_json_error(['message' => 'Invalid post ID.'], 400);
        }

        $markdown = (new MarkdownCacheStore())->getMarkdown($postId);
        if ($markdown === '') {
            wp_send_json_error(['message' => 'No Markdown cache found for this post.'], 404);
        }

        $post = get_post($postId);
        $renderedHtml = '';
        if ($post instanceof \WP_Post) {
            $renderedHtml = (string) apply_filters('the_content', $post->post_content);
        }

        wp_send_json_success([
            'markdown' => $markdown,
            'rendered_html' => $renderedHtml,
            'post_id' => $postId,
            'title' => (string) get_the_title($postId),
            'filename' => $postId . '.md',
            'url' => (string) get_permalink($postId),
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
        $documentManager->saveReferencedDocumentOperationStatus($fileHash, $shouldInclude ? 'uploading' : 'excluding');
        $success = $shouldInclude
            ? $documentManager->uploadReferencedDocumentByHash($fileHash)
            : $documentManager->removeReferencedDocumentByHash($fileHash);

        if ($success) {
            $documentManager->saveReferencedDocumentSelectionTarget($fileHash, $shouldInclude);
            $documentManager->saveReferencedDocumentOperationStatus($fileHash, $shouldInclude ? 'indexed' : 'excluded');
        }

        if (!$success) {
            $documentManager->saveReferencedDocumentOperationStatus($fileHash, 'error', 'The document action could not be completed.');
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
            'refreshed_at' => DateDisplay::formatDateTime(time()),
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
            $documentManager->saveReferencedDocumentOperationStatus($fileHash, 'excluding');
            $removed = $documentManager->removeReferencedDocumentByHash($fileHash);
            if (!$removed) {
                $documentManager->saveReferencedDocumentOperationStatus($fileHash, 'error', 'Could not remove this source from the Gemini store. It is still included.');
                wp_send_json_error(['message' => 'Could not remove this source from the Gemini store. It is still included.'], 500);
            }
            $documentManager->saveReferencedDocumentSelectionTarget($fileHash, false);
            $documentManager->saveReferencedDocumentOperationStatus($fileHash, 'excluded');
        } else {
            $documentManager->saveReferencedDocumentSelectionTarget($fileHash, true);
            $documentManager->saveReferencedDocumentOperationStatus($fileHash, 'not_indexed');
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
            'refreshed_at' => DateDisplay::formatDateTime(time()),
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
            'refreshed_at' => DateDisplay::formatDateTime(time()),
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
            'refreshed_at' => DateDisplay::formatDateTime(time()),
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
        $connectionStatus = $provider->getConnectionStatus();
        $requestedForceRefresh = !empty($_POST['force_refresh']);
        $shouldForceRefresh = $requestedForceRefresh || $this->shouldForceModelRefresh($connectionStatus);
        $models = $provider->getModels($shouldForceRefresh);
        $selectedModel = $provider->getModel();
        $modelStatuses = $provider->getModelStatuses();

        wp_send_json_success([
            'models' => array_values($models),
            'selected_model' => $selectedModel,
            'connection_status' => $connectionStatus,
            'model_statuses' => is_array($modelStatuses) ? $modelStatuses : [],
            'used_cached_models' => !$shouldForceRefresh,
        ]);
    }

    public function ajaxTestModel(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        if ($model === '') {
            wp_send_json_error(['message' => 'No model selected.'], 400);
        }

        $provider = ProviderFactory::make();
        $result = $provider->testModel($model);
        $modelStatuses = $provider->getModelStatuses();

        if (($result['status'] ?? '') === 'ok') {
            wp_send_json_success([
                'result' => $result,
                'model_statuses' => $modelStatuses,
            ]);
        }

        wp_send_json_error([
            'message' => (string) ($result['message'] ?? 'Model test failed.'),
            'result' => $result,
            'model_statuses' => $modelStatuses,
        ], 500);
    }

    /**
     * @param array<string,mixed> $connectionStatus
     */
    private function shouldForceModelRefresh(array $connectionStatus): bool {
        $timestamp = isset($connectionStatus['timestamp']) ? (int) $connectionStatus['timestamp'] : 0;
        if ($timestamp <= 0) {
            return true;
        }

        return (current_time('timestamp') - $timestamp) >= self::MODEL_REFRESH_COOLDOWN_SECONDS;
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
