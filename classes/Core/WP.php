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
    private const AI_CHAT_JOB_TRANSIENT_PREFIX = 'geweb_ai_chat_job_';
    private const AI_CHAT_JOB_TTL = DAY_IN_SECONDS;
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private const TRIMMED_CONTEXT_RECENT_MESSAGE_LIMIT = 5;
    private const TRIMMED_CONTEXT_SUMMARY_POINT_LIMIT = 5;
    private const TRIMMED_CONTEXT_SUMMARY_RETRY_OLDER_MESSAGE_LIMIT = 12;
    private ConversationManager $conversationManager;
    private AdminPageSections $adminPageSections;
    private FrontendAiWorkspaceController $frontendAiWorkspaceController;
    private FrontendAiPromptManager $frontendAiPromptManager;

    /**
     * Constructor - registers WordPress hooks
     */
    public function __construct() {
        UserScopeMigration::maybeRun();
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

        if (PluginUpdateGuard::isActive()) {
            wp_die(PluginUpdateGuard::getNoticeMessage());
        }

        try {
            GroupDataRevision::assertExpectedRevision(
                GroupDataRevision::extractExpectedRevisionFromRequest('geweb_ai_search_group_revision')
            );

            $settingsManager = new AdminSettingsManager();
            $settingsManager->save();
        } catch (OptimisticLockException $e) {
            $redirectUrl = add_query_arg(
                [
                    'geweb_conflict' => $e->getMessage(),
                ],
                wp_get_referer() ?: admin_url('admin.php?page=geweb-ai-search')
            );
            wp_safe_redirect($redirectUrl);
            exit;
        } catch (\InvalidArgumentException $e) {
            $redirectUrl = add_query_arg(
                [
                    'geweb_conflict' => $e->getMessage(),
                ],
                wp_get_referer() ?: admin_url('admin.php?page=geweb-ai-search')
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

        if ($temporaryPrompt !== '' && PromptSupport::containsDisallowedUrl($temporaryPrompt)) {
            wp_send_json_error(['message' => 'Temporary prompt cannot contain URLs. Remove links and try again.'], 400);
        }

        if (empty($rawMessages)) {
            wp_send_json_error(['message' => 'No messages provided']);
        }

        $messages = $this->normalizeAjaxChatMessages($rawMessages);

        try {
            $provider = ProviderFactory::make();
            $selectedModel = $this->resolveRequestedModel($provider, $requestedModel);
            $latestUserMessage = $this->conversationManager->extractLatestUserMessage($messages);
            $fullMessages = $this->conversationManager->buildFullConversationMessages($conversationId, $messages, $latestUserMessage);
            $initialConversation = $this->conversationManager->saveFrontendConversation(
                $conversationId,
                $fullMessages,
                '',
                false,
                ''
            );
            $resolvedConversationId = (string) ($initialConversation['id'] ?? $conversationId);
            $jobId = 'chatjob-' . wp_generate_password(12, false, false);

            $this->writeAiChatJob([
                'id' => $jobId,
                'status' => 'queued',
                'conversation_id' => $resolvedConversationId,
                'messages' => $fullMessages,
                'requested_model' => $selectedModel,
                'temporary_prompt' => $temporaryPrompt,
                'excluded_sources' => $this->extractExcludedSourcesFromRequest(),
                'created_at' => time(),
                'updated_at' => time(),
                'result' => null,
                'error_message' => '',
                'progress' => [
                    'stage' => 'queued',
                    'label' => __('Queued', 'geweb-ai-search'),
                    'thoughts' => [
                        __('Queued the request and waiting for the background worker to start.', 'geweb-ai-search'),
                    ],
                    'supports_thoughts' => strpos(strtolower($selectedModel), 'gemini-3') === 0,
                    'updated_at' => time(),
                ],
            ]);

            $this->sendAsyncJsonAndContinue([
                'success' => true,
                'data' => [
                    'queued' => true,
                    'job_id' => $jobId,
                    'conversation_id' => $resolvedConversationId,
                ],
            ]);

            $this->processAiChatJob($jobId);
            exit;
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public function ajaxGetAiChatJob(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $jobId = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        if ($jobId === '') {
            wp_send_json_error(['message' => 'Missing job ID.'], 400);
        }

        $job = $this->readAiChatJob($jobId);
        if ($job === null) {
            wp_send_json_error(['message' => 'Chat job not found.'], 404);
        }

        $status = (string) ($job['status'] ?? 'queued');
        $payload = [
            'job_id' => $jobId,
            'status' => $status,
            'conversation_id' => (string) ($job['conversation_id'] ?? ''),
        ];

        if (isset($job['progress']) && is_array($job['progress'])) {
            $payload['progress'] = $job['progress'];
        }

        if ($status === 'completed' && isset($job['result']) && is_array($job['result'])) {
            $payload['result'] = $job['result'];
        }

        if ($status === 'error') {
            $payload['message'] = (string) ($job['error_message'] ?? 'The AI request did not complete.');
        }

        wp_send_json_success($payload);
    }

    private function processAiChatJob(string $jobId): void {
        $job = $this->readAiChatJob($jobId);
        if ($job === null) {
            return;
        }

        $job['status'] = 'running';
        $job['updated_at'] = time();
        $this->updateAiChatJobProgress(
            $job,
            'starting',
            __('Preparing request', 'geweb-ai-search'),
            [
                __('Loading the saved conversation state.', 'geweb-ai-search'),
                __('Checking the selected model and request settings.', 'geweb-ai-search'),
            ]
        );
        $this->writeAiChatJob($job);

        try {
            $conversationId = (string) ($job['conversation_id'] ?? '');
            $fullMessages = isset($job['messages']) && is_array($job['messages']) ? $job['messages'] : [];
            $selectedModel = (string) ($job['requested_model'] ?? '');
            $temporaryPrompt = (string) ($job['temporary_prompt'] ?? '');
            $excludedSources = isset($job['excluded_sources']) && is_array($job['excluded_sources']) ? $job['excluded_sources'] : [];
            $thoughtHistory = [];
            $requestStartedAt = microtime(true);

            $provider = ProviderFactory::make();
            $requestId = 'chat-' . wp_generate_password(10, false, false);
            if (method_exists($provider, 'setRuntimeLogContext')) {
                $provider->setRuntimeLogContext([
                    'request_id' => $requestId,
                    'conversation_id' => $conversationId !== '' ? $conversationId : 'pending',
                    'ajax_action' => 'geweb_ai_chat_async',
                ]);
            }
            if (method_exists($provider, 'setStreamProgressCallback')) {
                $lastProgressHash = '';
                $lastProgressWriteAt = 0.0;
                $provider->setStreamProgressCallback(function (array $progress) use (&$job, &$lastProgressHash, &$lastProgressWriteAt, &$thoughtHistory, $requestStartedAt): void {
                    $thoughts = isset($progress['thoughts']) && is_array($progress['thoughts'])
                        ? $progress['thoughts']
                        : [];
                    $label = isset($progress['label']) ? (string) $progress['label'] : '';
                    $stage = isset($progress['stage']) ? (string) $progress['stage'] : 'streaming';
                    $signature = md5((string) wp_json_encode([
                        'stage' => $stage,
                        'label' => $label,
                        'thoughts' => $thoughts,
                    ]));
                    $now = microtime(true);
                    if ($signature === $lastProgressHash && ($now - $lastProgressWriteAt) < 0.8) {
                        return;
                    }

                    $this->updateAiChatJobProgress($job, $stage, $label, $thoughts);
                    $job['updated_at'] = time();
                    $this->writeAiChatJob($job);
                    if (!empty($thoughts)) {
                        $thoughtHistory[] = [
                            'stage' => sanitize_key($stage),
                            'label' => trim($label),
                            'changed_at_ms' => (int) round(microtime(true) * 1000),
                            'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
                            'thoughts' => array_values($thoughts),
                        ];
                    }
                    $lastProgressHash = $signature;
                    $lastProgressWriteAt = $now;
                });
            }

            $this->updateAiChatJobProgress(
                $job,
                'context',
                __('Preparing context', 'geweb-ai-search'),
                [
                    __('Reviewing the recent conversation for the next request.', 'geweb-ai-search'),
                    __('Checking whether earlier messages need to be compacted.', 'geweb-ai-search'),
                ]
            );
            $this->writeAiChatJob($job);

            $context = $this->conversationManager->compactConversationForRequest($fullMessages);
            $context = $this->refineTrimmedContext($context, $fullMessages, $provider, $selectedModel, $conversationId);
            $this->conversationManager->saveFrontendConversation(
                $conversationId,
                $fullMessages,
                '',
                !empty($context['compacted']),
                (string) ($context['summary'] ?? '')
            );

            $contextThoughts = [
                !empty($context['compacted'])
                    ? __('Compacted older messages to keep the request focused.', 'geweb-ai-search')
                    : __('Kept the full recent conversation context.', 'geweb-ai-search'),
            ];
            if (trim((string) ($context['summary'] ?? '')) !== '') {
                $contextThoughts[] = __('Attached the saved conversation summary for continuity.', 'geweb-ai-search');
            }
            $contextThoughts[] = __('Prepared the message bundle for Gemini search.', 'geweb-ai-search');
            $this->updateAiChatJobProgress(
                $job,
                'search',
                __('Waiting for Gemini', 'geweb-ai-search'),
                $contextThoughts
            );
            $this->writeAiChatJob($job);

            $result = $provider->search(
                $context['messages'],
                $selectedModel,
                $temporaryPrompt !== '' ? $temporaryPrompt : null,
                $excludedSources
            );
            $result = $this->attachRequestDebugMeta($result, $context, $selectedModel, $temporaryPrompt, $excludedSources, $thoughtHistory);
            $this->appendAiResponseToConversation($fullMessages, $result);
            $this->conversationManager->recordConversationUsage($conversationId, $fullMessages, $context['summary'], $result, $provider);

            $result['context_compacted'] = !empty($context['compacted']);
            $result['context_summary'] = (string) ($context['summary'] ?? '');

            $job['status'] = 'completed';
            $job['updated_at'] = time();
            $job['result'] = $result;
            $job['error_message'] = '';
            $this->updateAiChatJobProgress(
                $job,
                'completed',
                __('Completed', 'geweb-ai-search'),
                [
                    __('Gemini returned an answer and the response is ready to display.', 'geweb-ai-search'),
                ]
            );
            $this->writeAiChatJob($job);
        } catch (\Exception $e) {
            $job['status'] = 'error';
            $job['updated_at'] = time();
            $job['error_message'] = $e->getMessage();
            $job['result'] = null;
            $this->updateAiChatJobProgress(
                $job,
                'error',
                __('Request failed', 'geweb-ai-search'),
                [
                    __('The request stopped before Gemini returned a complete answer.', 'geweb-ai-search'),
                ]
            );
            $this->writeAiChatJob($job);
        }
    }

    /**
     * @param array<string,mixed> $job
     * @param array<int,string> $thoughts
     */
    private function updateAiChatJobProgress(array &$job, string $stage, string $label, array $thoughts = []): void {
        $supportsThoughts = false;
        $requestedModel = isset($job['requested_model']) ? strtolower(trim((string) $job['requested_model'])) : '';
        if (
            $requestedModel !== ''
            && (strpos($requestedModel, 'gemini-3') === 0 || strpos($requestedModel, 'gemini-2.5') === 0)
        ) {
            $supportsThoughts = true;
        }

        $normalizedThoughts = [];
        foreach ($thoughts as $thought) {
            $text = trim((string) $thought);
            if ($text !== '') {
                $normalizedThoughts[] = $text;
            }
        }

        $job['progress'] = [
            'stage' => sanitize_key($stage),
            'label' => trim($label),
            'thoughts' => array_values(array_unique($normalizedThoughts)),
            'supports_thoughts' => $supportsThoughts,
            'updated_at' => time(),
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @param array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool} $context
     * @param string $selectedModel
     * @param string $temporaryPrompt
     * @param array<int,array{key:string,title:string,url:string}> $excludedSources
     * @param array<int,array<string,mixed>> $thoughtHistory
     * @return array<string,mixed>
     */
    private function attachRequestDebugMeta(array $result, array $context, string $selectedModel, string $temporaryPrompt, array $excludedSources, array $thoughtHistory = []): array {
        $meta = isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [];

        $meta['request'] = [
            'created_at' => time(),
            'compacted' => !empty($context['compacted']),
            'context_summary' => trim((string) ($context['summary'] ?? '')),
            'context_message_count' => count($context['messages']),
            'model' => trim($selectedModel),
            'temporary_prompt_active' => trim($temporaryPrompt) !== '',
            'excluded_source_count' => count($excludedSources),
            'excluded_sources' => $this->buildExcludedSourceDebugLabels($excludedSources),
            'messages_preview' => $this->buildContextMessagePreview($context['messages']),
        ];
        if (!empty($thoughtHistory)) {
            $meta['thought_history'] = array_values(array_filter($thoughtHistory, static function ($entry): bool {
                return is_array($entry) && !empty($entry['thoughts']) && is_array($entry['thoughts']);
            }));
            $meta['request']['thought_history_updates'] = count($meta['thought_history']);
        }

        $result['meta'] = $meta;
        return $result;
    }

    /**
     * @param array<int,array{key:string,title:string,url:string}> $excludedSources
     * @return array<int,string>
     */
    private function buildExcludedSourceDebugLabels(array $excludedSources): array {
        $labels = [];

        foreach ($excludedSources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $title = trim((string) ($source['title'] ?? ''));
            $url = trim((string) ($source['url'] ?? ''));
            $label = $title !== ''
                ? $title
                : ($url !== '' ? $url : trim((string) ($source['key'] ?? '')));
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array{role:string,content:string}>
     */
    private function buildContextMessagePreview(array $messages): array {
        $preview = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if (function_exists('mb_strimwidth')) {
                $content = mb_strimwidth($content, 0, 320, '...');
            } elseif (strlen($content) > 320) {
                $content = substr($content, 0, 317) . '...';
            }

            $preview[] = [
                'role' => (string) ($message['role'] ?? 'user'),
                'content' => $content,
            ];
        }

        return $preview;
    }

    /**
     * @param array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool} $context
     * @param array<int,array<string,mixed>> $fullMessages
     * @param AIProviderInterface $provider
     * @param string $selectedModel
    * @param string $conversationId
     * @return array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool}
     */
    private function refineTrimmedContext(array $context, array $fullMessages, AIProviderInterface $provider, string $selectedModel, string $conversationId): array {
        if (empty($context['compacted'])) {
            return $context;
        }

        $recentMessages = array_slice($fullMessages, -self::TRIMMED_CONTEXT_RECENT_MESSAGE_LIMIT);
        $olderCount = max(0, count($fullMessages) - count($recentMessages));
        $olderMessages = $olderCount > 0 ? array_slice($fullMessages, 0, $olderCount) : [];
        $previousSummary = $this->conversationManager->getConversationContextSummary($conversationId);

        $summaryText = trim((string) ($context['summary'] ?? ''));
        if ($provider instanceof Gemini && !empty($olderMessages)) {
            $apiSummary = $this->buildApiContextSummaryWithRetry($provider, $olderMessages, $selectedModel, $previousSummary);
            if ($apiSummary !== '') {
                $summaryText = $apiSummary;
            }
        }

        $contextMessages = [];
        if ($summaryText !== '') {
            $contextMessages[] = [
                'role' => 'user',
                'content' => $summaryText,
            ];
        }

        foreach ($recentMessages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : 'user';
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $contextMessages[] = [
                'role' => $role === 'model' ? 'model' : 'user',
                'content' => $content,
            ];
        }

        return [
            'messages' => $contextMessages,
            'summary' => $summaryText,
            'compacted' => true,
        ];
    }

    /**
     * @param Gemini $provider
     * @param array<int,array<string,mixed>> $olderMessages
     * @param string $selectedModel
     * @param string $previousSummary
     * @return string
     */
    private function buildApiContextSummaryWithRetry(Gemini $provider, array $olderMessages, string $selectedModel, string $previousSummary): string {
        $attempts = [
            [
                'messages' => $olderMessages,
                'max_items' => self::TRIMMED_CONTEXT_SUMMARY_POINT_LIMIT,
            ],
            [
                'messages' => array_slice($olderMessages, -self::TRIMMED_CONTEXT_SUMMARY_RETRY_OLDER_MESSAGE_LIMIT),
                'max_items' => max(3, self::TRIMMED_CONTEXT_SUMMARY_POINT_LIMIT - 1),
            ],
        ];

        foreach ($attempts as $attempt) {
            $candidateMessages = isset($attempt['messages']) && is_array($attempt['messages'])
                ? $attempt['messages']
                : [];
            if (empty($candidateMessages)) {
                continue;
            }

            $maxItems = isset($attempt['max_items']) ? (int) $attempt['max_items'] : self::TRIMMED_CONTEXT_SUMMARY_POINT_LIMIT;

            try {
                $summary = trim((string) $provider->summarizeConversationForContext(
                    $candidateMessages,
                    $selectedModel,
                    $maxItems,
                    $previousSummary
                ));

                if ($summary !== '') {
                    return $summary;
                }
            } catch (\Exception $e) {
                // Retry with the next smaller attempt payload.
            }
        }

        return '';
    }

    /**
     * @param array<int,mixed> $rawMessages
     * @return array<int,array{role:string,content:string,created_at?:int}>
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

            $normalizedMessage = [
                'role' => $role,
                'content' => $content,
            ];

            $createdAtRaw = $rawMessage['created_at'] ?? $rawMessage['createdAt'] ?? null;
            if ($createdAtRaw !== null && is_numeric($createdAtRaw)) {
                $createdAt = (int) $createdAtRaw;
                if ($createdAt > 1000000000000) {
                    $createdAt = (int) floor($createdAt / 1000);
                }

                if ($createdAt > 0) {
                    $normalizedMessage['created_at'] = $createdAt;
                }
            }

            $messages[] = $normalizedMessage;
        }

        return $messages;
    }

    /**
     * @return array<int,array{key:string,title:string,url:string}>
     */
    private function extractExcludedSourcesFromRequest(): array {
        $rawValue = isset($_POST['excluded_sources']) ? wp_unslash($_POST['excluded_sources']) : '';
        if (!is_string($rawValue) || trim($rawValue) === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $excludedSources = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = isset($item['key']) ? sanitize_text_field((string) $item['key']) : '';
            $title = isset($item['title']) ? sanitize_text_field((string) $item['title']) : '';
            $url = isset($item['url']) ? esc_url_raw((string) $item['url']) : '';
            if ($key === '' && $url === '' && $title === '') {
                continue;
            }

            $excludedSources[] = [
                'key' => $key,
                'title' => $title,
                'url' => $url,
            ];
        }

        return $excludedSources;
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
            'created_at' => time(),
        ];
    }

    private function getAiChatJobTransientKey(string $jobId): string {
        return self::AI_CHAT_JOB_TRANSIENT_PREFIX . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId);
    }

    private function readAiChatJob(string $jobId): ?array {
        $job = get_transient($this->getAiChatJobTransientKey($jobId));
        return is_array($job) ? $job : null;
    }

    private function writeAiChatJob(array $job): void {
        $jobId = isset($job['id']) ? (string) $job['id'] : '';
        if ($jobId === '') {
            return;
        }

        set_transient($this->getAiChatJobTransientKey($jobId), $job, self::AI_CHAT_JOB_TTL);
    }

    private function sendAsyncJsonAndContinue(array $payload): void {
        if (!headers_sent()) {
            status_header(202);
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            header('Cache-Control: no-cache, must-revalidate, max-age=0');
        }

        ignore_user_abort(true);

        $json = wp_json_encode($payload);
        if (is_string($json)) {
            echo $json;
        } else {
            echo '{"success":false,"data":{"message":"Could not encode async response."}}';
        }

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        flush();
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
            'geweb-ai-search-admin',
            GEWEB_AI_SEARCH_URL . 'assets/admin.js',
            ['jquery', 'geweb-ai-search-markdown-renderer'],
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
