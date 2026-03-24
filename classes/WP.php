<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * WordPress integration class
 *
 * Handles admin interface, AJAX endpoints, and WordPress hooks
 */
class WP {
    /**
     * Option key for custom AI prompt
     */
    private const OPTION_CUSTOM_PROMPT = 'geweb_aisearch_custom_prompt';
    private const OPTION_PROMPT_HISTORY = 'geweb_aisearch_prompt_history';
    private const OPTION_PROMPT_HISTORY_LIMIT = 'geweb_aisearch_prompt_history_limit';
    private const OPTION_CUSTOM_PROMPT_NAME = 'geweb_aisearch_custom_prompt_name';
    private const OPTION_PROVIDER = 'geweb_aisearch_provider';
    private const OPTION_INCLUDE_REFERENCED_DOCUMENTS = 'geweb_aisearch_include_referenced_documents';
    private const OPTION_PRESERVE_DATA_ON_UNINSTALL = 'geweb_aisearch_preserve_data_on_uninstall';
    private const OPTION_CONNECTION_STATUS = 'geweb_aisearch_connection_status';
    private const DEFAULT_PROMPT_HISTORY_LIMIT = 10;

    /**
     * Constructor - registers WordPress hooks
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('admin_post_geweb_save', [$this, 'saveSettings']);
        add_filter('plugin_action_links_' . plugin_basename(GEWEB_AI_SEARCH_PATH . 'geweb-ai-search.php'), [$this, 'addPluginActionLinks']);

        add_action('wp_ajax_geweb_search', [$this, 'ajaxSearch']);
        add_action('wp_ajax_nopriv_geweb_search', [$this, 'ajaxSearch']);

        add_action('wp_ajax_geweb_ai_chat', [$this, 'ajaxAiChat']);
        add_action('wp_ajax_nopriv_geweb_ai_chat', [$this, 'ajaxAiChat']);
        add_action('wp_ajax_geweb_clear_prompt_history', [$this, 'ajaxClearPromptHistory']);
        add_action('wp_ajax_geweb_delete_prompt_history_item', [$this, 'ajaxDeletePromptHistoryItem']);
        add_action('wp_ajax_geweb_refresh_referenced_documents', [$this, 'ajaxRefreshReferencedDocuments']);
        add_action('wp_ajax_geweb_update_referenced_document', [$this, 'ajaxUpdateReferencedDocument']);
        add_action('wp_ajax_geweb_toggle_referenced_document_exclude', [$this, 'ajaxToggleReferencedDocumentExclude']);
        add_action('wp_ajax_geweb_update_referenced_document_nice_name', [$this, 'ajaxUpdateReferencedDocumentNiceName']);
        add_action('wp_ajax_geweb_refresh_gemini_stores', [$this, 'ajaxRefreshGeminiStores']);
        add_action('wp_ajax_geweb_delete_gemini_store', [$this, 'ajaxDeleteGeminiStore']);
        add_action('wp_ajax_geweb_refresh_models', [$this, 'ajaxRefreshModels']);

        add_action('wp_ajax_geweb_get_nonce', [$this, 'ajaxGetNonce']);
        add_action('wp_ajax_nopriv_geweb_get_nonce', [$this, 'ajaxGetNonce']);

        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);

        add_action('wp_footer', [$this, 'renderModals']);

        // Initialize HTML2MD hooks
        new HTML2MD();
    }

    /**
     * Register admin menu
     *
     * @return void
     */
    public function adminMenu(): void {
        add_menu_page(
            'Geweb AI Search',
            'Geweb AI Search',
            'manage_options',
            'geweb-ai-search',
            [$this, 'renderOptionsPage'],
            'dashicons-search'
        );

        add_submenu_page(
            'geweb-ai-search',
            'General',
            'General',
            'manage_options',
            'geweb-ai-search',
            [$this, 'renderOptionsPage']
        );

        add_submenu_page(
            'geweb-ai-search',
            'Prompts',
            'Prompts',
            'manage_options',
            'geweb-ai-search&geweb_tab=prompts',
            [$this, 'renderOptionsPage']
        );

        add_submenu_page(
            'geweb-ai-search',
            'Documents',
            'Documents',
            'manage_options',
            'geweb-ai-search&geweb_tab=documents',
            [$this, 'renderOptionsPage']
        );

        add_submenu_page(
            'geweb-ai-search',
            'Gemini Stores',
            'Gemini Stores',
            'manage_options',
            'geweb-ai-search&geweb_tab=stores',
            [$this, 'renderOptionsPage']
        );
    }

    /**
     * Render referenced documents table HTML.
     *
     * @return void
     */
    private function renderReferencedDocumentsTable(): void {
        $table = new ReferencedDocumentListTable();
        $table->prepare_items();
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="geweb-referenced-documents-table-form">
            <input type="hidden" name="page" value="geweb-ai-search">
            <input type="hidden" name="geweb_tab" value="documents">
            <?php $table->display(); ?>
        </form>
        <?php
    }

    /**
     * Render Gemini stores table HTML.
     *
     * @return void
     */
    private function renderGeminiStoresTable(): void {
        $table = new GeminiStoreListTable();
        $table->prepare_items();
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="geweb-gemini-stores-table-form">
            <input type="hidden" name="page" value="geweb-ai-search">
            <input type="hidden" name="geweb_tab" value="stores">
            <?php $table->display(); ?>
        </form>
        <?php
    }

    /**
     * Build the main settings URL for a specific tab.
     *
     * @param string $tab
     * @return string
     */
    private function getTabUrl(string $tab): string {
        return add_query_arg(
            [
                'page' => 'geweb-ai-search',
                'geweb_tab' => $tab,
            ],
            admin_url('admin.php')
        );
    }

