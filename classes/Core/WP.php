<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * WordPress integration class
 *
 * Handles admin interface, AJAX endpoints, and WordPress hooks
 */
class WP {
    use WPAjaxTrait;

    private const OPTION_REWRITE_VERSION = 'geweb_aisearch_rewrite_version';
    private const FRONTEND_AI_REWRITE_SLUG = 'ai-search-workspace';
    private const FRONTEND_AI_REWRITE_VERSION = '2';
    private const ADMIN_PAGE_PATH = 'admin.php?page=geweb-ai-search';
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private ConversationManager $conversationManager;
    private AiChatJobStore $aiChatJobStore;
    private AiChatJobProcessor $aiChatJobProcessor;
    private AdminPageSections $adminPageSections;
    private FrontendAiWorkspaceController $frontendAiWorkspaceController;
    private FrontendAiPromptManager $frontendAiPromptManager;

    /**
     * Constructor - registers WordPress hooks
     */
    public function __construct() {
        UserScopeMigration::maybeRun();
        $this->conversationManager = new ConversationManager();
        $this->aiChatJobStore = new AiChatJobStore();
        $this->aiChatJobProcessor = new AiChatJobProcessor($this->conversationManager, $this->aiChatJobStore);
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
        add_action('wp_ajax_geweb_get_ai_chat_job', [$this, 'ajaxGetAiChatJob']);
        add_action('wp_ajax_nopriv_geweb_get_ai_chat_job', [$this, 'ajaxGetAiChatJob']);
        add_action('wp_ajax_geweb_get_frontend_conversations', [$conversationAjaxController, 'ajaxGetFrontendConversations']);
        add_action('wp_ajax_nopriv_geweb_get_frontend_conversations', [$conversationAjaxController, 'ajaxGetFrontendConversations']);
        add_action('wp_ajax_geweb_get_frontend_conversation', [$conversationAjaxController, 'ajaxGetFrontendConversation']);
        add_action('wp_ajax_nopriv_geweb_get_frontend_conversation', [$conversationAjaxController, 'ajaxGetFrontendConversation']);
        add_action('wp_ajax_geweb_save_frontend_conversation', [$conversationAjaxController, 'ajaxSaveFrontendConversation']);
        add_action('wp_ajax_nopriv_geweb_save_frontend_conversation', [$conversationAjaxController, 'ajaxSaveFrontendConversation']);
        add_action('wp_ajax_geweb_resolve_source_references', [$sourceReferenceAjaxController, 'ajaxResolveSourceReferences']);
        add_action('wp_ajax_nopriv_geweb_resolve_source_references', [$sourceReferenceAjaxController, 'ajaxResolveSourceReferences']);
        add_action('wp_ajax_geweb_reconstruct_source_contexts', [$sourceReferenceAjaxController, 'ajaxReconstructSourceContexts']);
        add_action('wp_ajax_nopriv_geweb_reconstruct_source_contexts', [$sourceReferenceAjaxController, 'ajaxReconstructSourceContexts']);
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
        add_action('wp_ajax_geweb_start_admin_preload', [$adminDataAjaxController, 'ajaxStartAdminPreload']);
        add_action('wp_ajax_geweb_get_admin_preload_progress', [$adminDataAjaxController, 'ajaxGetAdminPreloadProgress']);
        add_action('wp_ajax_geweb_refresh_conversations', [$adminDataAjaxController, 'ajaxRefreshConversations']);
        add_action('wp_ajax_geweb_get_markdown_cache', [$adminDataAjaxController, 'ajaxGetMarkdownCache']);
        add_action('wp_ajax_geweb_get_referenced_document_markdown_cache', [$adminDataAjaxController, 'ajaxGetReferencedDocumentMarkdownCache']);
        add_action('wp_ajax_geweb_update_referenced_document', [$adminDataAjaxController, 'ajaxUpdateReferencedDocument']);
        add_action('wp_ajax_geweb_toggle_referenced_document_exclude', [$adminDataAjaxController, 'ajaxToggleReferencedDocumentExclude']);
        add_action('wp_ajax_geweb_set_referenced_document_image_processing_mode', [$adminDataAjaxController, 'ajaxSetReferencedDocumentImageProcessingMode']);
        add_action('wp_ajax_geweb_update_referenced_document_nice_name', [$adminDataAjaxController, 'ajaxUpdateReferencedDocumentNiceName']);
        add_action('wp_ajax_geweb_remove_referenced_document_from_file_list', [$adminDataAjaxController, 'ajaxRemoveReferencedDocumentFromFileList']);
        add_action('wp_ajax_geweb_refresh_gemini_stores', [$adminDataAjaxController, 'ajaxRefreshGeminiStores']);
        add_action('wp_ajax_geweb_refresh_gemini_store_documents', [$adminDataAjaxController, 'ajaxRefreshGeminiStoreDocuments']);
        add_action('wp_ajax_geweb_delete_gemini_store', [$adminDataAjaxController, 'ajaxDeleteGeminiStore']);
        add_action('wp_ajax_geweb_refresh_models', [$adminDataAjaxController, 'ajaxRefreshModels']);
        add_action('wp_ajax_geweb_test_model', [$adminDataAjaxController, 'ajaxTestModel']);

        add_action('wp_ajax_geweb_get_nonce', [$this, 'ajaxGetNonce']);
        add_action('wp_ajax_nopriv_geweb_get_nonce', [$this, 'ajaxGetNonce']);

        add_action('init', [self::class, 'registerFrontendAiRewrite']);
        add_action('init', [self::class, 'maybeRefreshFrontendAiRewrite'], 20);
        add_action('init', [self::class, 'maybeEnsureFrontendAiPageExists'], 30);
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
            'Workspace AI Search',
            'Workspace AI Search',
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
            'Files',
            'Files',
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
            'Chats',
            'Chats',
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

    public static function maybeEnsureFrontendAiPageExists(): void {
        FrontendAiContext::getFrontendAiPageId();
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

        try {
            $expectedRevision = GroupDataRevision::extractExpectedRevisionFromRequest('geweb_ai_search_group_revision');
            if ($expectedRevision !== '') {
                GroupDataRevision::assertExpectedRevision($expectedRevision);
            }

            $settingsManager = new AdminSettingsManager();
            $settingsManager->save();
        } catch (OptimisticLockException $e) {
            $redirectUrl = add_query_arg(
                [
                    'geweb_conflict' => $e->getMessage(),
                ],
                wp_get_referer() ?: admin_url(self::ADMIN_PAGE_PATH)
            );
            wp_safe_redirect($redirectUrl);
            exit;
        } catch (\InvalidArgumentException $e) {
            $redirectUrl = add_query_arg(
                [
                    'geweb_conflict' => $e->getMessage(),
                ],
                wp_get_referer() ?: admin_url(self::ADMIN_PAGE_PATH)
            );
            wp_safe_redirect($redirectUrl);
            exit;
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
        $settingsLink = '<a href="' . esc_url(admin_url(self::ADMIN_PAGE_PATH)) . '">Settings</a>';
        $workspaceLink = '<a href="' . esc_url(FrontendAiContext::getFrontendAiPageUrl()) . '">AI Workspace</a>';
        array_unshift($links, $settingsLink);
        array_unshift($links, $workspaceLink);

        return $links;
    }

    /**
     * Enqueue backtend scripts and styles
     *
     * @return void
     */
    public function enqueueAdminScripts(): void {
        wp_enqueue_style(
            'geweb-ai-search-admin',
            GEWEB_AI_SEARCH_URL . 'assets/css/admin.css',
            [],
            AssetVersion::forRelativePath('assets/css/admin.css')
        );

        wp_enqueue_script(
            'geweb-ai-search-markdown-renderer',
            GEWEB_AI_SEARCH_URL . 'assets/js/markdown-renderer.js',
            [],
            AssetVersion::forRelativePath('assets/js/markdown-renderer.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search-admin-utils',
            GEWEB_AI_SEARCH_URL . 'assets/js/admin-utils.js',
            ['jquery'],
            AssetVersion::forRelativePath('assets/js/admin-utils.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search-admin-markdown',
            GEWEB_AI_SEARCH_URL . 'assets/js/admin-markdown.js',
            ['geweb-ai-search-admin-utils', 'geweb-ai-search-markdown-renderer'],
            AssetVersion::forRelativePath('assets/js/admin-markdown.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search-admin',
            GEWEB_AI_SEARCH_URL . 'assets/admin.js',
            ['jquery', 'geweb-ai-search-markdown-renderer', 'geweb-ai-search-admin-utils', 'geweb-ai-search-admin-markdown'],
            AssetVersion::forRelativePath('assets/admin.js'),
            true
        );

        wp_localize_script('geweb-ai-search-admin', 'gewebAisearchAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'generateLibraryNonce' => wp_create_nonce('geweb_ai_search_generate_library'),
            'adminActionNonce' => wp_create_nonce('geweb_ai_search_admin_actions'),
            'groupDataRevision' => GroupDataRevision::ensureCurrentRevision(),
        ]);
    }

}
