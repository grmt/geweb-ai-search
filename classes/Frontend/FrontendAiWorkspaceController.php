<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Renders and controls the frontend AI workspace.
 */
class FrontendAiWorkspaceController {
    /**
     * @var callable
     */
    private $getTabUrl;
    /**
     * @var callable
     */
    private $getFrontendAiChatModelConfig;
    /**
     * @var callable
     */
    private $getFrontendPromptDescriptors;

    private bool $frontendAiPageModalRendered = false;
    private bool $shortcodePageViewActive = false;
    /**
     * @var array<string,mixed>
     */
    private array $renderOverrides = [];

    /**
     * @param callable $getTabUrl
     * @param callable $getFrontendAiChatModelConfig
     * @param callable $getFrontendPromptDescriptors
     */
    public function __construct(callable $getTabUrl, callable $getFrontendAiChatModelConfig, callable $getFrontendPromptDescriptors) {
        $this->getTabUrl = $getTabUrl;
        $this->getFrontendAiChatModelConfig = $getFrontendAiChatModelConfig;
        $this->getFrontendPromptDescriptors = $getFrontendPromptDescriptors;
    }

    public function enqueueScripts(): void {
        $locale = function_exists('determine_locale') ? (string) determine_locale() : get_locale();
        $searchWithAiLabel = $this->getSearchWithAiLabel($locale);

        wp_enqueue_style(
            'geweb-ai-search-page-shell',
            GEWEB_AI_SEARCH_URL . 'assets/css/page-shell.css',
            [],
            AssetVersion::forRelativePath('assets/css/page-shell.css')
        );

        if (!$this->shouldLoadFullFrontendWorkspace()) {
            wp_enqueue_script(
                'geweb-ai-search-launcher',
                GEWEB_AI_SEARCH_URL . 'assets/launcher.js',
                ['jquery'],
                AssetVersion::forRelativePath('assets/launcher.js'),
                true
            );

            wp_localize_script('geweb-ai-search-launcher', 'geweb_aisearch', $this->buildFrontendLauncherConfig($searchWithAiLabel));
            return;
        }

        $provider = ProviderFactory::make();
        $frontendModelConfig = ($this->getFrontendAiChatModelConfig)($provider);
        $models = is_array($frontendModelConfig['models'] ?? null) ? $frontendModelConfig['models'] : [];
        $selectedModel = is_string($frontendModelConfig['selected_model'] ?? null) ? $frontendModelConfig['selected_model'] : '';
        if ($selectedModel !== '' && !in_array($selectedModel, $models, true) && method_exists($provider, 'getDefaultModel')) {
            array_unshift($models, $selectedModel);
            $models = array_values(array_unique($models));
        }

        wp_enqueue_script(
            'geweb-ai-search-markdown-renderer',
            GEWEB_AI_SEARCH_URL . 'assets/js/markdown-renderer.js',
            [],
            AssetVersion::forRelativePath('assets/js/markdown-renderer.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search-sources',
            GEWEB_AI_SEARCH_URL . 'assets/js/ai-sources.js',
            ['jquery', 'geweb-ai-search-markdown-renderer'],
            AssetVersion::forRelativePath('assets/js/ai-sources.js'),
            true
        );

        wp_enqueue_script(
            'geweb-ai-search',
            GEWEB_AI_SEARCH_URL . 'assets/script.js',
            ['jquery', 'geweb-ai-search-sources'],
            AssetVersion::forRelativePath('assets/script.js'),
            true
        );

        wp_enqueue_style(
            'geweb-ai-search-workspace-layout',
            GEWEB_AI_SEARCH_URL . 'assets/css/workspace-layout.css',
            ['geweb-ai-search-page-shell'],
            AssetVersion::forRelativePath('assets/css/workspace-layout.css')
        );

        wp_enqueue_style(
            'geweb-ai-search-workspace-panels',
            GEWEB_AI_SEARCH_URL . 'assets/css/workspace-panels.css',
            ['geweb-ai-search-workspace-layout'],
            AssetVersion::forRelativePath('assets/css/workspace-panels.css')
        );

        wp_enqueue_style(
            'geweb-ai-search-workspace-content',
            GEWEB_AI_SEARCH_URL . 'assets/css/workspace-content.css',
            ['geweb-ai-search-workspace-panels'],
            AssetVersion::forRelativePath('assets/css/workspace-content.css')
        );

        wp_localize_script('geweb-ai-search', 'geweb_aisearch', array_merge($this->buildFrontendLauncherConfig($searchWithAiLabel), [
            'ajax_url' => admin_url('admin-ajax.php'),
            'site_url' => home_url('/'),
            'models' => array_values($models),
            'selected_model' => $selectedModel,
            'prompt_descriptors' => ($this->getFrontendPromptDescriptors)($provider, $models, $selectedModel),
            'frontend_ai_interface' => FrontendAiContext::getFrontendAiInterface(),
            'frontend_ai_page_url' => FrontendAiContext::getCurrentFrontendAiPageUrl(),
            'frontend_ai_exit_url' => FrontendAiContext::getFrontendAiExitUrl(),
            'frontend_ai_manage_conversations_url' => current_user_can('manage_options') ? ($this->getTabUrl)('conversations') : '',
            'frontend_ai_manage_documents_url' => current_user_can('manage_options') ? ($this->getTabUrl)('documents') : '',
            'frontend_ai_manage_pages_url' => current_user_can('edit_pages') ? admin_url('edit.php?post_type=page') : '',
            'frontend_ai_edit_post_url' => current_user_can('edit_pages') ? admin_url('post.php') : '',
            'current_scope_key' => UserScope::getCurrentUserScopeStorageKey(),
            'is_frontend_ai_page' => FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive),
            'frontend_ai_conversation_id' => FrontendAiContext::getRequestedFrontendConversationId(),
            'frontend_ai_initial_query' => FrontendAiContext::getRequestedFrontendQuery(),
            'conversation_trim_message_limit' => FrontendAiContext::getConversationTrimMessageLimit(),
            'conversation_trim_char_limit' => FrontendAiContext::getConversationTrimCharLimit(),
            'local_conversation_archive_limit' => FrontendAiContext::getLocalConversationArchiveLimit(),
            'i18n' => [
                'openAiSearch' => __('Open AI Search', 'geweb-ai-search'),
                'askAi' => __('Ask AI', 'geweb-ai-search'),
                'searchWithAi' => $searchWithAiLabel,
                'thinking' => __('Thinking...', 'geweb-ai-search'),
                'thoughtProcess' => __('Denkproces', 'geweb-ai-search'),
                'thoughtProcessPending' => __('Denkproces wordt opgebouwd...', 'geweb-ai-search'),
                'couldNotStart' => __('Could not start the AI search. Please try again.', 'geweb-ai-search'),
                'connectionError' => __('Connection error. Please try again.', 'geweb-ai-search'),
                'requestTimedOut' => __('The AI request timed out. Please try again.', 'geweb-ai-search'),
                'answerError' => __('Error: Unable to get response', 'geweb-ai-search'),
                'responseDetails' => __('Response details', 'geweb-ai-search'),
                'requestMetaTitle' => __('Request context', 'geweb-ai-search'),
                'clickAnswerForDetails' => __('Click the answer to show response details.', 'geweb-ai-search'),
                'hideDetails' => __('Hide details', 'geweb-ai-search'),
                'showDetails' => __('Show details', 'geweb-ai-search'),
                'earlierTrimmed' => __('Earlier messages were trimmed to keep the chat context compact.', 'geweb-ai-search'),
                'contextSummaryCollapsed' => __('Compacted context summary used. Click to expand.', 'geweb-ai-search'),
                'noChatsYet' => __('No chats yet.', 'geweb-ai-search'),
                'copyAnswer' => __('Copy answer', 'geweb-ai-search'),
                'retryAnswer' => __('Retry with longer timeout', 'geweb-ai-search'),
                'copyConversation' => __('Copy chat', 'geweb-ai-search'),
                'temporaryPrompt' => __('Temporary chat prompt', 'geweb-ai-search'),
                'temporaryPromptActive' => __('Temporary prompt', 'geweb-ai-search'),
                'temporaryModelActive' => __('Temporary model', 'geweb-ai-search'),
                'temporaryPromptPrefix' => __('Temporary override of', 'geweb-ai-search'),
                'temporaryPromptPlaceholder' => __('Optional prompt override for this question only...', 'geweb-ai-search'),
                'toggleTemporaryPrompt' => __('Toggle temporary prompt', 'geweb-ai-search'),
                'composerSettings' => __('Settings', 'geweb-ai-search'),
                'composerSettingsTitle' => __('Next question settings', 'geweb-ai-search'),
                'composerNextMessageOnly' => __('Applies to the next question only.', 'geweb-ai-search'),
                'composerAppliesNextQuestions' => __('Applies to the next questions until you reset it.', 'geweb-ai-search'),
                'composerReset' => __('Reset', 'geweb-ai-search'),
                'composerClose' => __('Close settings', 'geweb-ai-search'),
                'composerUseDefaultModel' => __('Use default model', 'geweb-ai-search'),
                'composerUseDefaultPrompt' => __('Use default prompt', 'geweb-ai-search'),
                'composerEditPrompt' => __('Edit prompt', 'geweb-ai-search'),
                'composerHidePromptEditor' => __('Hide prompt editor', 'geweb-ai-search'),
                'composerTemporaryOverride' => __('Defaults overridden', 'geweb-ai-search'),
                'composerPromptLabel' => __('Prompt', 'geweb-ai-search'),
                'composerModelLabel' => __('Model', 'geweb-ai-search'),
                'composerPreviousQuestion' => __('Previous question', 'geweb-ai-search'),
                'composerNextQuestion' => __('Next question', 'geweb-ai-search'),
                'copied' => __('Copied', 'geweb-ai-search'),
                'copyFailed' => __('Could not copy', 'geweb-ai-search'),
                'excludeSourceTemporarily' => __('Temporarily exclude', 'geweb-ai-search'),
                'excludeSourceTemporarilyTitle' => __('Temporarily exclude this source from the next question', 'geweb-ai-search'),
                'includeSourceAgain' => __('Use again', 'geweb-ai-search'),
                'includeSourceAgainTitle' => __('Allow this source again for the next question', 'geweb-ai-search'),
                'useAllSourcesAgain' => __('Use all sources again', 'geweb-ai-search'),
                'oneSourceTemporarilyExcluded' => __('1 source temporarily excluded for the next question.', 'geweb-ai-search'),
                'multipleSourcesTemporarilyExcluded' => __('%d sources temporarily excluded for the next question.', 'geweb-ai-search'),
                'savedChat' => __('Saved chat', 'geweb-ai-search'),
                'untitledConversation' => __('Untitled chat', 'geweb-ai-search'),
                'noSourcesYet' => __('No source links yet.', 'geweb-ai-search'),
                'renameConversation' => __('Rename chat', 'geweb-ai-search'),
                'removeConversationConfirm' => __('Remove this chat from the current search context?', 'geweb-ai-search'),
                'mentionedInAnswer' => __('Mentioned in answer', 'geweb-ai-search'),
                'newChat' => __('New chat', 'geweb-ai-search'),
                'linksToPages' => __('Links to pages and documents used in the answer.', 'geweb-ai-search'),
                'openPagesListing' => __('Open pages listing', 'geweb-ai-search'),
                'openDocumentsListing' => __('Open documents listing', 'geweb-ai-search'),
                'closeSourceContext' => __('Close source context', 'geweb-ai-search'),
                'showResults' => __('Show results', 'geweb-ai-search'),
                'hideResults' => __('Hide results', 'geweb-ai-search'),
                'modelLabel' => __('Model', 'geweb-ai-search'),
                'manageConversations' => __('Manage chats', 'geweb-ai-search'),
                'searchResultsIntro' => __('Use your normal site search above to update these WordPress results without leaving the AI workspace.', 'geweb-ai-search'),
                'mobileChatsTab' => __('History', 'geweb-ai-search'),
                'mobileChatTab' => __('AI chat', 'geweb-ai-search'),
                'mobileSourcesTab' => __('Sources', 'geweb-ai-search'),
            ],
        ]));
    }