    /**
     * AJAX: Refresh referenced documents cache and return rendered table.
     *
     * @return void
     */
    public function ajaxRefreshReferencedDocuments(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $documentStore = new DocumentStore();
        $items = $documentStore->getReferencedDocumentOverview(true);

        ob_start();
        $this->renderReferencedDocumentsTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
        ]);
    }

    /**
     * AJAX: Upload or remove a referenced document from the file store.
     *
     * @return void
     */
    public function ajaxUpdateReferencedDocument(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        $actionName = isset($_POST['document_action']) ? sanitize_key(wp_unslash($_POST['document_action'])) : '';

        if ($fileHash === '' || !in_array($actionName, ['upload', 'remove'], true)) {
            wp_send_json_error(['message' => 'Invalid document action.'], 400);
        }

        $documentStore = new DocumentStore();
        $success = false;

        if ($actionName === 'upload') {
            $success = $documentStore->uploadReferencedDocumentByHash($fileHash);
        } elseif ($actionName === 'remove') {
            $success = $documentStore->removeReferencedDocumentByHash($fileHash);
        }

        if (!$success) {
            wp_send_json_error(['message' => 'The document action could not be completed.'], 500);
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
        $this->renderReferencedDocumentsTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'message' => $actionName === 'upload' ? 'Document uploaded.' : 'Document removed from store.',
            'row_exists' => is_array($updatedItem),
            'status_html' => is_array($updatedItem) ? $table->renderStatusCell($updatedItem) : '',
            'actions_html' => is_array($updatedItem) ? $table->renderActionsCell($updatedItem) : '',
        ]);
    }

    /**
     * AJAX: Toggle whether a referenced document is excluded from store indexing.
     *
     * @return void
     */
    public function ajaxToggleReferencedDocumentExclude(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        $exclude = !empty($_POST['exclude']);

        if ($fileHash === '') {
            wp_send_json_error(['message' => 'Missing file hash.'], 400);
        }

        $documentStore = new DocumentStore();
        $documentStore->saveReferencedDocumentSelectionTarget($fileHash, !$exclude);
        if ($exclude) {
            $documentStore->removeReferencedDocumentByHash($fileHash);
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
        ]);
    }

    /**
     * AJAX: Update a referenced document nice name in Simple File List metadata.
     *
     * @return void
     */
    public function ajaxUpdateReferencedDocumentNiceName(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        $niceName = isset($_POST['nice_name']) ? sanitize_text_field(wp_unslash($_POST['nice_name'])) : '';

        if ($fileHash === '' || $niceName === '') {
            wp_send_json_error(['message' => 'Missing file or nice name.'], 400);
        }

        $documentStore = new DocumentStore();
        $success = $documentStore->updateReferencedDocumentNiceNameByHash($fileHash, $niceName);
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
        $this->renderReferencedDocumentsTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'message' => 'Nice name updated.',
            'row_exists' => is_array($updatedItem),
            'nice_name_html' => is_array($updatedItem) ? $table->renderNiceNameCell($updatedItem) : '',
        ]);
    }

    /**
     * AJAX: Refresh Gemini store cache and return rendered table.
     *
     * @return void
     */
    public function ajaxRefreshGeminiStores(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $provider = ProviderFactory::make();
        if (!$provider instanceof Gemini) {
            wp_send_json_error(['message' => 'Gemini store overview is only available for the Gemini provider.'], 400);
        }

        $items = $provider->getStoreOverview(true);

        ob_start();
        $this->renderGeminiStoresTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'error' => $provider->getStoreOverviewError(),
        ]);
    }

    /**
     * AJAX: Delete a Gemini store and return refreshed table HTML.
     *
     * @return void
     */
    public function ajaxDeleteGeminiStore(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $provider = ProviderFactory::make();
        if (!$provider instanceof Gemini) {
            wp_send_json_error(['message' => 'Gemini store management is only available for the Gemini provider.'], 400);
        }

        $storeName = isset($_POST['store_name']) ? sanitize_text_field(wp_unslash($_POST['store_name'])) : '';
        if ($storeName === '') {
            wp_send_json_error(['message' => 'Missing store name.'], 400);
        }

        try {
            $provider->deleteStoreByResourceName($storeName);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }

        $items = $provider->getStoreOverview(true);

        ob_start();
        $this->renderGeminiStoresTable();
        $html = ob_get_clean();

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'message' => 'Gemini store deleted.',
            'deleted_store_name' => $storeName,
            'error' => $provider->getStoreOverviewError(),
        ]);
    }

    /**
     * AJAX: Refresh Gemini models for the settings page without blocking page render.
     *
     * @return void
     */
    public function ajaxRefreshModels(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
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
     * Save plugin settings
     *
     * @return void
     */
    public function saveSettings(): void {
        check_admin_referer('geweb_ai_search_save_settings');

        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }

        // Save API Key
        $submittedApiKey = '';
        if (isset($_POST['geweb_gemini_token'])) {
            $submittedApiKey = sanitize_text_field(wp_unslash($_POST['geweb_gemini_token']));
        } elseif (isset($_POST['geweb_api_key'])) {
            $submittedApiKey = sanitize_text_field(wp_unslash($_POST['geweb_api_key']));
        }

        if ($submittedApiKey !== '') {
            $encryption = new Encryption();
            $encryption->saveApiKey($submittedApiKey);

            // Create store if doesn't exist or if forced
            $provider = ProviderFactory::make();
            $provider->clearModelsCache();
            $connectionStatus = $provider->validateConnection();
            if (($connectionStatus['status'] ?? '') === 'ok' && (empty($provider->getStoreData()) || isset($_POST['geweb_ai_search_create_store']))) {
                $provider->createStore();
            }
        }

        update_option(
            self::OPTION_PROVIDER,
            ProviderFactory::getConfiguredProviderKey()
        );

        // Save Post Types
        if (isset($_POST['geweb_ai_search_post_types']) && is_array($_POST['geweb_ai_search_post_types'])) {
            $postTypes = array_map('sanitize_key', wp_unslash($_POST['geweb_ai_search_post_types']));
            update_option('geweb_aisearch_post_types', $postTypes);
        } else {
            update_option('geweb_aisearch_post_types', []);
        }

        // Save Model
        if (isset($_POST['geweb_ai_search_model'])) {
            update_option('geweb_aisearch_model', sanitize_text_field(wp_unslash($_POST['geweb_ai_search_model'])));
        }

        update_option(
            self::OPTION_INCLUDE_REFERENCED_DOCUMENTS,
            !empty($_POST['geweb_ai_search_include_referenced_documents']) ? '1' : '0'
        );
        update_option(
            self::OPTION_PRESERVE_DATA_ON_UNINSTALL,
            !empty($_POST['geweb_ai_search_preserve_data_on_uninstall']) ? '1' : '0'
        );

        if (isset($_POST['geweb_ai_search_referenced_document_targets'])) {
            $rawTargets = wp_unslash($_POST['geweb_ai_search_referenced_document_targets']);
            $decodedTargets = json_decode(is_string($rawTargets) ? $rawTargets : '', true);
            if (is_array($decodedTargets)) {
                $normalizedTargets = [];
                foreach ($decodedTargets as $fileHash => $target) {
                    if (!is_string($fileHash) || $fileHash === '') {
                        continue;
                    }

                    $normalizedTargets[sanitize_text_field($fileHash)] = (bool) $target;
                }

                $documentStore = new DocumentStore();
                $documentStore->applyReferencedDocumentSelectionTargets($normalizedTargets);
            }
        }

        $historyLimit = self::DEFAULT_PROMPT_HISTORY_LIMIT;
        if (isset($_POST['geweb_ai_search_prompt_history_limit'])) {
            $historyLimit = max(1, intval($_POST['geweb_ai_search_prompt_history_limit']));
        }
        update_option(self::OPTION_PROMPT_HISTORY_LIMIT, $historyLimit);
        $this->trimPromptHistory(max(0, $historyLimit - 1));

        // Save custom prompt
        if (isset($_POST['geweb_ai_search_custom_prompt'])) {
            $newPrompt = $this->normalizePromptInput($_POST['geweb_ai_search_custom_prompt']);
            $currentPrompt = (string) get_option(self::OPTION_CUSTOM_PROMPT, '');
            $newPromptName = isset($_POST['geweb_ai_search_custom_prompt_name'])
                ? sanitize_text_field(wp_unslash($_POST['geweb_ai_search_custom_prompt_name']))
                : '';
            $defaultPrompt = $this->getDefaultPrompt();
            $useDefaultPrompt = ($newPrompt === '' || trim($newPrompt) === trim($defaultPrompt));

            if (($useDefaultPrompt ? '' : $newPrompt) !== $currentPrompt) {
                $this->storePromptHistory($currentPrompt, max(0, $historyLimit - 1));
            }

            if ($useDefaultPrompt) {
                delete_option(self::OPTION_CUSTOM_PROMPT);
                delete_option(self::OPTION_CUSTOM_PROMPT_NAME);
                if (trim($defaultPrompt) !== trim($currentPrompt)) {
                    $this->storeCurrentPromptSnapshot($defaultPrompt, 'Default prompt', $historyLimit);
                }
            } else {
                update_option(self::OPTION_CUSTOM_PROMPT, $newPrompt);
                update_option(self::OPTION_CUSTOM_PROMPT_NAME, $newPromptName);
                if ($newPrompt !== $currentPrompt) {
                    $this->storeCurrentPromptSnapshot($newPrompt, $newPromptName, $historyLimit);
                }
            }
        }

        // Save prompt history names
        if (isset($_POST['geweb_ai_search_prompt_history_names']) && is_array($_POST['geweb_ai_search_prompt_history_names'])) {
            $this->updatePromptHistoryNames(wp_unslash($_POST['geweb_ai_search_prompt_history_names']));
        }

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    /**
     * Render options page
     *
     * @return void
     */
    public function renderOptionsPage(): void {
        $provider = ProviderFactory::make();
        $storeEnabled = !empty($provider->getStoreData());

        $models = $provider->getModels();
        $selectedModel = $provider->getModel();
        if ($selectedModel !== '' && !in_array($selectedModel, $models, true) && $this->supportsFileSearchModel($selectedModel)) {
            array_unshift($models, $selectedModel);
            $models = array_values(array_unique($models));
        }
        $defaultModel = $provider->getDefaultModel($models);
        $modelStatuses = $provider->getModelStatuses();
        $connectionStatus = get_option(self::OPTION_CONNECTION_STATUS, []);
        $hasValidSavedApiKey = is_array($connectionStatus) && (($connectionStatus['status'] ?? '') === 'ok');
        $selectedProvider = ProviderFactory::getConfiguredProviderKey();
        $availableProviders = ProviderFactory::getAvailableProviders();
        $customPrompt = get_option(self::OPTION_CUSTOM_PROMPT, '');
        $customPromptName = get_option(self::OPTION_CUSTOM_PROMPT_NAME, '');
        $defaultPrompt = $provider->getDefaultSystemInstruction();
        $effectivePrompt = method_exists($provider, 'getSystemInstruction') ? $provider->getSystemInstruction() : $defaultPrompt;
        $isUsingDefaultPrompt = trim((string) $customPrompt) === '';
        $promptHistoryLimit = (int) get_option(self::OPTION_PROMPT_HISTORY_LIMIT, self::DEFAULT_PROMPT_HISTORY_LIMIT);
        $promptHistory = get_option(self::OPTION_PROMPT_HISTORY, []);
        if (!is_array($promptHistory)) {
            $promptHistory = [];
        }
        $seenPromptHistoryEntries = [];
        $postTypes = get_option('geweb_aisearch_post_types', []);
        $includeReferencedDocuments = get_option(self::OPTION_INCLUDE_REFERENCED_DOCUMENTS, '0') === '1';
        $preserveDataOnUninstall = get_option(self::OPTION_PRESERVE_DATA_ON_UNINSTALL, '0') === '1';
        $allPostTypes = get_post_types(['public' => true], 'objects');
        $activeTab = isset($_GET['geweb_tab']) ? sanitize_key(wp_unslash($_GET['geweb_tab'])) : 'general';
        if ($activeTab === 'ai') {
            $activeTab = 'prompts';
        }
        if (!in_array($activeTab, ['general', 'prompts', 'documents', 'stores'], true)) {
            $activeTab = 'general';
        }
        $documentStore = new DocumentStore();
        $hasReferencedDocumentCache = $documentStore->hasReferencedDocumentOverviewCache();
        if (!$hasReferencedDocumentCache && $activeTab === 'documents') {
            $documentStore->getReferencedDocumentOverview(true);
            $hasReferencedDocumentCache = $documentStore->hasReferencedDocumentOverviewCache();
        }
        $referencedCacheTime = $documentStore->getReferencedDocumentOverviewCacheTime();
        $referencedDebug = $documentStore->getReferencedDocumentOverviewDebug();
        $providerHasStoreCache = $provider instanceof Gemini ? $provider->hasStoreOverviewCache() : false;
        $providerStoreCacheTime = $provider instanceof Gemini ? $provider->getStoreOverviewCacheTime() : 0;
        $providerStoreError = $provider instanceof Gemini ? $provider->getStoreOverviewError() : '';
        ?>
        <div class="wrap">
            <h1>Geweb AI Search</h1>
            <h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a href="<?php echo esc_url($this->getTabUrl('general')); ?>" class="nav-tab <?php echo $activeTab === 'general' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="general">General</a>
                <a href="<?php echo esc_url($this->getTabUrl('prompts')); ?>" class="nav-tab <?php echo $activeTab === 'prompts' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="prompts">Prompts</a>
                <a href="<?php echo esc_url($this->getTabUrl('documents')); ?>" class="nav-tab <?php echo $activeTab === 'documents' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="documents">Documents</a>
                <a href="<?php echo esc_url($this->getTabUrl('stores')); ?>" class="nav-tab <?php echo $activeTab === 'stores' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="stores">Gemini Stores</a>
            </h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" accept-charset="UTF-8">
                <input type="hidden" name="action" value="geweb_save">
                <input type="hidden" name="geweb_ai_search_referenced_document_targets" id="geweb_ai_search_referenced_document_targets" value="">
                <?php wp_nonce_field('geweb_ai_search_save_settings'); ?>

                <div class="geweb-settings-tab-panel" data-geweb-tab-panel="general" <?php echo $activeTab === 'general' ? '' : 'style="display:none;"'; ?>>
                <table class="form-table">
                    <tr>
                        <th><label for="geweb_ai_search_provider">AI Provider:</label></th>
                        <td>
                            <select name="geweb_ai_search_provider" id="geweb_ai_search_provider" disabled>
                                <?php foreach ($availableProviders as $providerKey => $providerLabel): ?>
                                    <option value="<?php echo esc_attr($providerKey); ?>" <?php selected($selectedProvider, $providerKey); ?>>
                                        <?php echo esc_html($providerLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Provider abstraction is now in place. ChatGPT/OpenAI support is the next step, but only Gemini is wired up right now.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_api_key">API Key:</label></th>
                        <td>
                            <div style="position:absolute; left:-9999px; top:auto; width:1px; height:1px; overflow:hidden;" aria-hidden="true">
                                <input type="text" name="geweb_fake_username" autocomplete="username" tabindex="-1">
                                <input type="password" name="geweb_fake_password" autocomplete="current-password" tabindex="-1">
                            </div>
                            <div style="display:flex; align-items:center; gap:8px; max-width:460px;">
                                <input type="password" id="geweb_api_key" name="geweb_gemini_token" placeholder="<?php echo esc_attr($storeEnabled ? 'API Key is set' : 'Enter API Key'); ?>" class="regular-text" style="flex:1 1 auto;" autocomplete="new-password" autocapitalize="off" autocorrect="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" data-form-type="other" data-current-key-valid="<?php echo $hasValidSavedApiKey ? '1' : '0'; ?>">
                                <button type="button" class="button" id="geweb-toggle-api-key-visibility" aria-label="Show API key" aria-pressed="false" title="Show API key" style="display:none;">
                                    <span class="dashicons dashicons-visibility" aria-hidden="true" style="line-height:1.5;"></span>
                                </button>
                            </div>
                            <p class="description" id="geweb-api-key-replacement-warning" style="display:none; color:#996800;">
                                You are entering a new API key. The currently saved working key will no longer be used after you save settings.
                            </p>
                            <p class="description">Enter a Google AI Studio Gemini API key from <a href="https://aistudio.google.com/app/api-keys" target="_blank">Google AI Studio</a>. Other Google Cloud or Maps API keys will not work here.</p>
                            <?php if (is_array($connectionStatus) && !empty($connectionStatus['status'])): ?>
                                <?php
                                $apiStatus = (string) $connectionStatus['status'];
                                $apiMessage = isset($connectionStatus['message']) ? (string) $connectionStatus['message'] : '';
                                $apiTimestamp = !empty($connectionStatus['timestamp'])
                                    ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($connectionStatus['timestamp']))
                                    : '';
                                $apiColor = $apiStatus === 'ok' ? '#46b450' : ($apiStatus === 'missing' ? '#646970' : '#d63638');
                                $apiLabel = $apiStatus === 'ok' ? 'Valid' : ($apiStatus === 'missing' ? 'Missing' : 'Invalid');
                                ?>
                                <p class="description" style="color: <?php echo esc_attr($apiColor); ?>;">
                                    <strong>API key status:</strong> <?php echo esc_html($apiLabel); ?>
                                    <?php if ($apiTimestamp !== ''): ?>
                                        at <?php echo esc_html($apiTimestamp); ?>
                                    <?php endif; ?>
                                    <?php if ($apiMessage !== ''): ?>
                                        <br><small><?php echo esc_html($apiMessage); ?></small>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_model">Select Model:</label></th>
                        <td>
                            <select name="geweb_ai_search_model" id="geweb_ai_search_model">
                                <?php foreach ($models as $model): ?>
                                    <option value="<?php echo esc_attr($model); ?>" <?php selected($selectedModel, $model); ?>>
                                        <?php echo esc_html($model); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" id="geweb-ai-model-refresh-status" style="display:none;"></p>
                            <p class="description">Current model: <code><?php echo esc_html($selectedModel); ?></code></p>
                            <p class="description">Default model: <code><?php echo esc_html($defaultModel); ?></code></p>
                            <p class="description">The list above is fetched from Gemini when possible and falls back to the bundled defaults if the API is unavailable.</p>
                            <?php if (!empty($modelStatuses)): ?>
                                <div style="margin-top:12px;">
                                    <strong>Model status</strong>
                                    <ul style="margin:8px 0 0 18px;">
                                        <?php foreach ($models as $model): ?>
                                            <?php
                                            $entry = $modelStatuses[$model] ?? null;
                                            if (!is_array($entry) || empty($entry['status'])) {
                                                continue;
                                            }

                                            $status = $entry['status'] === 'failed' ? 'Failed' : 'OK';
                                            $color = $entry['status'] === 'failed' ? '#d63638' : '#46b450';
                                            $timestamp = !empty($entry['timestamp'])
                                                ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), intval($entry['timestamp']))
                                                : '';
                                            $message = isset($entry['message']) ? (string) $entry['message'] : '';
                                            ?>
                                            <li style="color:<?php echo esc_attr($color); ?>;">
                                                <code><?php echo esc_html($model); ?></code>
                                                <?php echo esc_html($status); ?>
                                                <?php if ($timestamp !== ''): ?>
                                                    at <?php echo esc_html($timestamp); ?>
                                                <?php endif; ?>
                                                <?php if ($message !== ''): ?>
                                                    <br><small style="color:#50575e;"><?php echo esc_html($message); ?></small>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th>Select Post Types for AI Search:</th>
                        <td>
                            <?php foreach ($allPostTypes as $postType): ?>
                            <p>
                                <label>
                                    <input type="checkbox" name="geweb_ai_search_post_types[]" value="<?php echo esc_attr($postType->name); ?>"
                                        <?php checked(in_array($postType->name, $postTypes), true); ?>>
                                    <?php echo esc_html($postType->label); ?>
                                </label>
                            </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_include_referenced_documents">Referenced Documents:</label></th>
                        <td>
                            <label for="geweb_ai_search_include_referenced_documents">
                                <input
                                    type="checkbox"
                                    id="geweb_ai_search_include_referenced_documents"
                                    name="geweb_ai_search_include_referenced_documents"
                                    value="1"
                                    <?php checked($includeReferencedDocuments); ?>
                                >
                                Upload referenced local documents together with indexed pages
                            </label>
                            <p class="description">When enabled, files linked from post content in the WordPress uploads folder are uploaded to the Gemini store as separate documents. When disabled, linked files are detected but not uploaded.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_preserve_data_on_uninstall">Uninstall Cleanup:</label></th>
                        <td>
                            <label for="geweb_ai_search_preserve_data_on_uninstall">
                                <input
                                    type="checkbox"
                                    id="geweb_ai_search_preserve_data_on_uninstall"
                                    name="geweb_ai_search_preserve_data_on_uninstall"
                                    value="1"
                                    <?php checked($preserveDataOnUninstall); ?>
                                >
                                Keep plugin data when uninstalling
                            </label>
                            <p class="description">When enabled, uninstalling the plugin keeps its settings, status metadata, and indexed document tables in the WordPress database. The stored API key and encryption key are always removed on uninstall.</p>
                        </td>
                    </tr>

                    <?php if($storeEnabled): ?>
                        <tr>
                            <th>File Store Created:</th>
                            <td>
                                <label for="geweb_ai_search_create_store">
                                    <input type="checkbox" id="geweb_ai_search_create_store" name="geweb_ai_search_create_store" value="1" />
                                    Create a New Store
                                </label>
                                <p class="description">Warning: Recreating the store will delete all indexed documents. You'll need to regenerate the library.</p>
                            </td>
                        </tr>
                        <?php
                            if (!empty($postTypes)) {
                                HTML2MD::renderButton();
                            }
                        endif;
                    ?>
                </table>
                </div>

                <div class="geweb-settings-tab-panel" data-geweb-tab-panel="prompts" <?php echo $activeTab === 'prompts' ? '' : 'style="display:none;"'; ?>>
                <table class="form-table">
                    <tr>
                        <th><label for="geweb_ai_search_custom_prompt">AI Prompt:</label></th>
                        <td>
                            <p class="description" style="margin-top:0;">
                                <strong>Currently using:</strong>
                                <?php echo esc_html($isUsingDefaultPrompt ? 'Built-in default prompt' : ($customPromptName !== '' ? $customPromptName : 'Custom prompt')); ?>
                            </p>
                            <p style="margin-top:0;">
                                <label for="geweb_ai_search_custom_prompt_name"><strong>Prompt name</strong></label><br>
                                <input
                                    type="text"
                                    id="geweb_ai_search_custom_prompt_name"
                                    name="geweb_ai_search_custom_prompt_name"
                                    value="<?php echo esc_attr((string) $customPromptName); ?>"
                                    class="regular-text"
                                    placeholder="Optional name for this prompt version"
                                >
                            </p>
                            <textarea
                                id="geweb_ai_search_custom_prompt"
                                name="geweb_ai_search_custom_prompt"
                                rows="10"
                                class="large-text code"
                                placeholder="Enter the AI prompt used for Gemini requests."
                            ><?php echo esc_textarea($customPrompt); ?></textarea>
                            <textarea id="geweb_ai_search_default_prompt" style="display:none;" readonly><?php echo esc_textarea($defaultPrompt); ?></textarea>
                            <p>
                                <button
                                    type="button"
                                    class="button"
                                    id="geweb-ai-restore-default-prompt"
                                    data-default-prompt="<?php echo esc_attr(base64_encode($defaultPrompt)); ?>"
                                >Restore default prompt</button>
                            </p>
                            <p class="description">If this field is empty, the built-in default prompt is used. You can fully replace it here and restore the default at any time.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_prompt_history_limit">Prompt History:</label></th>
                        <td>
                            <input
                                type="number"
                                id="geweb_ai_search_prompt_history_limit"
                                name="geweb_ai_search_prompt_history_limit"
                                min="1"
                                step="1"
                                value="<?php echo esc_attr((string) $promptHistoryLimit); ?>"
                                class="small-text"
                            >
                            <p class="description">Number of prompt versions to keep. Default: <?php echo esc_html((string) self::DEFAULT_PROMPT_HISTORY_LIMIT); ?>. Names are saved when you save settings.</p>
                            <?php if (!empty($promptHistory)): ?>
                                <div id="geweb-ai-prompt-history-list" style="margin-top: 12px; display: flex; flex-direction: column; gap: 8px; max-width: 800px;">
                                    <?php foreach ($promptHistory as $entry): ?>
                                        <?php
                                        $saved_at = intval($entry['saved_at'] ?? 0);
                                        if ($saved_at === 0) {
                                            continue;
                                        }
                                        $entryPrompt = (string) ($entry['prompt'] ?? '');
                                        $isDefaultPrompt = trim($entryPrompt) === trim($defaultPrompt);
                                        $normalizedEntryPrompt = trim($entryPrompt);
                                        $isFirstOccurrence = !isset($seenPromptHistoryEntries[$normalizedEntryPrompt]);
                                        $seenPromptHistoryEntries[$normalizedEntryPrompt] = true;
                                        $canRename = !$isDefaultPrompt && $isFirstOccurrence;
                                        $name = (string) ($entry['name'] ?? '');
                                        if ($isDefaultPrompt) {
                                            $name = 'Default prompt';
                                        } elseif ($name === '') {
                                            $name = 'Version from ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), $saved_at);
                                        }
                                        $prompt_b64 = base64_encode($entryPrompt);
                                        ?>
                                        <div class="geweb-ai-prompt-history-item" data-timestamp="<?php echo esc_attr($saved_at); ?>" data-prompt="<?php echo esc_attr($prompt_b64); ?>" data-is-default="<?php echo $isDefaultPrompt ? '1' : '0'; ?>" data-can-rename="<?php echo $canRename ? '1' : '0'; ?>" style="padding: 8px; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer;">
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <div style="flex-grow: 1; margin-right: 10px;">
                                                    <span class="geweb-ai-prompt-history-name-label"><?php echo esc_html($name); ?></span>
                                                    <?php if ($canRename): ?>
                                                        <input
                                                            type="text"
                                                            name="geweb_ai_search_prompt_history_names[<?php echo esc_attr($saved_at); ?>]"
                                                            value="<?php echo esc_attr($name); ?>"
                                                            class="regular-text geweb-ai-prompt-history-name-input"
                                                            style="display:none; width:100%;"
                                                        />
                                                    <?php endif; ?>
                                                </div>
                                                <span style="color: #646970; white-space: nowrap; margin-right: 10px;"><?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), $saved_at); ?></span>
                                                <?php if ($canRename): ?>
                                                    <button type="button" class="button geweb-ai-rename-history-prompt">Rename</button>
                                                <?php endif; ?>
                                                <button type="button" class="button geweb-ai-use-history-prompt">Use</button>
                                                <button type="button" class="button-link button-link-delete geweb-ai-delete-history-prompt" style="margin-left: 5px;" title="Delete"><span class="dashicons dashicons-trash"></span></button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="geweb-ai-clear-history" style="margin-top: 12px;">Clear All History</button>
                                <div style="margin-top:12px; max-width: 800px;">
                                    <p class="description" style="margin:0 0 8px 0;">Select a saved prompt version to compare it with the current AI Prompt. The newest previous version is shown automatically.</p>
                                    <pre
                                        id="geweb-ai-prompt-history-diff"
                                        style="margin-top:8px; padding:12px; background:#fff; border:1px solid #dcdcde; min-height:20em; max-height:none; overflow:auto; white-space:pre-wrap;"
                                    >Select a saved prompt version to compare it with the current AI Prompt.</pre>
                                </div>
                            <?php else: ?>
                                <p class="description">No previous prompts saved yet.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                </div>
                <p class="submit">
                    <input type="submit" id="geweb-save-settings" class="button-primary" value="Save Settings" disabled>
                </p>
            </form>

            <div class="geweb-settings-tab-panel" data-geweb-tab-panel="documents" <?php echo $activeTab === 'documents' ? '' : 'style="display:none;"'; ?>>
                <p class="description" style="margin-top:0; max-width: 900px;">
                    This table shows local files found in managed content, whether they have been uploaded to the Gemini store, and any uploaded documents that are no longer referenced.
                </p>
                <?php if (is_array($connectionStatus) && !empty($connectionStatus['status'])): ?>
                    <?php
                    $apiStatus = (string) $connectionStatus['status'];
                    $apiMessage = isset($connectionStatus['message']) ? (string) $connectionStatus['message'] : '';
                    $apiColor = $apiStatus === 'ok' ? '#46b450' : ($apiStatus === 'missing' ? '#646970' : '#d63638');
                    $apiLabel = $apiStatus === 'ok' ? 'Valid' : ($apiStatus === 'missing' ? 'Missing' : 'Invalid');
                    ?>
                    <p class="description" style="color: <?php echo esc_attr($apiColor); ?>;">
                        <strong>API key status:</strong> <?php echo esc_html($apiLabel); ?>
                        <?php if ($apiMessage !== ''): ?>
                            <br><small><?php echo esc_html($apiMessage); ?></small>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <p>
                    <button type="button" class="button" id="geweb-refresh-referenced-documents" <?php disabled(!$hasReferencedDocumentCache); ?>>Refresh List</button>
                    <span id="geweb-referenced-documents-status" style="margin-left:10px; color:#646970;">
                        <?php if ($referencedCacheTime > 0): ?>
                            Showing cached list. Last refreshed: <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $referencedCacheTime)); ?>
                        <?php else: ?>
                            Loading referenced documents...
                        <?php endif; ?>
                    </span>
                </p>
                <?php if (!empty($referencedDebug)): ?>
                    <p class="description">
                        Scanned posts: <?php echo esc_html((string) ($referencedDebug['managed_posts'] ?? 0)); ?>,
                        posts with document links: <?php echo esc_html((string) ($referencedDebug['posts_with_document_links'] ?? 0)); ?>,
                        accepted document references: <?php echo esc_html((string) ($referencedDebug['accepted_documents'] ?? 0)); ?>
                        <?php if (!empty($referencedDebug['using_all_public_post_types'])): ?>
                            <br><small>No post types are configured yet, so this overview is scanning all public post types.</small>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <div id="geweb-referenced-documents-container" data-needs-refresh="<?php echo $hasReferencedDocumentCache ? '0' : '1'; ?>" style="margin-top:16px;">
                    <?php if ($hasReferencedDocumentCache): ?>
                        <?php $this->renderReferencedDocumentsTable(); ?>
                    <?php else: ?>
                        <p>Referenced documents could not be loaded yet. Use Refresh List to try again.</p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="geweb-settings-tab-panel" data-geweb-tab-panel="stores" <?php echo $activeTab === 'stores' ? '' : 'style="display:none;"'; ?>>
                <p class="description" style="margin-top:0; max-width: 900px;">
                    This table shows all Gemini File Search Stores visible to the configured API key, marks the one used by this plugin, and helps spot likely orphaned stores.
                </p>
                <?php if (!$provider instanceof Gemini): ?>
                    <p>This tab is only available when the Gemini provider is active.</p>
                <?php else: ?>
                    <p>
                        <button type="button" class="button" id="geweb-refresh-gemini-stores" <?php disabled(!$providerHasStoreCache); ?>>Refresh List</button>
                        <span id="geweb-gemini-stores-status" style="margin-left:10px; color:#646970;">
                            <?php if ($providerStoreCacheTime > 0): ?>
                                Last refreshed: <?php echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $providerStoreCacheTime)); ?>
                            <?php else: ?>
                                Loading Gemini stores...
                            <?php endif; ?>
                        </span>
                    </p>
                    <p id="geweb-gemini-stores-error" class="description" style="color:#d63638;<?php echo $providerStoreError !== '' ? '' : ' display:none;'; ?>"><?php echo esc_html($providerStoreError); ?></p>
                    <div id="geweb-gemini-stores-container" data-needs-refresh="<?php echo $providerHasStoreCache ? '0' : '1'; ?>" style="margin-top:16px;">
                        <?php if ($providerHasStoreCache): ?>
                            <?php $this->renderGeminiStoresTable(); ?>
                        <?php else: ?>
                            <p>Loading Gemini stores for the first time. This can take a moment if multiple stores need to be checked.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Add settings link on the plugins screen
     *
     * @param array $links Existing action links
     * @return array
     */
    public function addPluginActionLinks(array $links): array {
        $settingsLink = '<a href="' . esc_url(admin_url('admin.php?page=geweb-ai-search')) . '">Settings</a>';
        array_unshift($links, $settingsLink);

        return $links;
    }

    /**
     * Store previous prompts for later restore
     *
     * @param string $prompt Prompt text
     * @param int $limit Maximum number of entries
     * @return void
     */
    private function storePromptHistory(string $prompt, int $limit): void {
        $this->storePromptHistoryEntry($prompt, '', $limit);
    }

    /**
     * Normalize prompt text from the browser without flattening valid UTF-8 punctuation.
     *
     * @param mixed $rawPrompt
     * @return string
     */
    private function normalizePromptInput($rawPrompt): string {
        if (!is_string($rawPrompt)) {
            return '';
        }

        $prompt = wp_unslash($rawPrompt);
        $prompt = wp_check_invalid_utf8($prompt, true);
        $prompt = str_replace(["\r\n", "\r"], "\n", $prompt);
        $prompt = str_replace("\0", '', $prompt);

        return trim($prompt);
    }

    /**
     * Determine whether a model should appear in the File Search model selector.
     *
     * @param string $model
     * @return bool
     */
    private function supportsFileSearchModel(string $model): bool {
        foreach ([
            'gemini-3-pro-preview',
            'gemini-3-flash-preview',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
        ] as $prefix) {
            if (strpos($model, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store or refresh the current prompt snapshot in history with an optional name.
     *
     * @param string $prompt
     * @param string $name
     * @param int $limit
     * @return void
     */
    private function storeCurrentPromptSnapshot(string $prompt, string $name, int $limit): void {
        $this->storePromptHistoryEntry($prompt, $name, $limit);
    }

    /**
     * Store a prompt history entry.
     *
     * @param string $prompt
     * @param string $name
     * @param int $limit
     * @return void
     */
    private function storePromptHistoryEntry(string $prompt, string $name, int $limit): void {
        $prompt = trim($prompt);
        if ($prompt === '' || $limit <= 0) {
            return;
        }

        if ($prompt === trim($this->getDefaultPrompt())) {
            $name = 'Default prompt';
        }

        $history = get_option(self::OPTION_PROMPT_HISTORY, []);
        if (!is_array($history)) {
            $history = [];
        }

        array_unshift($history, [
            'prompt' => $prompt,
            'saved_at' => current_time('timestamp'),
            'name' => $name,
        ]);

        $uniqueHistory = [];
        $seen = [];
        foreach ($history as $entry) {
            $entryPrompt = isset($entry['prompt']) ? trim((string) $entry['prompt']) : '';
            if ($entryPrompt === '' || isset($seen[$entryPrompt])) {
                continue;
            }

            $seen[$entryPrompt] = true;
            $uniqueHistory[] = [
                'prompt' => $entryPrompt,
                'saved_at' => intval($entry['saved_at'] ?? current_time('timestamp')),
                'name' => sanitize_text_field((string) ($entry['name'] ?? '')),
            ];

            if (count($uniqueHistory) >= $limit) {
                break;
            }
        }

        update_option(self::OPTION_PROMPT_HISTORY, $uniqueHistory);
    }

    /**
     * Update names for prompt history entries
     *
     * @param array<int,string> $names Array of timestamp => name
     * @return void
     */
    private function updatePromptHistoryNames(array $names): void {
        $history = get_option(self::OPTION_PROMPT_HISTORY, []);
        if (!is_array($history) || empty($history)) {
            return;
        }

        $newHistory = [];
        $seenPrompts = [];
        foreach ($history as $entry) {
            $saved_at = (int) ($entry['saved_at'] ?? 0);
            $entryPrompt = isset($entry['prompt']) ? trim((string) $entry['prompt']) : '';
            if ($entryPrompt !== '' && $entryPrompt === trim($this->getDefaultPrompt())) {
                $entry['name'] = 'Default prompt';
                $newHistory[] = $entry;
                $seenPrompts[$entryPrompt] = true;
                continue;
            }
            if (!isset($seenPrompts[$entryPrompt]) && isset($names[$saved_at])) {
                $entry['name'] = sanitize_text_field($names[$saved_at]);
            }
            $newHistory[] = $entry;
            if ($entryPrompt !== '') {
                $seenPrompts[$entryPrompt] = true;
            }
        }

        if ($newHistory !== $history) {
            update_option(self::OPTION_PROMPT_HISTORY, $newHistory);
        }
    }

    /**
     * @return string
     */
    private function getDefaultPrompt(): string {
        return ProviderFactory::make()->getDefaultSystemInstruction();
    }

    /**
     * Trim stored prompt history to the configured limit
     *
     * @param int $limit Maximum number of entries
     * @return void
     */
    private function trimPromptHistory(int $limit): void {
        $history = get_option(self::OPTION_PROMPT_HISTORY, []);
        if (!is_array($history)) {
            update_option(self::OPTION_PROMPT_HISTORY, []);
            return;
        }

        update_option(self::OPTION_PROMPT_HISTORY, array_slice($history, 0, max(0, $limit)));
    }

    /**
     * AJAX: Clear saved prompt history immediately
     *
     * @return void
     */
    public function ajaxClearPromptHistory(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        update_option(self::OPTION_PROMPT_HISTORY, []);

        wp_send_json_success([
            'message' => 'Prompt history cleared.',
        ]);
    }

    /**
     * AJAX: Delete a single prompt history item
     *
     * @return void
     */
    public function ajaxDeletePromptHistoryItem(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions'], 403);
        }

        $timestamp = isset($_POST['timestamp']) ? (int) $_POST['timestamp'] : 0;
        if ($timestamp <= 0) {
            wp_send_json_error(['message' => 'Invalid timestamp.'], 400);
        }

        $history = get_option(self::OPTION_PROMPT_HISTORY, []);
        if (!is_array($history)) {
            wp_send_json_success(['message' => 'History is already empty.']);
            return;
        }

        $newHistory = [];
        $found = false;
        foreach ($history as $entry) {
            if (isset($entry['saved_at']) && (int) $entry['saved_at'] === $timestamp) {
                $found = true;
                continue;
            }
            $newHistory[] = $entry;
        }

        if (!$found) {
            wp_send_json_error(['message' => 'Prompt version not found.'], 404);
        }

        update_option(self::OPTION_PROMPT_HISTORY, $newHistory);

        wp_send_json_success(['message' => 'Prompt version deleted.']);
    }

    /**
     * AJAX: Standard WordPress search (autocomplete)
     *
     * @return void
     */
    public function ajaxSearch(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $query = sanitize_text_field(wp_unslash($_POST['query'] ?? ''));
        $query_len = strlen($query);

        if ($query_len > 50 || $query_len < 3) {
            wp_send_json_error();
        }

        $results = [];

        $wpQuery = new \WP_Query([
            'post_type' => get_option('geweb_aisearch_post_types', ['post']),
            'posts_per_page' => 10,
            's' => $query
        ]);
        if ($wpQuery->have_posts()) {
            while ($wpQuery->have_posts()) {
                $wpQuery->the_post();
                $results[] = [
                    'url' => get_permalink(get_the_ID()),
                    'title' => get_the_title()
                ];
            }
        }
        wp_reset_postdata();

        wp_send_json_success($results);
    }

    /**
     * AJAX: AI-powered search
     *
     * @return void
     */
    public function ajaxAiChat(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each array element is sanitized in the foreach loop below
        $rawMessages = isset($_POST['messages']) && is_array($_POST['messages']) ? wp_unslash($_POST['messages']) : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (empty($rawMessages)) {
            wp_send_json_error(['message' => 'No messages provided']);
        }

        $allowedRoles = ['user', 'model'];
        $messages = [];
        foreach ($rawMessages as $rawMessage) {
            if (!is_array($rawMessage)) {
                continue;
            }

            $role = isset($rawMessage['role']) ? sanitize_text_field($rawMessage['role']) : '';
            $content = isset($rawMessage['content']) ? sanitize_textarea_field($rawMessage['content']) : '';

            if (!in_array($role, $allowedRoles, true)) {
                $role = 'user';
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        try {
            $provider = ProviderFactory::make();
            $result = $provider->search($messages);

            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX: Get fresh nonce (for cache compatibility)
     *
     * @return void
     */
    public function ajaxGetNonce(): void {
        wp_send_json_success([
            'nonce' => wp_create_nonce('geweb_ai_search_search')
        ]);
    }

    /**
     * Enqueue frontend scripts and styles
     *
     * @return void
     */
    public function enqueueScripts(): void {
        wp_enqueue_script(
            'geweb-ai-search',
            GEWEB_AI_SEARCH_URL . 'assets/script.js',
            ['jquery'],
            GEWEB_AI_SEARCH_VERSION,
            true
        );

        wp_enqueue_style(
            'geweb-ai-search',
            GEWEB_AI_SEARCH_URL . 'assets/styles.css',
            [],
            GEWEB_AI_SEARCH_VERSION
        );

        wp_localize_script('geweb-ai-search', 'geweb_aisearch', [
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
    }

    /**
     * Enqueue backtend scripts and styles
     *
     * @return void
     */
    public function enqueueAdminScripts(): void {
        wp_enqueue_script(
            'geweb-ai-search-admin',
            GEWEB_AI_SEARCH_URL . 'assets/admin.js',
            ['jquery'],
            GEWEB_AI_SEARCH_VERSION,
            true
        );

        wp_localize_script('geweb-ai-search-admin', 'gewebAisearchAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'generateLibraryNonce' => wp_create_nonce('geweb_ai_search_generate_library'),
            'adminActionNonce' => wp_create_nonce('geweb_ai_search_admin_actions'),
        ]);
    }

    /**
     * Render modal windows in footer
     *
     * @return void
     */
    public function renderModals(): void {
        ?>
        <dialog id="geweb-ai-modal" class="geweb-aisearch-modal-window">
            <div class="modal-header">
                <strong class="ai-assistant-title"><?php echo esc_html(apply_filters('geweb_aisearch_ai_modal_title', 'AI Assistant')); ?></strong>
                <div class="close"></div>
            </div>
            <div class="answer-box"></div>
            <div class="question-box">
                <textarea id="geweb-ai-query-display" placeholder="<?php echo esc_attr(apply_filters('geweb_aisearch_ai_textarea_placeholder', 'Ask AI a question...')); ?>"></textarea>
                <button id="geweb-ask-ai-submit" class="btn" type="submit" disabled></button>
            </div>
        </dialog>
        <?php
    }
}
?>
