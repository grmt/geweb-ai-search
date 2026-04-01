<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * WordPress integration class
 *
 * Handles admin interface, AJAX endpoints, and WordPress hooks
 */
class WP {
    private const OPTION_REWRITE_VERSION = 'geweb_aisearch_rewrite_version';
    private const FRONTEND_AI_REWRITE_SLUG = 'ai-search-workspace';
    private const FRONTEND_AI_REWRITE_VERSION = '2';
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private ConversationManager $conversationManager;
    private AdminPageSections $adminPageSections;
    private FrontendAiWorkspaceController $frontendAiWorkspaceController;
    private FrontendAiPromptManager $frontendAiPromptManager;

    /**
     * Constructor - registers WordPress hooks
     */
    public function __construct() {
        $this->conversationManager = new ConversationManager();
        $this->adminPageSections = new AdminPageSections($this->conversationManager);
        $this->frontendAiPromptManager = new FrontendAiPromptManager();
        $this->frontendAiWorkspaceController = new FrontendAiWorkspaceController(
            fn(string $tab): string => $this->getTabUrl($tab),
            fn(AIProviderInterface $provider): array => $this->frontendAiPromptManager->getFrontendAiChatModelConfig($provider),
            fn(AIProviderInterface $provider, array $models, string $selectedModel): array => $this->frontendAiPromptManager->getFrontendPromptDescriptors($provider, $models, $selectedModel)
        );
        $conversationAjaxController = new ConversationAjaxController($this->conversationManager);
        $promptHistoryAjaxController = new PromptHistoryAjaxController();
        $sourceReferenceAjaxController = new SourceReferenceAjaxController(new ManagedSourceReferenceResolver());
        $adminDataAjaxController = new AdminDataAjaxController($this->adminPageSections);

        add_action('admin_menu', [$this, 'adminMenu']);
        add_action('admin_post_geweb_save', [$this, 'saveSettings']);
        add_filter('plugin_action_links_' . plugin_basename(GEWEB_AI_SEARCH_PATH . 'geweb-ai-search.php'), [$this, 'addPluginActionLinks']);

        add_action('wp_ajax_geweb_search', [$this, 'ajaxSearch']);
        add_action('wp_ajax_nopriv_geweb_search', [$this, 'ajaxSearch']);

        add_action('wp_ajax_geweb_ai_chat', [$this, 'ajaxAiChat']);
        add_action('wp_ajax_nopriv_geweb_ai_chat', [$this, 'ajaxAiChat']);
        add_action('wp_ajax_geweb_get_frontend_conversations', [$conversationAjaxController, 'ajaxGetFrontendConversations']);
        add_action('wp_ajax_nopriv_geweb_get_frontend_conversations', [$conversationAjaxController, 'ajaxGetFrontendConversations']);
        add_action('wp_ajax_geweb_get_frontend_conversation', [$conversationAjaxController, 'ajaxGetFrontendConversation']);
        add_action('wp_ajax_nopriv_geweb_get_frontend_conversation', [$conversationAjaxController, 'ajaxGetFrontendConversation']);
        add_action('wp_ajax_geweb_resolve_source_references', [$sourceReferenceAjaxController, 'ajaxResolveSourceReferences']);
        add_action('wp_ajax_nopriv_geweb_resolve_source_references', [$sourceReferenceAjaxController, 'ajaxResolveSourceReferences']);
        add_action('wp_ajax_geweb_frontend_rename_conversation', [$conversationAjaxController, 'ajaxFrontendRenameConversation']);
        add_action('wp_ajax_nopriv_geweb_frontend_rename_conversation', [$conversationAjaxController, 'ajaxFrontendRenameConversation']);
        add_action('wp_ajax_geweb_frontend_delete_conversation', [$conversationAjaxController, 'ajaxFrontendDeleteConversation']);
        add_action('wp_ajax_nopriv_geweb_frontend_delete_conversation', [$conversationAjaxController, 'ajaxFrontendDeleteConversation']);
        add_action('wp_ajax_geweb_clear_prompt_history', [$promptHistoryAjaxController, 'ajaxClearPromptHistory']);
        add_action('wp_ajax_geweb_delete_prompt_history_item', [$promptHistoryAjaxController, 'ajaxDeletePromptHistoryItem']);
        add_action('wp_ajax_geweb_render_prompt_diff', [$promptHistoryAjaxController, 'ajaxRenderPromptDiff']);
        add_action('wp_ajax_geweb_rename_conversation', [$conversationAjaxController, 'ajaxRenameConversation']);
        add_action('wp_ajax_geweb_delete_conversation', [$conversationAjaxController, 'ajaxDeleteConversation']);
        add_action('wp_ajax_geweb_refresh_referenced_documents', [$adminDataAjaxController, 'ajaxRefreshReferencedDocuments']);
        add_action('wp_ajax_geweb_update_referenced_document', [$adminDataAjaxController, 'ajaxUpdateReferencedDocument']);
        add_action('wp_ajax_geweb_toggle_referenced_document_exclude', [$adminDataAjaxController, 'ajaxToggleReferencedDocumentExclude']);
        add_action('wp_ajax_geweb_update_referenced_document_nice_name', [$adminDataAjaxController, 'ajaxUpdateReferencedDocumentNiceName']);
        add_action('wp_ajax_geweb_refresh_gemini_stores', [$adminDataAjaxController, 'ajaxRefreshGeminiStores']);
        add_action('wp_ajax_geweb_refresh_gemini_store_documents', [$adminDataAjaxController, 'ajaxRefreshGeminiStoreDocuments']);
        add_action('wp_ajax_geweb_delete_gemini_store', [$adminDataAjaxController, 'ajaxDeleteGeminiStore']);
        add_action('wp_ajax_geweb_refresh_models', [$adminDataAjaxController, 'ajaxRefreshModels']);

        add_action('wp_ajax_geweb_get_nonce', [$this, 'ajaxGetNonce']);
        add_action('wp_ajax_nopriv_geweb_get_nonce', [$this, 'ajaxGetNonce']);

        add_action('init', [self::class, 'registerFrontendAiRewrite']);
        add_action('init', [self::class, 'maybeRefreshFrontendAiRewrite'], 20);
        add_filter('query_vars', [$this, 'registerFrontendAiQueryVars']);
        add_action('wp_enqueue_scripts', [$this->frontendAiWorkspaceController, 'enqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
        add_action('template_redirect', [$this->frontendAiWorkspaceController, 'maybeRenderFrontendAiPage']);
        add_action('admin_bar_menu', [$this->frontendAiWorkspaceController, 'maybeRemoveFrontendAdminBarSearch'], 999);
        add_shortcode('geweb_ai_search', [$this->frontendAiWorkspaceController, 'renderFrontendAiSearchShortcode']);

        add_action('wp_footer', [$this->frontendAiWorkspaceController, 'renderModals']);
    }

    /**
     * Register the dedicated frontend AI workspace rewrite.
     *
     * @return void
     */
    public static function registerFrontendAiRewrite(): void {
        add_rewrite_rule(
            '^' . preg_quote(self::FRONTEND_AI_REWRITE_SLUG, '/') . '/?$',
            'index.php?geweb_ai_page=1',
            'top'
        );
    }

    /**
     * Refresh rewrite rules once after changing the dedicated AI route so the normal page slug wins again.
     *
     * @return void
     */
    public static function maybeRefreshFrontendAiRewrite(): void {
        $storedVersion = (string) get_option(self::OPTION_REWRITE_VERSION, '');
        if ($storedVersion === self::FRONTEND_AI_REWRITE_VERSION) {
            return;
        }

        update_option(self::OPTION_REWRITE_VERSION, self::FRONTEND_AI_REWRITE_VERSION, false);
        flush_rewrite_rules(false);
    }

    /**
     * @param array<int,string> $queryVars
     * @return array<int,string>
     */
    public function registerFrontendAiQueryVars(array $queryVars): array {
        $queryVars[] = 'geweb_ai_page';
        $queryVars[] = 'geweb_ai_conversation';
        return array_values(array_unique($queryVars));
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

        add_submenu_page(
            'geweb-ai-search',
            'Conversations',
            'Conversations',
            'manage_options',
            'geweb-ai-search&geweb_tab=conversations',
            [$this, 'renderOptionsPage']
        );
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
     * Ensure the default AI Search page exists and is excluded from indexing.
     *
     * @return int
     */
    public static function ensureFrontendAiPageExists(): int {
        return FrontendAiPageManager::ensureFrontendAiPageExists();
    }

    /**
     * Save plugin settings
     *
     * @return void
     */
    public function saveSettings(): void {
        check_admin_referer('geweb_ai_search_save_settings');

        if (!current_user_can('manage_options')) {
            wp_die(self::MESSAGE_INSUFFICIENT_PERMISSIONS);
        }

        $settingsManager = new AdminSettingsManager();
        $settingsManager->save();

        wp_safe_redirect(wp_get_referer());
        exit;
    }

    /**
     * Render options page
     *
     * @return void
     */
    public function renderOptionsPage(): void {
        $builder = new AdminPageConfigBuilder(
            fn(string $tab): string => $this->getTabUrl($tab),
            function (): void {
                $this->adminPageSections->renderReferencedDocumentsTable();
            },
            function (): void {
                $this->adminPageSections->renderGeminiStoresTable();
            },
            fn(array $storeOverview): array => $this->adminPageSections->getInitialGeminiStoreSelection($storeOverview),
            function (string $storeName, string $storeLabel, array $documents): void {
                $this->adminPageSections->renderGeminiStoreDocumentsPanel($storeName, $storeLabel, $documents);
            },
            function (): void {
                $this->adminPageSections->renderConversationsTable();
            },
            fn(string $model): bool => $this->adminPageSections->supportsFileSearchModel($model)
        );
        $renderer = new AdminPageRenderer();
        $renderer->render($builder->build());
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
        $conversationId = isset($_POST['conversation_id']) ? FrontendAiContext::sanitizeConversationId(wp_unslash($_POST['conversation_id'])) : '';
        $requestedModel = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $temporaryPrompt = isset($_POST['temporary_prompt']) ? PromptSupport::normalizePromptInput($_POST['temporary_prompt']) : '';

        if (empty($rawMessages)) {
            wp_send_json_error(['message' => 'No messages provided']);
        }

        $messages = $this->normalizeAjaxChatMessages($rawMessages);

        try {
            $provider = ProviderFactory::make();
            $selectedModel = $this->resolveRequestedModel($provider, $requestedModel);

            $latestUserMessage = $this->conversationManager->extractLatestUserMessage($messages);
            $fullMessages = $this->conversationManager->buildFullConversationMessages($conversationId, $messages, $latestUserMessage);
            $context = $this->conversationManager->compactConversationForRequest($fullMessages);

            $result = $provider->search($context['messages'], $selectedModel, $temporaryPrompt !== '' ? $temporaryPrompt : null);
            $this->appendAiResponseToConversation($fullMessages, $result);

            $this->conversationManager->recordConversationUsage($conversationId, $fullMessages, $context['summary'], $result, $provider);

            $result['context_compacted'] = !empty($context['compacted']);
            wp_send_json_success($result);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * @param array<int,mixed> $rawMessages
     * @return array<int,array{role:string,content:string}>
     */
    private function normalizeAjaxChatMessages(array $rawMessages): array {
        $allowedRoles = ['user', 'model'];
        $messages = [];

        foreach ($rawMessages as $rawMessage) {
            if (!is_array($rawMessage)) {
                continue;
            }

            $role = isset($rawMessage['role']) ? sanitize_text_field($rawMessage['role']) : '';
            $content = isset($rawMessage['content']) ? sanitize_textarea_field($rawMessage['content']) : '';
            if ($content === '') {
                continue;
            }

            if (!in_array($role, $allowedRoles, true)) {
                $role = 'user';
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * @param AIProviderInterface $provider
     * @param string $requestedModel
     * @return string
     */
    private function resolveRequestedModel(AIProviderInterface $provider, string $requestedModel): string {
        $availableModels = $provider->getModels();
        return in_array($requestedModel, $availableModels, true) ? $requestedModel : $provider->getModel();
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed> $result
     * @return void
     */
    private function appendAiResponseToConversation(array &$messages, array $result): void {
        $answer = isset($result['answer']) ? (string) $result['answer'] : '';
        $answerText = wp_strip_all_tags($answer);
        if ($answerText === '') {
            return;
        }

        $messages[] = [
            'role' => 'model',
            'content' => $answer,
            'sources' => isset($result['sources']) && is_array($result['sources']) ? $result['sources'] : [],
            'meta' => isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [],
        ];
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

}
