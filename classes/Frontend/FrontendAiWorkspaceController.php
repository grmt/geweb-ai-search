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
        $provider = ProviderFactory::make();
        $locale = function_exists('determine_locale') ? (string) determine_locale() : get_locale();
        $searchWithAiLabel = $this->getSearchWithAiLabel($locale);
        $frontendModelConfig = ($this->getFrontendAiChatModelConfig)($provider);
        $models = is_array($frontendModelConfig['models'] ?? null) ? $frontendModelConfig['models'] : [];
        $selectedModel = is_string($frontendModelConfig['selected_model'] ?? null) ? $frontendModelConfig['selected_model'] : '';
        if ($selectedModel !== '' && !in_array($selectedModel, $models, true) && method_exists($provider, 'getDefaultModel')) {
            array_unshift($models, $selectedModel);
            $models = array_values(array_unique($models));
        }

        wp_enqueue_script(
            'geweb-ai-search-sources',
            GEWEB_AI_SEARCH_URL . 'assets/js/ai-sources.js',
            ['jquery'],
            GEWEB_AI_SEARCH_VERSION,
            true
        );

        wp_enqueue_script(
            'geweb-ai-search',
            GEWEB_AI_SEARCH_URL . 'assets/script.js',
            ['jquery', 'geweb-ai-search-sources'],
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
            'ajax_url' => admin_url('admin-ajax.php'),
            'site_url' => home_url('/'),
            'models' => array_values($models),
            'selected_model' => $selectedModel,
            'prompt_descriptors' => ($this->getFrontendPromptDescriptors)($provider, $models, $selectedModel),
            'frontend_ai_interface' => FrontendAiContext::getFrontendAiInterface(),
            'frontend_ai_page_url' => FrontendAiContext::getCurrentFrontendAiPageUrl(),
            'frontend_ai_exit_url' => FrontendAiContext::getFrontendAiExitUrl(),
            'frontend_ai_manage_conversations_url' => current_user_can('manage_options') ? ($this->getTabUrl)('conversations') : '',
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
                'couldNotStart' => __('Could not start the AI search. Please try again.', 'geweb-ai-search'),
                'connectionError' => __('Connection error. Please try again.', 'geweb-ai-search'),
                'answerError' => __('Error: Unable to get response', 'geweb-ai-search'),
                'responseDetails' => __('Response details', 'geweb-ai-search'),
                'clickAnswerForDetails' => __('Click the answer to show response details.', 'geweb-ai-search'),
                'hideDetails' => __('Hide details', 'geweb-ai-search'),
                'showDetails' => __('Show details', 'geweb-ai-search'),
                'earlierTrimmed' => __('Earlier messages were trimmed to keep the chat context compact.', 'geweb-ai-search'),
                'noChatsYet' => __('No chats yet.', 'geweb-ai-search'),
                'copyAnswer' => __('Copy answer', 'geweb-ai-search'),
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
                'copied' => __('Copied', 'geweb-ai-search'),
                'copyFailed' => __('Could not copy', 'geweb-ai-search'),
                'savedChat' => __('Saved chat', 'geweb-ai-search'),
                'untitledConversation' => __('Untitled chat', 'geweb-ai-search'),
                'noSourcesYet' => __('No source links yet.', 'geweb-ai-search'),
                'renameConversation' => __('Rename chat', 'geweb-ai-search'),
                'removeConversationConfirm' => __('Remove this chat from the current search context?', 'geweb-ai-search'),
                'mentionedInAnswer' => __('Mentioned in answer', 'geweb-ai-search'),
                'newChat' => __('New chat', 'geweb-ai-search'),
                'linksToPages' => __('Links to pages and documents used in the answer.', 'geweb-ai-search'),
                'showResults' => __('Show results', 'geweb-ai-search'),
                'hideResults' => __('Hide results', 'geweb-ai-search'),
                'modelLabel' => __('Model', 'geweb-ai-search'),
                'manageConversations' => __('Manage chats', 'geweb-ai-search'),
                'searchResultsIntro' => __('Use your normal site search above to update these WordPress results without leaving the AI workspace.', 'geweb-ai-search'),
            ],
        ]);
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
                <?php $this->renderFrontendAiSearchResultsPanel(); ?>
            <?php endif; ?>
            <div class="geweb-ai-workspace">
                <aside class="geweb-ai-sidebar" aria-label="<?php echo esc_attr__('Chat panel', 'geweb-ai-search'); ?>">
                    <div class="geweb-ai-overview-header">
                        <div class="geweb-ai-panel-heading">
                            <div class="geweb-ai-panel-heading-main">
                                <div class="geweb-ai-panel-title geweb-ai-panel-title--inline"><?php echo esc_html__('Chat', 'geweb-ai-search'); ?></div>
                                <div class="geweb-ai-panel-heading-actions geweb-ai-panel-heading-actions--conversation">
                                    <button type="button" class="button button-small geweb-ai-new-conversation geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" aria-label="<?php echo esc_attr__('New chat', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('New chat', 'geweb-ai-search'); ?>">
                                        <span class="geweb-ai-overview-action-icon" aria-hidden="true">+</span>
                                        <span class="geweb-ai-overview-action-label"><?php echo esc_html__('New', 'geweb-ai-search'); ?></span>
                                    </button>
                                    <?php if ($showManageLink && current_user_can('manage_options')): ?>
                                        <a class="button button-small geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" href="<?php echo esc_url(($this->getTabUrl)('conversations')); ?>" aria-label="<?php echo esc_attr__('Manage chats', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Manage chats', 'geweb-ai-search'); ?>">
                                            <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                                            <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Manage', 'geweb-ai-search'); ?></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="button" class="button button-small geweb-ai-panel-collapse" data-panel-toggle="left" aria-expanded="true" aria-label="<?php echo esc_attr__('Collapse chats panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Collapse chats panel', 'geweb-ai-search'); ?>">
                                <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">◀</span>
                            </button>
                        </div>
                    </div>
                    <div class="geweb-ai-current-conversation">
                        <div class="geweb-ai-current-conversation-header">
                            <div class="geweb-ai-current-conversation-label"><?php echo esc_html__('Current chat', 'geweb-ai-search'); ?></div>
                            <div class="geweb-ai-current-conversation-actions">
                                <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-copy-conversation" aria-label="<?php echo esc_attr__('Copy chat', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Copy chat', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">⧉</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Copy', 'geweb-ai-search'); ?></span>
                                </button>
                                <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-rename-conversation" aria-label="<?php echo esc_attr__('Rename chat', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Rename chat', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">✎</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Rename', 'geweb-ai-search'); ?></span>
                                </button>
                                <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-first geweb-ai-current-conversation-action" id="geweb-ai-delete-conversation" aria-label="<?php echo esc_attr__('Remove chat', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Remove chat', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">−</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Remove', 'geweb-ai-search'); ?></span>
                                </button>
                            </div>
                        </div>
                        <div id="geweb-ai-current-conversation-summary" class="geweb-ai-current-conversation-summary"><?php echo esc_html__('Untitled chat', 'geweb-ai-search'); ?></div>
                    </div>
                    <div id="geweb-ai-conversation-overview" class="geweb-ai-conversation-overview"></div>
                </aside>
                <button type="button" class="button button-small geweb-ai-panel-collapse geweb-ai-panel-reopen geweb-ai-panel-reopen--left" data-panel-toggle="left" aria-expanded="true" aria-label="<?php echo esc_attr__('Expand chats panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Expand chats panel', 'geweb-ai-search'); ?>">
                    <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">▶</span>
                </button>
                <div class="geweb-ai-pane-resizer geweb-ai-pane-resizer--left" data-resize-target="left" aria-orientation="vertical" aria-label="<?php echo esc_attr__('Resize chats panel', 'geweb-ai-search'); ?>"></div>
                <div class="geweb-ai-main-panel">
                    <div class="answer-box"></div>
                    <div class="question-box">
                        <div class="geweb-ai-question-toolbar">
                            <div class="geweb-ai-question-summary" aria-live="polite">
                                <span class="geweb-ai-question-summary-item">
                                    <span class="geweb-ai-question-summary-label"><?php echo esc_html__('Model:', 'geweb-ai-search'); ?></span>
                                    <span id="geweb-ai-current-model-display" class="geweb-ai-question-summary-value"><?php echo esc_html($selectedModel); ?></span>
                                </span>
                                <span class="geweb-ai-question-summary-item">
                                    <span class="geweb-ai-question-summary-label"><?php echo esc_html__('Prompt:', 'geweb-ai-search'); ?></span>
                                    <span id="geweb-ai-current-prompt-display" class="geweb-ai-question-summary-value geweb-ai-question-summary-value--prompt" title="<?php echo esc_attr($currentPromptInstruction); ?>"><?php echo esc_html($currentPromptName); ?></span>
                                </span>
                            </div>
                            <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-only geweb-ai-question-settings-button" id="geweb-ai-toggle-temp-settings" aria-expanded="false" aria-controls="geweb-ai-temporary-settings-panel" aria-label="<?php echo esc_attr__('Next question settings', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Next question settings', 'geweb-ai-search'); ?>">
                                <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                            </button>
                        </div>
                        <div class="geweb-ai-temporary-settings-panel" id="geweb-ai-temporary-settings-panel" hidden>
                            <div class="geweb-ai-temporary-settings-header">
                                <div class="geweb-ai-temporary-settings-heading">
                                    <div class="geweb-ai-temporary-settings-title"><?php echo esc_html__('Next question settings', 'geweb-ai-search'); ?></div>
                                    <p class="geweb-ai-temporary-settings-note"><?php echo esc_html__('Applies to the next questions until you reset it.', 'geweb-ai-search'); ?></p>
                                </div>
                                <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-only geweb-ai-temporary-settings-close" id="geweb-ai-close-temp-settings" aria-label="<?php echo esc_attr__('Close settings', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Close settings', 'geweb-ai-search'); ?>">×</button>
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
                                        <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-temporary-reset-button geweb-ai-temporary-inline-action" id="geweb-ai-reset-temp-model"><?php echo esc_html__('Use default model', 'geweb-ai-search'); ?></button>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="geweb-ai-temporary-settings-row">
                                <label for="geweb-ai-temporary-prompt" class="geweb-ai-model-selector-label"><?php echo esc_html__('Prompt', 'geweb-ai-search'); ?></label>
                                <div class="geweb-ai-temporary-settings-controls geweb-ai-temporary-settings-controls--prompt">
                                    <div id="geweb-ai-temporary-prompt-summary" class="geweb-ai-temporary-prompt-summary"><?php echo esc_html($currentPromptName); ?></div>
                                    <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-temporary-inline-action" id="geweb-ai-toggle-prompt-editor"><?php echo esc_html__('Edit prompt', 'geweb-ai-search'); ?></button>
                                    <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-temporary-reset-button geweb-ai-temporary-inline-action" id="geweb-ai-reset-temp-prompt"><?php echo esc_html__('Use default prompt', 'geweb-ai-search'); ?></button>
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
                <aside class="geweb-ai-sources-panel" aria-label="<?php echo esc_attr__('Source references panel', 'geweb-ai-search'); ?>">
                    <div class="geweb-ai-panel-heading">
                        <div class="geweb-ai-panel-heading-main">
                            <div class="geweb-ai-panel-title"><?php echo esc_html__('Source references', 'geweb-ai-search'); ?></div>
                            <div class="geweb-ai-panel-heading-actions">
                                <?php if (current_user_can('manage_options')): ?>
                                    <a class="button button-small geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" href="<?php echo esc_url(($this->getTabUrl)('documents')); ?>" aria-label="<?php echo esc_attr__('Manage source references', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Manage source references', 'geweb-ai-search'); ?>">
                                        <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                                        <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Manage', 'geweb-ai-search'); ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button type="button" class="button button-small geweb-ai-panel-collapse" data-panel-toggle="right" aria-expanded="true" aria-label="<?php echo esc_attr__('Collapse sources panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Collapse sources panel', 'geweb-ai-search'); ?>">
                            <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">▶</span>
                        </button>
                    </div>
                    <p class="geweb-ai-sources-help"><?php echo esc_html__('Pages, posts, and documents referenced by the current answer or restored chat.', 'geweb-ai-search'); ?></p>
                    <div id="geweb-ai-sources" class="geweb-ai-sources"></div>
                </aside>
                <button type="button" class="button button-small geweb-ai-panel-collapse geweb-ai-panel-reopen geweb-ai-panel-reopen--right" data-panel-toggle="right" aria-expanded="true" aria-label="<?php echo esc_attr__('Expand sources panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Expand sources panel', 'geweb-ai-search'); ?>">
                    <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">◀</span>
                </button>
            </div>
        </<?php echo esc_html($tagName); ?>>
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
        $initialHeight = isset($this->renderOverrides['search_results_initial_height'])
            ? (int) $this->renderOverrides['search_results_initial_height']
            : 70;
        $initialHeight = max(0, min(100, $initialHeight));
        ?>
        <section class="geweb-ai-search-results-panel">
            <div class="geweb-ai-search-results-header">
                <div class="geweb-ai-panel-heading geweb-ai-panel-heading--search-results">
                    <div class="geweb-ai-panel-title geweb-ai-panel-title--inline"><?php echo esc_html__('Search Results', 'geweb-ai-search'); ?></div>
                    <?php if ($query !== ''): ?>
                        <button
                            type="button"
                            class="geweb-ai-search-results-info"
                            aria-label="<?php echo esc_attr__('These are the regular WordPress search results for the current query.', 'geweb-ai-search'); ?>"
                            title="<?php echo esc_attr__('These are the regular WordPress search results for the current query.', 'geweb-ai-search'); ?>"
                        >
                            <span class="geweb-ai-search-results-info-icon" aria-hidden="true">i</span>
                        </button>
                    <?php endif; ?>
                    <button type="button" class="button button-small geweb-ai-panel-collapse" data-panel-toggle="search" aria-expanded="<?php echo $initialHeight > 0 ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr($initialHeight > 0 ? __('Collapse classic search results', 'geweb-ai-search') : __('Expand classic search results', 'geweb-ai-search')); ?>" title="<?php echo esc_attr($initialHeight > 0 ? __('Collapse classic search results', 'geweb-ai-search') : __('Expand classic search results', 'geweb-ai-search')); ?>">
                        <span class="geweb-ai-panel-collapse-icon" aria-hidden="true"><?php echo $initialHeight > 0 ? '▴' : '▾'; ?></span>
                    </button>
                </div>
            </div>
            <div class="geweb-ai-search-results-content">
                <?php if ($query === ''): ?>
                    <p class="geweb-ai-empty-panel"><?php echo esc_html__('Use the normal site search in the header to see matching WordPress results here without leaving the AI workspace.', 'geweb-ai-search'); ?></p>
                <?php else: ?>
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
}
