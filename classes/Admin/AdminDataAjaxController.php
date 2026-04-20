<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminDataAjaxController {
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private const MODEL_REFRESH_COOLDOWN_SECONDS = DAY_IN_SECONDS;
    private const STORE_REFRESH_COOLDOWN_SECONDS = DAY_IN_SECONDS;
    private const OFFICIAL_GEMINI_FLASH_LATEST = 'gemini-3-flash-preview';
    private const OFFICIAL_GEMINI_PRO_LATEST = 'gemini-3.1-pro-preview';

    private AdminPageSections $adminPageSections;

    public function __construct(AdminPageSections $adminPageSections) {
        $this->adminPageSections = $adminPageSections;
    }

    public function ajaxRefreshReferencedDocuments(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $preloadJobId = isset($_POST['preload_job']) ? sanitize_text_field(wp_unslash($_POST['preload_job'])) : '';
        if ($preloadJobId !== '') {
            AdminPreloadProgress::markRunning($preloadJobId, 'documents', 'Refreshing referenced documents');
        }

        $documentStore = new DocumentStore();
        $forceRefresh = !empty($_POST['force_refresh']);
        $pluginUpdating = PluginUpdateGuard::isActive();
        if ($pluginUpdating && !$documentStore->hasReferencedDocumentOverviewCache()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload('Workspace AI Search is updating. Files are temporarily unavailable.'), 503);
        }
        if ($pluginUpdating) {
            $forceRefresh = false;
        }
        $items = $documentStore->getReferencedDocumentOverview($forceRefresh);

        ob_start();
        $this->adminPageSections->renderReferencedDocumentsTable();
        $html = ob_get_clean();

        if ($preloadJobId !== '') {
            AdminPreloadProgress::markCompleted($preloadJobId, 'documents', 'Referenced documents loaded');
        }

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => DateDisplay::formatDateTime(time()),
            'count' => count($items),
            'group_revision' => GroupDataRevision::ensureCurrentRevision(),
            'cache_state' => AdminViewRevision::ensureCurrentState(),
            'plugin_updating' => $pluginUpdating,
            'message' => $pluginUpdating ? PluginUpdateGuard::getNoticeMessage() : '',
        ]);
    }

    public function ajaxStartAdminPreload(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $jobId = AdminPreloadProgress::start([
            'documents' => 'Loading Documents…',
            'stores' => 'Loading Gemini Stores…',
            'store_documents' => 'Loading Store Documents…',
            'conversations' => 'Loading Chats…',
        ]);

        wp_send_json_success([
            'job_id' => $jobId,
            'progress' => AdminPreloadProgress::getSummary($jobId),
        ]);
    }

    public function ajaxGetAdminPreloadProgress(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $jobId = isset($_REQUEST['job_id']) ? sanitize_text_field(wp_unslash($_REQUEST['job_id'])) : '';
        $summary = AdminPreloadProgress::getSummary($jobId);
        if (!is_array($summary)) {
            wp_send_json_error(['message' => 'Progress job not found.'], 404);
        }

        wp_send_json_success($summary);
    }

    public function ajaxRefreshConversations(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $preloadJobId = isset($_POST['preload_job']) ? sanitize_text_field(wp_unslash($_POST['preload_job'])) : '';
        if ($preloadJobId !== '') {
            AdminPreloadProgress::markRunning($preloadJobId, 'conversations', 'Refreshing chats');
        }

        ob_start();
        $this->adminPageSections->renderConversationsTable();
        $html = ob_get_clean();

        $conversations = (new ConversationManager())->getConversationLog();

        if ($preloadJobId !== '') {
            AdminPreloadProgress::markCompleted($preloadJobId, 'conversations', 'Chats loaded');
        }

        wp_send_json_success([
            'html' => $html,
            'count' => count($conversations),
            'refreshed_at' => DateDisplay::formatDateTime(time()),
            'cache_state' => AdminViewRevision::ensureCurrentState(),
            'plugin_updating' => PluginUpdateGuard::isActive(),
            'message' => PluginUpdateGuard::isActive() ? PluginUpdateGuard::getNoticeMessage() : '',
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

    public function ajaxGetReferencedDocumentMarkdownCache(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        if ($fileHash === '') {
            wp_send_json_error(['message' => 'Missing file hash.'], 400);
        }

        $cacheStore = new ReferencedDocumentMarkdownCacheStore();
        $markdown = $cacheStore->getMarkdown($fileHash);
        if ($markdown === '') {
            wp_send_json_error(['message' => 'No Markdown cache found for this document.'], 404);
        }

        $documentStore = new DocumentStore();
        $items = $documentStore->getReferencedDocumentOverview();
        $matchedItem = null;
        foreach ($items as $item) {
            if (is_array($item) && (($item['file_hash'] ?? '') === $fileHash)) {
                $matchedItem = $item;
                break;
            }
        }

        $displayName = $cacheStore->getDisplayName($fileHash);
        if ($displayName === '' && is_array($matchedItem)) {
            $displayName = (string) ($matchedItem['display_name'] ?? '');
        }

        $originalUrl = is_array($matchedItem) ? (string) ($matchedItem['file_url'] ?? '') : '';

        wp_send_json_success([
            'markdown' => $markdown,
            'rendered_html' => '',
            'title' => $displayName,
            'filename' => preg_replace('/\.[^.]+$/', '.md', $displayName ?: 'document.md'),
            'url' => $originalUrl,
        ]);
    }

    public function ajaxUpdateReferencedDocument(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload(), 503);
        }

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
            $items = $documentStore->getReferencedDocumentOverview(true);
            $updatedItems = array_values(array_filter($items, static function ($item) use ($fileHash): bool {
                return is_array($item) && (($item['file_hash'] ?? '') === $fileHash);
            }));
            $updatedItem = $updatedItems[0] ?? null;
            $table = new ReferencedDocumentListTable();

            wp_send_json_error([
                'message' => 'The document action could not be completed.',
                'row_exists' => is_array($updatedItem),
                'row_html' => is_array($updatedItem) ? $table->renderRowHtml($updatedItem) : '',
                'status_html' => is_array($updatedItem) ? $table->renderStatusCell($updatedItem) : '',
                'actions_html' => is_array($updatedItem) ? $table->renderActionsCell($updatedItem) : '',
                'markdown_cache_html' => is_array($updatedItem) ? $table->renderMarkdownCacheCell($updatedItem) : '',
                'group_revision' => GroupDataRevision::touch(),
                'cache_state' => (function (): array {
                    AdminViewRevision::touchFiles();
                    return AdminViewRevision::ensureCurrentState();
                })(),
            ], 500);
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
            'markdown_cache_html' => is_array($updatedItem) ? $table->renderMarkdownCacheCell($updatedItem) : '',
            'group_revision' => GroupDataRevision::touch(),
            'cache_state' => (function (): array {
                AdminViewRevision::touchFiles();
                return AdminViewRevision::ensureCurrentState();
            })(),
        ]);
    }

    public function ajaxToggleReferencedDocumentExclude(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload(), 503);
        }

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
                $items = $documentStore->getReferencedDocumentOverview(true);
                $updatedItem = null;
                foreach ($items as $item) {
                    if (is_array($item) && (($item['file_hash'] ?? '') === $fileHash)) {
                        $updatedItem = $item;
                        break;
                    }
                }
                $table = new ReferencedDocumentListTable();

                wp_send_json_error([
                    'message' => 'Could not remove this source from the Gemini store. It is still included.',
                    'row_exists' => is_array($updatedItem),
                    'row_html' => is_array($updatedItem) ? $table->renderRowHtml($updatedItem) : '',
                    'status_html' => is_array($updatedItem) ? $table->renderStatusCell($updatedItem) : '',
                    'actions_html' => is_array($updatedItem) ? $table->renderActionsCell($updatedItem) : '',
                    'markdown_cache_html' => is_array($updatedItem) ? $table->renderMarkdownCacheCell($updatedItem) : '',
                    'group_revision' => GroupDataRevision::touch(),
                    'cache_state' => (function (): array {
                        AdminViewRevision::touchFiles();
                        return AdminViewRevision::ensureCurrentState();
                    })(),
                ], 500);
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
            'markdown_cache_html' => is_array($updatedItem) ? $table->renderMarkdownCacheCell($updatedItem) : '',
            'group_revision' => GroupDataRevision::touch(),
            'cache_state' => (function (): array {
                AdminViewRevision::touchFiles();
                return AdminViewRevision::ensureCurrentState();
            })(),
        ]);
    }

    public function ajaxSetReferencedDocumentImageProcessingMode(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload(), 503);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        try {
            GroupDataRevision::assertExpectedRevision(GroupDataRevision::extractExpectedRevisionFromRequest());
        } catch (OptimisticLockException $e) {
            $this->sendConflictResponse($e);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        $mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : ImageOcrService::MODE_NONE;
        if ($fileHash === '') {
            wp_send_json_error(['message' => 'Missing file hash.'], 400);
        }

        $documentStore = new DocumentStore();
        $documentManager = new ReferencedDocumentManager($documentStore);
        $documentManager->saveReferencedDocumentImageProcessingMode($fileHash, $mode);
        $cacheWarning = '';
        if ($mode === ImageOcrService::MODE_NONE) {
            (new ReferencedDocumentMarkdownCacheStore())->deleteMarkdown($fileHash);
        } else {
            try {
                $documentManager->refreshReferencedDocumentImageMarkdownCache($fileHash);
            } catch (\Exception $exception) {
                $cacheWarning = sanitize_text_field($exception->getMessage());
            }
        }

        $items = $documentStore->getReferencedDocumentOverview(true);
        $updatedItem = null;
        foreach ($items as $item) {
            if (is_array($item) && (($item['file_hash'] ?? '') === $fileHash)) {
                $updatedItem = $item;
                break;
            }
        }

        $table = new ReferencedDocumentListTable();
        $processingSubject = is_array($updatedItem) && ((string) ($updatedItem['mime_type'] ?? '')) === 'application/pdf'
            ? 'PDF'
            : 'image';
        $message = $mode === ImageOcrService::MODE_DESCRIBE
            ? ($processingSubject === 'PDF' ? 'PDF description enabled.' : 'Image description enabled.')
            : ($mode === ImageOcrService::MODE_OCR
                ? ($processingSubject === 'PDF' ? 'OCR enabled for this PDF.' : 'OCR enabled for this image.')
                : ($processingSubject === 'PDF' ? 'PDF processing disabled.' : 'Image processing disabled.'));
        if ($cacheWarning !== '') {
            $message .= ' Cache could not be generated yet: ' . $cacheWarning;
        }

        wp_send_json_success([
            'message' => $message,
            'row_exists' => is_array($updatedItem),
            'actions_html' => is_array($updatedItem) ? $table->renderActionsCell($updatedItem) : '',
            'pdf_analysis_html' => is_array($updatedItem) ? $table->renderPdfAnalysisCell($updatedItem) : '',
            'markdown_cache_html' => is_array($updatedItem) ? $table->renderMarkdownCacheCell($updatedItem) : '',
            'group_revision' => GroupDataRevision::touch(),
            'cache_state' => (function (): array {
                AdminViewRevision::touchFiles();
                return AdminViewRevision::ensureCurrentState();
            })(),
        ]);
    }

    public function ajaxUpdateReferencedDocumentNiceName(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload(), 503);
        }

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
            'cache_state' => (function (): array {
                AdminViewRevision::touchFiles();
                return AdminViewRevision::ensureCurrentState();
            })(),
        ]);
    }

    public function ajaxRemoveReferencedDocumentFromFileList(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload(), 503);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        try {
            GroupDataRevision::assertExpectedRevision(GroupDataRevision::extractExpectedRevisionFromRequest());
        } catch (OptimisticLockException $e) {
            $this->sendConflictResponse($e);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        if ($fileHash === '') {
            wp_send_json_error(['message' => 'Missing file hash.'], 400);
        }

        $documentStore = new DocumentStore();
        $documentManager = new ReferencedDocumentManager($documentStore);
        $success = $documentManager->removeReferencedDocumentFromFileListByHash($fileHash);
        if (!$success) {
            wp_send_json_error(['message' => 'Only unreferenced File List items can be removed from Simple File List here.'], 500);
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
            'message' => 'Removed from Simple File List.',
            'row_exists' => is_array($updatedItem),
            'row_html' => is_array($updatedItem) ? $table->renderRowHtml($updatedItem) : '',
            'group_revision' => GroupDataRevision::touch(),
            'cache_state' => (function (): array {
                AdminViewRevision::touchFiles();
                return AdminViewRevision::ensureCurrentState();
            })(),
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

        $preloadJobId = isset($_POST['preload_job']) ? sanitize_text_field(wp_unslash($_POST['preload_job'])) : '';
        if ($preloadJobId !== '') {
            AdminPreloadProgress::markRunning($preloadJobId, 'stores', 'Refreshing Gemini stores');
        }

        $provider = ProviderFactory::make();
        if (!$provider instanceof Gemini) {
            error_log('geweb-ai-search: ajaxRefreshGeminiStores aborted because current provider is not Gemini.');
            if ($preloadJobId !== '') {
                AdminPreloadProgress::markFailed($preloadJobId, 'stores', 'Gemini provider is not active');
                AdminPreloadProgress::markFailed($preloadJobId, 'store_documents', 'Gemini provider is not active');
            }
            wp_send_json_error(['message' => 'Gemini store overview is only available for the Gemini provider.'], 400);
        }

        $requestedForceRefresh = !empty($_POST['force_refresh']);
        $pluginUpdating = PluginUpdateGuard::isActive();
        if ($pluginUpdating && !$provider->hasStoreOverviewCache()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload('Workspace AI Search is updating. Gemini Stores are temporarily unavailable.'), 503);
        }
        $shouldForceRefresh = $requestedForceRefresh || $this->shouldForceStoreRefresh($provider);
        if ($pluginUpdating) {
            $shouldForceRefresh = false;
        }
        error_log('geweb-ai-search: ajaxRefreshGeminiStores requesting ' . ($shouldForceRefresh ? 'fresh' : 'cached') . ' store overview.');
        $items = $provider->getStoreOverview($shouldForceRefresh);
        error_log('geweb-ai-search: ajaxRefreshGeminiStores received ' . count($items) . ' store(s).');
        $this->syncLocalIndexedStatusWithActiveStore($items);
        error_log('geweb-ai-search: ajaxRefreshGeminiStores finished local sync.');

        ob_start();
        $this->adminPageSections->renderGeminiStoresTable();
        $html = ob_get_clean();

        error_log('geweb-ai-search: ajaxRefreshGeminiStores completed in ' . number_format(microtime(true) - $startedAt, 3) . 's.');

        if ($preloadJobId !== '') {
            AdminPreloadProgress::markCompleted($preloadJobId, 'stores', 'Gemini stores loaded');
            if (count($items) < 1) {
                AdminPreloadProgress::markCompleted($preloadJobId, 'store_documents', 'No Gemini stores available');
            }
        }

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => DateDisplay::formatDateTime(time()),
            'count' => count($items),
            'error' => $provider->getStoreOverviewError(),
            'group_revision' => GroupDataRevision::ensureCurrentRevision(),
            'plugin_updating' => $pluginUpdating,
            'message' => $pluginUpdating ? PluginUpdateGuard::getNoticeMessage() : '',
        ]);
    }

    private function shouldForceStoreRefresh(Gemini $provider): bool {
        if (!$provider->hasStoreOverviewCache()) {
            return false;
        }

        $cacheTime = $provider->getStoreOverviewCacheTime();
        if ($cacheTime <= 0) {
            return true;
        }

        return (time() - $cacheTime) >= self::STORE_REFRESH_COOLDOWN_SECONDS || $provider->isStoreOverviewCacheStale();
    }

    public function ajaxRefreshGeminiStoreDocuments(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $preloadJobId = isset($_POST['preload_job']) ? sanitize_text_field(wp_unslash($_POST['preload_job'])) : '';
        if ($preloadJobId !== '') {
            AdminPreloadProgress::markRunning($preloadJobId, 'store_documents', 'Refreshing store documents');
        }

        $provider = ProviderFactory::make();
        if (!$provider instanceof Gemini) {
            if ($preloadJobId !== '') {
                AdminPreloadProgress::markFailed($preloadJobId, 'store_documents', 'Gemini provider is not active');
            }
            wp_send_json_error(['message' => 'Gemini store overview is only available for the Gemini provider.'], 400);
        }

        $storeName = isset($_POST['store_name']) ? sanitize_text_field(wp_unslash($_POST['store_name'])) : '';
        if ($storeName === '') {
            if ($preloadJobId !== '') {
                AdminPreloadProgress::markFailed($preloadJobId, 'store_documents', 'Missing store name');
            }
            wp_send_json_error(['message' => 'Missing store name.'], 400);
        }

        $storeLabel = isset($_POST['store_label']) ? sanitize_text_field(wp_unslash($_POST['store_label'])) : $storeName;

        $forceRefresh = !empty($_POST['force_refresh']);
        $pluginUpdating = PluginUpdateGuard::isActive();
        if ($pluginUpdating && !$provider->hasStoreDocumentsCache($storeName)) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload('Workspace AI Search is updating. Uploaded items are temporarily unavailable.'), 503);
        }
        if ($pluginUpdating) {
            $forceRefresh = false;
        }

        try {
            $documents = $provider->getStoreDocuments($storeName, $forceRefresh);
        } catch (\Exception $e) {
            if ($preloadJobId !== '') {
                AdminPreloadProgress::markFailed($preloadJobId, 'store_documents', $e->getMessage());
            }
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        try {
            $html = GeminiStoreListTable::renderDocumentList($documents);
        } catch (\Throwable $e) {
            error_log('geweb-ai-search: ajaxRefreshGeminiStoreDocuments render failed for ' . $storeName . ': ' . $e->getMessage());
            if ($preloadJobId !== '') {
                AdminPreloadProgress::markFailed($preloadJobId, 'store_documents', 'Could not render uploaded items list');
            }
            wp_send_json_error(['message' => 'Could not render the uploaded items list. Check the WordPress error log for details.'], 500);
        }

        if ($preloadJobId !== '') {
            AdminPreloadProgress::markCompleted($preloadJobId, 'store_documents', 'Store documents loaded');
        }

        wp_send_json_success([
            'html' => $html,
            'store_name' => $storeName,
            'store_label' => $storeLabel,
            'count' => count($documents),
            'message' => 'Uploaded items refreshed.',
            'group_revision' => GroupDataRevision::ensureCurrentRevision(),
            'plugin_updating' => $pluginUpdating,
        ]);
    }

    public function ajaxDeleteGeminiStore(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload(), 503);
        }

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

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload('Workspace AI Search is updating. Model refresh is temporarily unavailable.'), 503);
        }

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

        $dropdownModels = array_values(array_filter($models, function ($m) use ($provider, $selectedModel) {
            if ($m === $selectedModel) {
                return true;
            }
            if ($provider instanceof Gemini && $provider->isDeprecatedModel($m)) {
                return false;
            }
            return true;
        }));

        wp_send_json_success([
            'models' => array_values($models),
            'dropdown_models' => $dropdownModels,
            'selected_model' => $selectedModel,
            'connection_status' => $connectionStatus,
            'model_statuses' => is_array($modelStatuses) ? $modelStatuses : [],
            'used_cached_models' => !$shouldForceRefresh,
        ]);
    }

    public function ajaxTestModel(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (PluginUpdateGuard::isActive()) {
            wp_send_json_error(PluginUpdateGuard::buildJsonErrorPayload('Workspace AI Search is updating. Model tests are temporarily unavailable.'), 503);
        }

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
        $models = $provider->getModels();
        $selectedModel = $provider->getModel();
        $defaultModel = $provider->getDefaultModel($models);
        $diagnosticsHtml = $this->renderModelDiagnosticsHtml($models, $modelStatuses, $selectedModel, $defaultModel);

        if (($result['status'] ?? '') === 'ok') {
            wp_send_json_success([
                'result' => $result,
                'model_statuses' => $modelStatuses,
                'model_diagnostics_html' => $diagnosticsHtml,
            ]);
        }

        wp_send_json_error([
            'message' => (string) ($result['message'] ?? 'Model test failed.'),
            'result' => $result,
            'model_statuses' => $modelStatuses,
            'model_diagnostics_html' => $diagnosticsHtml,
        ], 500);
    }

    /**
     * @param array<int,string> $models
     * @param array<string,mixed> $modelStatuses
     */
    private function renderModelDiagnosticsHtml(array $models, array $modelStatuses, string $selectedModel, string $defaultModel): string {
        ob_start();
        $this->adminPageSections->renderModelDiagnosticsSection([
            'models' => $models,
            'modelStatuses' => $modelStatuses,
            'selectedModel' => $selectedModel,
            'defaultModel' => $defaultModel,
            'officialLatestAliases' => [
                'flash_latest' => self::OFFICIAL_GEMINI_FLASH_LATEST,
                'pro_latest' => self::OFFICIAL_GEMINI_PRO_LATEST,
            ],
            'workingModelHints' => [
                'flash' => $this->pickLatestWorkingModelByFamily($models, $modelStatuses, 'flash'),
                'pro' => $this->pickLatestWorkingModelByFamily($models, $modelStatuses, 'pro'),
            ],
            'latestModelHints' => [
                'flash' => $this->pickLatestModelByFamily($models, 'flash'),
                'pro' => $this->pickLatestModelByFamily($models, 'pro'),
                'stable_flash' => $this->pickLatestModelByFamily($models, 'flash', true),
                'stable_pro' => $this->pickLatestModelByFamily($models, 'pro', true),
            ],
            'statusColorError' => '#d63638',
            'statusColorSuccess' => '#46b450',
        ]);

        return (string) ob_get_clean();
    }

    /**
     * @param array<int,string> $models
     * @param array<string,mixed> $modelStatuses
     */
    private function pickLatestWorkingModelByFamily(array $models, array $modelStatuses, string $family): string {
        $workingModels = [];
        foreach ($models as $model) {
            $status = $modelStatuses[(string) $model] ?? null;
            if (!is_array($status) || (($status['status'] ?? '') !== 'ok')) {
                continue;
            }

            $resolvedModel = trim((string) ($status['resolved_model'] ?? ''));
            $workingModels[] = $resolvedModel !== '' ? $resolvedModel : (string) $model;
        }

        $workingModels = array_values(array_unique(array_filter($workingModels, static function ($model): bool {
            return is_string($model) && trim($model) !== '';
        })));

        return $this->pickLatestModelByFamily($workingModels, $family);
    }

    /**
     * @param array<int,string> $models
     */
    private function pickLatestModelByFamily(array $models, string $family, bool $stableOnly = false): string {
        $bestModel = '';
        $bestRank = null;

        foreach ($models as $model) {
            $normalizedModel = strtolower(trim((string) $model));
            if ($normalizedModel === '') {
                continue;
            }

            if ($family === 'flash') {
                if (!str_contains($normalizedModel, '-flash') || str_contains($normalizedModel, 'flash-lite')) {
                    continue;
                }
            } elseif ($family === 'pro') {
                if (!str_contains($normalizedModel, '-pro')) {
                    continue;
                }
            } else {
                continue;
            }

            if ($stableOnly && str_contains($normalizedModel, 'preview')) {
                continue;
            }

            $rank = $this->rankModelName($normalizedModel);
            if ($bestRank === null || $rank > $bestRank) {
                $bestRank = $rank;
                $bestModel = (string) $model;
            }
        }

        return $bestModel;
    }

    /**
     * @return array<int,int>
     */
    private function rankModelName(string $model): array {
        $major = 0;
        $minor = 0;
        if (preg_match('/gemini-(\d+)(?:\.(\d+))?/i', $model, $matches)) {
            $major = isset($matches[1]) ? (int) $matches[1] : 0;
            $minor = isset($matches[2]) ? (int) $matches[2] : 0;
        }

        return [
            $major,
            $minor,
            str_contains($model, 'preview') ? 0 : 1,
            str_contains($model, 'lite') ? 0 : 1,
        ];
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