    private function getSearchWithAiLabel(string $locale): string {
        $normalizedLocale = strtolower(str_replace('-', '_', $locale));
        if (str_starts_with($normalizedLocale, 'nl')) {
            return 'Zoeken met AI';
        }

        if (str_starts_with($normalizedLocale, 'en_gb') || str_starts_with($normalizedLocale, 'en_us') || str_starts_with($normalizedLocale, 'en')) {
            return 'AI search';
        }

        return __('AI search', 'geweb-ai-search');
    }

    /**
     * @param array<string,mixed> $atts
     */
    public function renderFrontendAiSearchShortcode(array $atts = []): string {
        if (is_admin()) {
            return '';
        }

        $atts = shortcode_atts([
            'title' => '',
            'search_results' => 'show',
            'search_results_height' => '70',
            'manage_link' => 'show',
        ], $atts, 'geweb_ai_search');

        $searchResults = sanitize_key((string) $atts['search_results']);
        $manageLink = sanitize_key((string) $atts['manage_link']);
        $initialHeight = is_numeric($atts['search_results_height']) ? (int) $atts['search_results_height'] : 70;
        $initialHeight = max(0, min(100, $initialHeight));

        if ($searchResults === 'hide') {
            $initialHeight = 0;
        }

        $this->shortcodePageViewActive = true;
        $this->renderOverrides = [
            'title' => sanitize_text_field((string) $atts['title']),
            'search_results_initial_height' => $initialHeight,
            'show_manage_link' => $manageLink !== 'hide',
        ];

        ob_start();
        $this->renderModals();
        $output = (string) ob_get_clean();
        $this->renderOverrides = [];

        return $output;
    }

    public function maybeRemoveFrontendAdminBarSearch(\WP_Admin_Bar $adminBar): void {
        if (is_admin() || !FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive)) {
            return;
        }

        $adminBar->remove_node('search');
    }

    public function renderModals(): void {
        if (!$this->shouldLoadFullFrontendWorkspace()) {
            return;
        }

        $isFrontendAiPage = FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive);
        $provider = ProviderFactory::make();
        $frontendModelConfig = ($this->getFrontendAiChatModelConfig)($provider);
        $models = is_array($frontendModelConfig['models'] ?? null) ? $frontendModelConfig['models'] : [];
        $selectedModel = is_string($frontendModelConfig['selected_model'] ?? null) ? $frontendModelConfig['selected_model'] : '';
        $promptDescriptors = ($this->getFrontendPromptDescriptors)($provider, $models, $selectedModel);
        $currentPromptDescriptor = $promptDescriptors[$selectedModel] ?? [];
        if (empty($currentPromptDescriptor)) {
            $fallbackPromptDescriptor = reset($promptDescriptors);
            $currentPromptDescriptor = is_array($fallbackPromptDescriptor)
                ? $fallbackPromptDescriptor
                : ['name' => __('Built-in prompt', 'geweb-ai-search'), 'instruction' => ''];
        }
        $currentPromptName = trim((string) ($currentPromptDescriptor['name'] ?? ''));
        if ($currentPromptName === '') {
            $currentPromptName = __('Built-in prompt', 'geweb-ai-search');
        }
        $currentPromptInstruction = (string) ($currentPromptDescriptor['instruction'] ?? '');

        if ($isFrontendAiPage && $this->frontendAiPageModalRendered) {
            return;
        }

        if ($isFrontendAiPage) {
            $this->frontendAiPageModalRendered = true;
        }

        $tagName = $isFrontendAiPage ? 'section' : 'dialog';
        $pageViewClass = $isFrontendAiPage ? ' geweb-aisearch-modal-window--page' : '';
        $pageViewAttribute = $isFrontendAiPage ? ' data-geweb-page-view="1"' : '';
        $title = (string) apply_filters('geweb_aisearch_ai_modal_title', 'AI Assistant');
        if (isset($this->renderOverrides['title']) && trim((string) $this->renderOverrides['title']) !== '') {
            $title = (string) $this->renderOverrides['title'];
        }
        $showManageLink = !isset($this->renderOverrides['show_manage_link']) || !empty($this->renderOverrides['show_manage_link']);
        ?>
        <<?php echo esc_html($tagName); ?> id="geweb-ai-modal" class="geweb-aisearch-modal-window geweb-aisearch-modal-window--<?php echo esc_attr(FrontendAiContext::getFrontendAiInterface()); ?><?php echo esc_attr($pageViewClass); ?>" aria-label="<?php echo esc_attr($title); ?>"<?php echo $pageViewAttribute; ?>>
            <?php if (!$isFrontendAiPage): ?>
                <div class="modal-header">
                    <strong class="ai-assistant-title"><?php echo esc_html($title); ?></strong>
                    <button type="button" class="close" aria-label="<?php echo esc_attr__('Close AI assistant', 'geweb-ai-search'); ?>"></button>
                </div>
            <?php endif; ?>
            <?php if ($isFrontendAiPage): ?>
                <?php $this->renderFrontendAiPageToolbar(); ?>
                <?php $this->renderFrontendAiSearchResultsPanel(); ?>
            <?php endif; ?>
            <div class="geweb-ai-workspace" data-mobile-pane="main">
                <aside class="geweb-ai-sidebar" data-mobile-pane="left" tabindex="-1" aria-label="<?php echo esc_attr__('Chat panel', 'geweb-ai-search'); ?>">
                    <div class="geweb-ai-overview-header">
                        <div class="geweb-ai-panel-heading">
                            <div class="geweb-ai-panel-heading-main">
                                <div class="geweb-ai-panel-title geweb-ai-panel-title--inline"><?php echo esc_html__('Chat', 'geweb-ai-search'); ?></div>
                                <div class="geweb-ai-panel-heading-actions geweb-ai-panel-heading-actions--conversation">
                                    <button type="button" class="geweb-ai-new-conversation geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" aria-label="<?php echo esc_attr__('New chat', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('New chat', 'geweb-ai-search'); ?>">
                                        <span class="geweb-ai-overview-action-icon" aria-hidden="true">+</span>
                                        <span class="geweb-ai-overview-action-label"><?php echo esc_html__('New', 'geweb-ai-search'); ?></span>
                                    </button>
                                    <?php if ($showManageLink && current_user_can('manage_options')): ?>
                                        <a class="geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" href="<?php echo esc_url(($this->getTabUrl)('conversations')); ?>" aria-label="<?php echo esc_attr__('Manage chats', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Manage chats', 'geweb-ai-search'); ?>">
                                            <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                                            <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Manage', 'geweb-ai-search'); ?></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="geweb-ai-icon-button geweb-ai-panel-collapse" data-panel-toggle="left" aria-expanded="true" aria-label="<?php echo esc_attr__('Collapse chats panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Collapse chats panel', 'geweb-ai-search'); ?>">
                                <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">◀</span>
                            </button>
                        </div>
                    </div>
                    <div class="geweb-ai-current-conversation">
                        <div class="geweb-ai-current-conversation-header">
                            <div class="geweb-ai-current-conversation-label"><?php echo esc_html__('Current chat', 'geweb-ai-search'); ?></div>
                            <div class="geweb-ai-current-conversation-actions">
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-copy-conversation" aria-label="<?php echo esc_attr__('Copy chat', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Copy chat', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">⧉</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Copy', 'geweb-ai-search'); ?></span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-rename-conversation" aria-label="<?php echo esc_attr__('Rename chat', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Rename chat', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">✎</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Rename', 'geweb-ai-search'); ?></span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-delete-conversation" aria-label="<?php echo esc_attr__('Remove chat', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Remove chat', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">−</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Remove', 'geweb-ai-search'); ?></span>
                                </button>
                            </div>
                        </div>
                        <div id="geweb-ai-current-conversation-summary" class="geweb-ai-current-conversation-summary"><?php echo esc_html__('Untitled chat', 'geweb-ai-search'); ?></div>
                    </div>
                    <div id="geweb-ai-conversation-overview" class="geweb-ai-conversation-overview"></div>
                </aside>
                <div class="geweb-ai-pane-resizer geweb-ai-pane-resizer--left" data-resize-target="left" aria-orientation="vertical" aria-label="<?php echo esc_attr__('Resize chats panel', 'geweb-ai-search'); ?>"></div>
                <div class="geweb-ai-main-panel" data-mobile-pane="main" tabindex="-1">
                    <div class="answer-box"></div>
                    <div class="question-box">
                        <div class="geweb-ai-question-toolbar">
                            <div class="geweb-ai-question-summary" aria-live="polite">
                                <button type="button" class="geweb-ai-question-summary-item" data-geweb-temp-settings-toggle="1" aria-expanded="false" aria-controls="geweb-ai-temporary-settings-panel" title="<?php echo esc_attr__('Next question settings', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-question-summary-label"><?php echo esc_html__('Model:', 'geweb-ai-search'); ?></span>
                                    <span id="geweb-ai-current-model-display" class="geweb-ai-question-summary-value"><?php echo esc_html($selectedModel); ?></span>
                                </button>
                                <button type="button" class="geweb-ai-question-summary-item" data-geweb-temp-settings-toggle="1" aria-expanded="false" aria-controls="geweb-ai-temporary-settings-panel" title="<?php echo esc_attr__('Next question settings', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-question-summary-label"><?php echo esc_html__('Prompt:', 'geweb-ai-search'); ?></span>
                                    <span id="geweb-ai-current-prompt-display" class="geweb-ai-question-summary-value geweb-ai-question-summary-value--prompt" title="<?php echo esc_attr($currentPromptInstruction); ?>"><?php echo esc_html($currentPromptName); ?></span>
                                </button>
                            </div>
                            <div class="geweb-ai-question-toolbar-actions">
                                <button type="button" class="geweb-ai-icon-button" data-geweb-temp-settings-toggle="1" aria-label="<?php echo esc_attr__('Settings', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Change model or prompt', 'geweb-ai-search'); ?>">
                                    <span aria-hidden="true" class="geweb-ai-icon">⚙</span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-only geweb-ai-question-history-button" id="geweb-ai-question-history-prev" aria-label="<?php echo esc_attr__('Previous question', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Previous question', 'geweb-ai-search'); ?>" disabled>
                                    <span aria-hidden="true">↑</span>
                                </button>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-only geweb-ai-question-history-button" id="geweb-ai-question-history-next" aria-label="<?php echo esc_attr__('Next question', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Next question', 'geweb-ai-search'); ?>" disabled>
                                    <span aria-hidden="true">↓</span>
                                </button>
                            </div>
                        </div>
                        <div class="geweb-ai-temporary-settings-panel" id="geweb-ai-temporary-settings-panel" hidden>
                            <div class="geweb-ai-temporary-settings-header">
                                <div class="geweb-ai-temporary-settings-heading">
                                    <div class="geweb-ai-temporary-settings-title"><?php echo esc_html__('Next question settings', 'geweb-ai-search'); ?></div>
                                    <p class="geweb-ai-temporary-settings-note"><?php echo esc_html__('Applies to the next questions until you reset it.', 'geweb-ai-search'); ?></p>
                                </div>
                                <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-secondary-button--icon-only geweb-ai-temporary-settings-close" id="geweb-ai-close-temp-settings" aria-label="<?php echo esc_attr__('Close settings', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Close settings', 'geweb-ai-search'); ?>">×</button>
                            </div>
                            <?php if (!empty($models)): ?>
                                <div class="geweb-ai-temporary-settings-row">
                                    <label for="geweb-ai-model-selector" class="geweb-ai-model-selector-label"><?php echo esc_html__('Model', 'geweb-ai-search'); ?></label>
                                    <div class="geweb-ai-temporary-settings-controls">
                                        <select id="geweb-ai-model-selector" class="geweb-ai-model-selector" disabled>
                                            <?php foreach ($models as $model): ?>
                                                <option value="<?php echo esc_attr($model); ?>" <?php selected($selectedModel, $model); ?>><?php echo esc_html($model); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-temporary-reset-button geweb-ai-temporary-inline-action geweb-ai-temporary-inline-action--icon" id="geweb-ai-reset-temp-model" aria-label="<?php echo esc_attr__('Use default model', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Use default model', 'geweb-ai-search'); ?>">
                                            <span class="geweb-ai-temporary-inline-action-icon" aria-hidden="true">↺</span>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="geweb-ai-temporary-settings-row">
                                <label for="geweb-ai-temporary-prompt" class="geweb-ai-model-selector-label"><?php echo esc_html__('Prompt', 'geweb-ai-search'); ?></label>
                                <div class="geweb-ai-temporary-settings-controls geweb-ai-temporary-settings-controls--prompt">
                                    <div id="geweb-ai-temporary-prompt-summary" class="geweb-ai-temporary-prompt-summary"><?php echo esc_html($currentPromptName); ?></div>
                                    <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-temporary-inline-action geweb-ai-temporary-inline-action--icon" id="geweb-ai-toggle-prompt-editor" aria-label="<?php echo esc_attr__('Edit prompt', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Edit prompt', 'geweb-ai-search'); ?>">
                                        <span class="geweb-ai-temporary-inline-action-icon geweb-ai-temporary-inline-action-icon--edit" aria-hidden="true">✎</span>
                                    </button>
                                    <button type="button" class="geweb-ai-icon-button geweb-ai-secondary-button geweb-ai-temporary-reset-button geweb-ai-temporary-inline-action geweb-ai-temporary-inline-action--icon" id="geweb-ai-reset-temp-prompt" aria-label="<?php echo esc_attr__('Use default prompt', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Use default prompt', 'geweb-ai-search'); ?>">
                                        <span class="geweb-ai-temporary-inline-action-icon" aria-hidden="true">↺</span>
                                    </button>
                                </div>
                            </div>
                            <div class="geweb-ai-temporary-prompt-editor" id="geweb-ai-temporary-prompt-editor" hidden>
                                <textarea id="geweb-ai-temporary-prompt" class="geweb-ai-temporary-prompt" placeholder="<?php echo esc_attr__('Optional prompt override for this question only...', 'geweb-ai-search'); ?>" disabled><?php echo esc_textarea($currentPromptInstruction); ?></textarea>
                            </div>
                        </div>
                        <div class="geweb-ai-question-input-row">
                            <textarea id="geweb-ai-query-display" placeholder="<?php echo esc_attr(apply_filters('geweb_aisearch_ai_textarea_placeholder', 'Ask AI a question...')); ?>"></textarea>
                            <button id="geweb-ask-ai-submit" class="btn" type="submit" disabled aria-label="<?php echo esc_attr__('Send AI question', 'geweb-ai-search'); ?>"></button>
                        </div>
                    </div>
                </div>
                <div class="geweb-ai-pane-resizer geweb-ai-pane-resizer--right" data-resize-target="right" aria-orientation="vertical" aria-label="<?php echo esc_attr__('Resize sources panel', 'geweb-ai-search'); ?>"></div>
                <aside class="geweb-ai-sources-panel" data-mobile-pane="right" tabindex="-1" aria-label="<?php echo esc_attr__('Source references panel', 'geweb-ai-search'); ?>">
                    <div class="geweb-ai-panel-heading">
                        <div class="geweb-ai-panel-heading-main">
                            <div class="geweb-ai-panel-title"><?php echo esc_html__('Source references', 'geweb-ai-search'); ?></div>
                            <div class="geweb-ai-panel-heading-actions">
                                <?php if (current_user_can('manage_options')): ?>
                                    <a class="geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" href="<?php echo esc_url(($this->getTabUrl)('documents')); ?>" aria-label="<?php echo esc_attr__('Manage source references', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Manage source references', 'geweb-ai-search'); ?>">
                                        <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                                        <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Manage', 'geweb-ai-search'); ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="geweb-ai-icon-button geweb-ai-panel-collapse" data-panel-toggle="right" aria-expanded="true" aria-label="<?php echo esc_attr__('Collapse sources panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Collapse sources panel', 'geweb-ai-search'); ?>">
                            <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">▶</span>
                        </button>
                    </div>
                    <p class="geweb-ai-sources-help"><?php echo esc_html__('Pages, posts, and documents referenced by the current answer or restored chat.', 'geweb-ai-search'); ?></p>
                    <div id="geweb-ai-sources" class="geweb-ai-sources"></div>
                </aside>
            </div>
            <?php if ($isFrontendAiPage): ?>
                <nav class="geweb-ai-mobile-pane-footer" aria-label="<?php echo esc_attr__('Workspace panes', 'geweb-ai-search'); ?>">
                    <button type="button" class="geweb-ai-icon-button geweb-ai-mobile-pane-tab" data-mobile-pane-target="left" aria-pressed="false">
                        <span class="geweb-ai-mobile-pane-tab-icon" aria-hidden="true">☰</span>
                        <span class="geweb-ai-mobile-pane-tab-label"><?php echo esc_html__('History', 'geweb-ai-search'); ?></span>
                    </button>
                    <button type="button" class="geweb-ai-icon-button geweb-ai-mobile-pane-tab" data-mobile-pane-target="main" aria-pressed="true">
                        <span class="geweb-ai-mobile-pane-tab-icon" aria-hidden="true">✎</span>
                        <span class="geweb-ai-mobile-pane-tab-label"><?php echo esc_html__('AI chat', 'geweb-ai-search'); ?></span>
                    </button>
                    <button type="button" class="geweb-ai-icon-button geweb-ai-mobile-pane-tab" data-mobile-pane-target="right" aria-pressed="false">
                        <span class="geweb-ai-mobile-pane-tab-icon" aria-hidden="true">#</span>
                        <span class="geweb-ai-mobile-pane-tab-label"><?php echo esc_html__('Sources', 'geweb-ai-search'); ?></span>
                    </button>
                </nav>
            <?php endif; ?>
        </<?php echo esc_html($tagName); ?>>
        <?php
    }

    private function renderFrontendAiPageToolbar(): void {
        ?>
        <section class="geweb-ai-page-toolbar" aria-label="<?php echo esc_attr__('AI workspace controls', 'geweb-ai-search'); ?>">
            <div class="geweb-ai-page-toolbar-title"><?php echo esc_html__('AI Workspace', 'geweb-ai-search'); ?></div>
            <div class="geweb-ai-page-toolbar-actions">
                <button
                    type="button"
                    class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--menu"
                    id="geweb-ai-toggle-mobile-menu"
                    data-panel-toggle="left"
                    aria-label="<?php echo esc_attr__('Show chats panel', 'geweb-ai-search'); ?>"
                    title="<?php echo esc_attr__('Show chats panel', 'geweb-ai-search'); ?>"
                    aria-expanded="false"
                >
                    <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">☰</span>
                    <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Chats', 'geweb-ai-search'); ?></span>
                </button>
                <button
                    type="button"
                    class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--align"
                    id="geweb-ai-align-workspace"
                    aria-label="<?php echo esc_attr__('Align workspace to the browser window', 'geweb-ai-search'); ?>"
                    title="<?php echo esc_attr__('Align workspace to the browser window', 'geweb-ai-search'); ?>"
                >
                    <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">↕</span>
                    <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Align', 'geweb-ai-search'); ?></span>
                </button>
                <button
                    type="button"
                    class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--toggle-search"
                    id="geweb-ai-toggle-search-panel"
                    data-panel-toggle="search"
                    aria-label="<?php echo esc_attr__('Toggle classic search results', 'geweb-ai-search'); ?>"
                    title="<?php echo esc_attr__('Toggle classic search results', 'geweb-ai-search'); ?>"
                    aria-expanded="true"
                >
                    <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">🔎</span>
                    <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Search', 'geweb-ai-search'); ?></span>
                </button>
                <button
                    type="button"
                    class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--fullscreen"
                    id="geweb-ai-toggle-fullscreen"
                    aria-label="<?php echo esc_attr__('Enter fullscreen', 'geweb-ai-search'); ?>"
                    title="<?php echo esc_attr__('Enter fullscreen', 'geweb-ai-search'); ?>"
                >
                    <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">⛶</span>
                    <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Fullscreen', 'geweb-ai-search'); ?></span>
                </button>
                <?php if (current_user_can('manage_options')): ?>
                    <a
                        class="geweb-ai-icon-button geweb-ai-page-toolbar-button geweb-ai-page-toolbar-button--settings"
                        href="<?php echo esc_url(admin_url('admin.php?page=geweb-ai-search')); ?>"
                        aria-label="<?php echo esc_attr__('Open AI search settings', 'geweb-ai-search'); ?>"
                        title="<?php echo esc_attr__('Open AI search settings', 'geweb-ai-search'); ?>"
                    >
                        <span class="geweb-ai-page-toolbar-button-icon" aria-hidden="true">⚙</span>
                        <span class="geweb-ai-page-toolbar-button-label"><?php echo esc_html__('Settings', 'geweb-ai-search'); ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    public function maybeRenderFrontendAiPage(): void {
        if (!FrontendAiContext::isDedicatedFrontendAiPageRequest()) {
            return;
        }

        status_header(200);
        nocache_headers();
        add_filter('body_class', [FrontendAiContext::class, 'filterFrontendAiBodyClasses']);
        get_header();
        remove_filter('body_class', [FrontendAiContext::class, 'filterFrontendAiBodyClasses']);
        ?>
            <main id="geweb-ai-page-root" class="geweb-ai-page-root">
                <?php $this->renderModals(); ?>
            </main>
        <?php
        get_footer();
        exit;
    }

    private function renderFrontendAiSearchResultsPanel(): void {
        $query = FrontendAiContext::getRequestedFrontendQuery();
        $emptyStateHint = __('Use your normal site search above to update these WordPress results without leaving the AI workspace.', 'geweb-ai-search');
        $resultsInfoText = $query !== ''
            ? __('These are the regular WordPress search results for the current query.', 'geweb-ai-search')
            : $emptyStateHint;
        $initialHeight = isset($this->renderOverrides['search_results_initial_height'])
            ? (int) $this->renderOverrides['search_results_initial_height']
            : 70;
        $initialHeight = max(0, min(100, $initialHeight));
        ?>
        <section class="geweb-ai-search-results-panel<?php echo $query === '' ? ' geweb-ai-search-results-panel--empty' : ''; ?>"<?php echo $query === '' ? ' title="' . esc_attr($emptyStateHint) . '"' : ''; ?>>
            <div class="geweb-ai-search-results-header"<?php echo $query === '' ? ' title="' . esc_attr($emptyStateHint) . '"' : ''; ?>>
                <div class="geweb-ai-panel-heading geweb-ai-panel-heading--search-results">
                    <div class="geweb-ai-panel-title geweb-ai-panel-title--inline"><?php echo esc_html__('Search Results', 'geweb-ai-search'); ?></div>
                    <button
                        type="button"
                        class="geweb-ai-search-results-info"
                        aria-label="<?php echo esc_attr($resultsInfoText); ?>"
                        title="<?php echo esc_attr($resultsInfoText); ?>"
                    >
                        <span class="geweb-ai-search-results-info-icon" aria-hidden="true">i</span>
                    </button>
                    <button type="button" class="geweb-ai-icon-button geweb-ai-panel-collapse" data-panel-toggle="search" aria-expanded="<?php echo $initialHeight > 0 ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr($initialHeight > 0 ? __('Collapse classic search results', 'geweb-ai-search') : __('Expand classic search results', 'geweb-ai-search')); ?>" title="<?php echo esc_attr($initialHeight > 0 ? __('Collapse classic search results', 'geweb-ai-search') : __('Expand classic search results', 'geweb-ai-search')); ?>">
                        <span class="geweb-ai-panel-collapse-icon" aria-hidden="true"><?php echo $initialHeight > 0 ? '▴' : '▾'; ?></span>
                    </button>
                </div>
            </div>
            <div class="geweb-ai-search-results-content">
                <form class="geweb-ai-search-form geweb-ai-inline-search-form" role="search" action="<?php echo esc_url(FrontendAiContext::getCurrentFrontendAiPageUrl()); ?>" method="get">
                    <label class="screen-reader-text" for="geweb-ai-inline-search"><?php echo esc_html__('Search this site', 'geweb-ai-search'); ?></label>
                    <input
                        type="search"
                        id="geweb-ai-inline-search"
                        name="s"
                        class="geweb-ai-search-input"
                        value="<?php echo esc_attr($query); ?>"
                        placeholder="<?php echo esc_attr__('Search this site...', 'geweb-ai-search'); ?>"
                        autocomplete="off"
                    >
                    <?php $conversationId = FrontendAiContext::getRequestedFrontendConversationId(); ?>
                    <?php if ($conversationId !== ''): ?>
                        <input type="hidden" name="geweb_ai_conversation" value="<?php echo esc_attr($conversationId); ?>">
                    <?php endif; ?>
                    <button type="submit" class="geweb-ai-icon-button geweb-ai-inline-search-submit"><?php echo esc_html__('Search', 'geweb-ai-search'); ?></button>
                </form>
                <?php if ($query !== ''): ?>
                    <?php
                    $postTypes = get_option('geweb_aisearch_post_types', ['post']);
                    if (!is_array($postTypes) || empty($postTypes)) {
                        $postTypes = ['post'];
                    }
                    $searchQuery = new \WP_Query([
                        'post_type' => $postTypes,
                        'post_status' => 'publish',
                        's' => $query,
                        'posts_per_page' => 8,
                        'post__not_in' => array_filter([FrontendAiContext::getFrontendAiPageId()]),
                    ]);
                    ?>
                    <?php if ($searchQuery->have_posts()): ?>
                        <ul class="geweb-ai-search-results-list">
                            <?php while ($searchQuery->have_posts()): $searchQuery->the_post(); ?>
                                <li class="geweb-ai-search-result-item">
                                    <a href="<?php the_permalink(); ?>" class="geweb-ai-search-result-link"><?php the_title(); ?></a>
                                    <div class="geweb-ai-search-result-excerpt"><?php echo esc_html(FrontendAiContext::buildFrontendSearchResultExcerpt(get_the_ID())); ?></div>
                                </li>
                            <?php endwhile; ?>
                        </ul>
                    <?php else: ?>
                        <p class="geweb-ai-empty-panel"><?php echo esc_html__('No normal WordPress search results were found for this query.', 'geweb-ai-search'); ?></p>
                    <?php endif; ?>
                    <?php wp_reset_postdata(); ?>
                <?php endif; ?>
            </div>
        </section>
        <?php
    }

    private function shouldLoadFullFrontendWorkspace(): bool {
        if (is_admin()) {
            return false;
        }

        if (FrontendAiContext::getFrontendAiInterface() !== 'fullscreen') {
            return true;
        }

        if (FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive)) {
            return true;
        }

        return $this->hasCurrentPageShortcode();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildFrontendLauncherConfig(string $searchWithAiLabel): array {
        return [
            'frontend_ai_interface' => FrontendAiContext::getFrontendAiInterface(),
            'frontend_ai_page_url' => FrontendAiContext::getFrontendAiPageUrl(),
            'frontend_ai_exit_url' => FrontendAiContext::getFrontendAiExitUrl(),
            'is_frontend_ai_page' => FrontendAiContext::isFrontendAiPageRequest($this->shortcodePageViewActive),
            'i18n' => [
                'openAiSearch' => __('Open AI Search', 'geweb-ai-search'),
                'searchWithAi' => $searchWithAiLabel,
            ],
        ];
    }

    private function hasCurrentPageShortcode(): bool {
        if (!is_singular()) {
            return false;
        }

        $post = get_post();
        if (!$post instanceof \WP_Post) {
            return false;
        }

        return has_shortcode((string) $post->post_content, 'geweb_ai_search');
    }
}
