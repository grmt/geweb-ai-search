<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

$fieldHelper = new \Geweb\AISearch\AdminSettingFieldHelper();
        ?>
        <div class="wrap">
            <h1>Workspace AI Search</h1>
            <div id="geweb-ai-admin-preload-state" data-needs-preload="<?php echo !empty($adminPanelsNeedPreload) ? '1' : '0'; ?>" hidden></div>
            <div
                id="geweb-ai-admin-cache-state"
                data-prompts-revision="<?php echo esc_attr((string) ($adminViewCacheState['prompts'] ?? '')); ?>"
                data-files-revision="<?php echo esc_attr((string) ($adminViewCacheState['files'] ?? '')); ?>"
                data-chats-revision="<?php echo esc_attr((string) ($adminViewCacheState['chats'] ?? '')); ?>"
                hidden
            ></div>
            <?php if ($conflictNotice !== ''): ?>
                <div class="notice notice-error"><p><?php echo esc_html((string) $conflictNotice); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($pluginUpdateGuardActive)): ?>
                <div class="notice notice-warning"><p><?php echo esc_html((string) $pluginUpdateGuardMessage); ?></p></div>
            <?php endif; ?>
            <?php if (!empty($workspaceSelectorOptions)): ?>
                <div style="margin:0 0 16px; padding:12px; background:#fff; border:1px solid #dcdcde; max-width:720px;">
                    <label for="geweb-workspace-scope-selector"><strong>Configuration workspace</strong></label><br>
                    <select id="geweb-workspace-scope-selector" onchange="if (this.value) { window.location = this.value; }" class="regular-text" style="max-width:360px;">
                        <?php foreach ($workspaceSelectorOptions as $workspaceOption): ?>
                            <?php
                            $workspaceOptionId = (string) ($workspaceOption['workspace_id'] ?? '');
                            $workspaceOptionName = (string) ($workspaceOption['name'] ?? $workspaceOptionId);
                            $workspaceOptionUrl = add_query_arg(
                                [
                                    'page' => 'geweb-ai-search',
                                    'geweb_tab' => $activeTab,
                                    'geweb_workspace_id' => $workspaceOptionId,
                                ],
                                admin_url('admin.php')
                            );
                            ?>
                            <option value="<?php echo esc_url($workspaceOptionUrl); ?>" <?php selected($activeWorkspaceId, $workspaceOptionId); ?>>
                                <?php echo esc_html($workspaceOptionName . ' [' . $workspaceOptionId . ']'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" style="margin:8px 0 0;">
                        API key, selected model, Gemini store, prompt overrides, and frontend-sharing toggle are now stored per workspace. Switch workspace here to edit its configuration.
                    </p>
                </div>
                <?php if (!empty($activeWorkspaceId)): ?>
                    <div style="margin:0 0 16px; padding:12px 14px; background:#f6f7f7; border-left:4px solid #2271b1; max-width:720px;">
                        <strong>Editing workspace configuration:</strong>
                        <?php echo esc_html((string) ($activeWorkspaceName !== '' ? $activeWorkspaceName : $activeWorkspaceId)); ?>
                        <code style="margin-left:6px;"><?php echo esc_html((string) $activeWorkspaceId); ?></code>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <h2 class="nav-tab-wrapper" style="margin-bottom:16px;">
                <a href="<?php echo esc_url((string) $generalTabUrl); ?>" class="nav-tab <?php echo $activeTab === 'general' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="general">General</a>
                <a href="<?php echo esc_url((string) $promptsTabUrl); ?>" class="nav-tab <?php echo $activeTab === 'prompts' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="prompts">Prompts</a>
                <a href="<?php echo esc_url((string) $documentsTabUrl); ?>" class="nav-tab <?php echo $activeTab === 'documents' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="documents">Files</a>
                <a href="<?php echo esc_url((string) $storesTabUrl); ?>" class="nav-tab <?php echo $activeTab === 'stores' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="stores">Gemini Stores</a>
                <a href="<?php echo esc_url((string) $conversationsTabUrl); ?>" class="nav-tab <?php echo $activeTab === 'conversations' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="conversations">Chats</a>
                <a href="<?php echo esc_url((string) $workspacesTabUrl); ?>" class="nav-tab <?php echo $activeTab === 'workspaces' ? 'nav-tab-active' : ''; ?>" data-geweb-tab="workspaces">Workspaces</a>
            </h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" accept-charset="UTF-8">
                <input type="hidden" name="action" value="geweb_save">
                <input type="hidden" name="geweb_workspace_id" value="<?php echo esc_attr((string) $activeWorkspaceId); ?>">
                <input type="hidden" name="geweb_ai_search_referenced_document_targets" id="geweb_ai_search_referenced_document_targets" value="">
                <input type="hidden" name="geweb_ai_search_group_revision" id="geweb_ai_search_group_revision" value="<?php echo esc_attr((string) $groupDataRevision); ?>">
                <?php wp_nonce_field('geweb_ai_search_save_settings'); ?>

                <div class="geweb-settings-tab-panel" data-geweb-tab-panel="general" <?php echo $activeTab === 'general' ? '' : (string) $inlineStyleHidden; ?>>
                <table class="form-table">
                    <tr>
                        <th><label for="geweb_ai_search_provider">AI Provider:</label></th>
                        <td>
                            <select name="geweb_ai_search_provider" id="geweb_ai_search_provider" disabled>
                                <?php foreach ($availableProviders as $providerKey => $providerLabel): ?>
                                    <option value="<?php echo esc_attr((string) $providerKey); ?>" <?php selected($selectedProvider, $providerKey); ?>>
                                        <?php echo esc_html((string) $providerLabel); ?>
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
                                <button type="button" class="button" id="geweb-toggle-api-key-visibility" aria-label="Show API key" aria-pressed="false" title="Show API key" <?php echo (string) $inlineStyleHidden; ?>>
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
                                    ? DateDisplay::formatDateTime(intval($connectionStatus['timestamp']))
                                    : '';
                                if ($apiStatus === 'ok') {
                                    $apiColor = (string) $statusColorSuccess;
                                    $apiLabel = 'Valid';
                                } elseif ($apiStatus === 'missing') {
                                    $apiColor = (string) $statusColorNeutral;
                                    $apiLabel = 'Missing';
                                } else {
                                    $apiColor = (string) $statusColorError;
                                    $apiLabel = 'Invalid';
                                }
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
                                <?php foreach ($dropdownModels as $model): ?>
                                    <?php
                                    $modelStatusEntry = $modelStatuses[$model] ?? null;
                                    $isFailedModel = is_array($modelStatusEntry) && (($modelStatusEntry['status'] ?? '') === 'failed');
                                    $isTimeoutModel = is_array($modelStatusEntry) && (($modelStatusEntry['status'] ?? '') === 'timeout');
                                    $modelStatusAttribute = 'ok';
                                    $modelStatusStyle = '';
                                    if ($isFailedModel) {
                                        $modelStatusAttribute = 'failed';
                                        $modelStatusStyle = 'color:#b32d2e;';
                                    } elseif ($isTimeoutModel) {
                                        $modelStatusAttribute = 'timeout';
                                        $modelStatusStyle = 'color:#dba617;';
                                    }
                                    ?>
                                    <option
                                        value="<?php echo esc_attr((string) $model); ?>"
                                        data-model-status="<?php echo esc_attr($modelStatusAttribute); ?>"
                                        style="<?php echo esc_attr($modelStatusStyle); ?>"
                                        <?php if ($isFailedModel || $isTimeoutModel): ?>
                                            title="<?php echo esc_attr((string) ($modelStatusEntry['message'] ?? '')); ?>"
                                        <?php endif; ?>
                                        <?php selected($selectedModel, $model); ?>
                                    >
                                        <?php echo esc_html((string) $model); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span style="display:inline-flex; gap:8px; margin-left:8px; vertical-align:middle;">
                                <button type="button" class="button" id="geweb-refresh-models-button">Refresh models</button>
                                <button type="button" class="button" id="geweb-test-selected-model">Test selected model</button>
                            </span>
                            <p class="description" id="geweb-ai-model-refresh-status" <?php echo (string) $inlineStyleHidden; ?>></p>
                            <p class="description" style="margin-top:8px;">
                                Gemini model info:
                                <a href="<?php echo esc_url((string) $geminiChangelogUrl); ?>" target="_blank" rel="noopener noreferrer">Release notes</a>
                                |
                                <a href="<?php echo esc_url((string) $geminiDeprecationsUrl); ?>" target="_blank" rel="noopener noreferrer">Deprecations</a>
                            </p>
                            <?php
                            (new AdminPageSections(new ConversationManager()))->renderModelDiagnosticsSection([
                                'models' => $models,
                                'modelStatuses' => $modelStatuses,
                                'selectedModel' => $selectedModel,
                                'defaultModel' => $defaultModel,
                                'officialLatestAliases' => $officialLatestAliases,
                                'workingModelHints' => $workingModelHints,
                                'latestModelHints' => $latestModelHints,
                                'statusColorError' => $statusColorError,
                                'statusColorSuccess' => $statusColorSuccess,
                            ]);
                            ?>
                        </td>
                    </tr>

                <tr>
                    <th><label for="geweb_aisearch_timeout_flash">API Timeouts:</label></th>
                    <td>
                        <?php
                        $systemRetries = is_numeric(get_option(\Geweb\AISearch\Gemini::OPTION_SYSTEM_RETRIES, ''))
                            ? max(1, min(4, (int) get_option(\Geweb\AISearch\Gemini::OPTION_SYSTEM_RETRIES, '')))
                            : \Geweb\AISearch\Gemini::DEFAULT_SYSTEM_RETRIES;
                        $humanRetries = is_numeric(get_option(\Geweb\AISearch\Gemini::OPTION_HUMAN_RETRIES, ''))
                            ? max(0, min(4, (int) get_option(\Geweb\AISearch\Gemini::OPTION_HUMAN_RETRIES, '')))
                            : \Geweb\AISearch\Gemini::DEFAULT_HUMAN_RETRIES;
                        $maxAttempts = $systemRetries * (1 + $humanRetries);
                        $frontendAjaxTimeoutBufferSeconds = 60;
                        $selectedModel = (string) ($selectedModel ?? '');
                        $selectedModelIsPro = strpos(strtolower($selectedModel), 'pro') !== false;
                        $selectedModelTimeoutDefaultSeconds = $selectedModelIsPro
                            ? \Geweb\AISearch\Gemini::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS
                            : \Geweb\AISearch\Gemini::DEFAULT_HTTP_TIMEOUT_SECONDS;
                        $selectedModelTimeoutSeconds = $selectedModelIsPro
                            ? (int) get_option(\Geweb\AISearch\Gemini::OPTION_TIMEOUT_PRO, \Geweb\AISearch\Gemini::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS)
                            : (int) get_option(\Geweb\AISearch\Gemini::OPTION_TIMEOUT_FLASH, \Geweb\AISearch\Gemini::DEFAULT_HTTP_TIMEOUT_SECONDS);
                        if ($selectedModelTimeoutSeconds <= 0) {
                            $selectedModelTimeoutSeconds = $selectedModelTimeoutDefaultSeconds;
                        }
                        $frontendAjaxTimeoutSeconds = max(30, $selectedModelTimeoutSeconds + $frontendAjaxTimeoutBufferSeconds);
                        $timeoutFlashField = $fieldHelper->numberFieldConfig((int) get_option(\Geweb\AISearch\Gemini::OPTION_TIMEOUT_FLASH, \Geweb\AISearch\Gemini::DEFAULT_HTTP_TIMEOUT_SECONDS), \Geweb\AISearch\Gemini::DEFAULT_HTTP_TIMEOUT_SECONDS);
                        $timeoutProField = $fieldHelper->numberFieldConfig((int) get_option(\Geweb\AISearch\Gemini::OPTION_TIMEOUT_PRO, \Geweb\AISearch\Gemini::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS), \Geweb\AISearch\Gemini::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS);
                        $systemRetriesField = $fieldHelper->numberFieldConfig($systemRetries, \Geweb\AISearch\Gemini::DEFAULT_SYSTEM_RETRIES);
                        $humanRetriesField = $fieldHelper->numberFieldConfig($humanRetries, \Geweb\AISearch\Gemini::DEFAULT_HUMAN_RETRIES);
                        $conversationTrimMessageLimitField = $fieldHelper->numberFieldConfig($conversationTrimMessageLimit, \Geweb\AISearch\FrontendAiContext::DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT);
                        $conversationTrimCharLimitField = $fieldHelper->numberFieldConfig($conversationTrimCharLimit, \Geweb\AISearch\FrontendAiContext::DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT);
                        $localConversationArchiveLimitField = $fieldHelper->numberFieldConfig($localConversationArchiveLimit, \Geweb\AISearch\FrontendAiContext::DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT);
                        $storedContextMessageLimitField = $fieldHelper->numberFieldConfig($storedContextMessageLimit, \Geweb\AISearch\FrontendAiContext::DEFAULT_STORED_CONTEXT_MESSAGE_LIMIT);
                        $storedContextCharLimitField = $fieldHelper->numberFieldConfig($storedContextCharLimit, \Geweb\AISearch\FrontendAiContext::DEFAULT_STORED_CONTEXT_CHAR_LIMIT);
                        $promptHistoryLimitField = $fieldHelper->numberFieldConfig($promptHistoryLimit, 10);
                        $timeoutFields = [
                            [
                                'id' => 'geweb_aisearch_timeout_flash',
                                'name' => \Geweb\AISearch\Gemini::OPTION_TIMEOUT_FLASH,
                                'label' => 'Standard/Flash Model Timeout (seconds)',
                                'default_label' => $timeoutFlashField['default_label'] . ' seconds',
                                'value' => $timeoutFlashField['value'],
                                'class' => $timeoutFlashField['class'],
                                'min' => 15,
                                'max' => 300,
                                'step' => 1,
                            ],
                            [
                                'id' => 'geweb_aisearch_timeout_pro',
                                'name' => \Geweb\AISearch\Gemini::OPTION_TIMEOUT_PRO,
                                'label' => 'Pro Model Timeout (seconds)',
                                'default_label' => $timeoutProField['default_label'] . ' seconds',
                                'value' => $timeoutProField['value'],
                                'class' => $timeoutProField['class'],
                                'min' => 15,
                                'max' => 300,
                                'step' => 1,
                            ],
                            [
                                'id' => 'geweb_aisearch_gemini_system_retries',
                                'name' => \Geweb\AISearch\Gemini::OPTION_SYSTEM_RETRIES,
                                'label' => 'Automatic retries per request',
                                'default_label' => $systemRetriesField['default_label'],
                                'value' => $systemRetriesField['value'],
                                'class' => $systemRetriesField['class'],
                                'min' => 1,
                                'max' => 4,
                                'step' => 1,
                            ],
                            [
                                'id' => 'geweb_aisearch_gemini_human_retries',
                                'name' => \Geweb\AISearch\Gemini::OPTION_HUMAN_RETRIES,
                                'label' => 'Manual retry rounds allowed after timeouts',
                                'default_label' => $humanRetriesField['default_label'],
                                'value' => $humanRetriesField['value'],
                                'class' => $humanRetriesField['class'],
                                'min' => 0,
                                'max' => 4,
                                'step' => 1,
                            ],
                        ];
                        $compactionFields = [
                            [
                                'id' => 'geweb_ai_search_conversation_trim_message_limit',
                                'name' => 'geweb_ai_search_conversation_trim_message_limit',
                                'label' => 'Start trimming request context after this many messages',
                                'default_label' => $conversationTrimMessageLimitField['default_label'],
                                'value' => $conversationTrimMessageLimitField['value'],
                                'class' => $conversationTrimMessageLimitField['class'],
                                'min' => 2,
                                'max' => null,
                                'step' => 1,
                            ],
                            [
                                'id' => 'geweb_ai_search_conversation_trim_char_limit',
                                'name' => 'geweb_ai_search_conversation_trim_char_limit',
                                'label' => 'Maximum request-context size in characters',
                                'default_label' => $conversationTrimCharLimitField['default_label'],
                                'value' => $conversationTrimCharLimitField['value'],
                                'class' => $conversationTrimCharLimitField['class'],
                                'min' => 500,
                                'max' => null,
                                'step' => 100,
                            ],
                            [
                                'id' => 'geweb_ai_search_local_conversation_archive_limit',
                                'name' => 'geweb_ai_search_local_conversation_archive_limit',
                                'label' => 'Saved conversations to show in the chat sidebar',
                                'default_label' => $localConversationArchiveLimitField['default_label'],
                                'value' => $localConversationArchiveLimitField['value'],
                                'class' => $localConversationArchiveLimitField['class'],
                                'min' => 1,
                                'max' => null,
                                'step' => 1,
                            ],
                            [
                                'id' => 'geweb_ai_search_stored_context_message_limit',
                                'name' => 'geweb_ai_search_stored_context_message_limit',
                                'label' => 'Hard max stored context messages (definitive purge)',
                                'default_label' => $storedContextMessageLimitField['default_label'],
                                'value' => $storedContextMessageLimitField['value'],
                                'class' => $storedContextMessageLimitField['class'],
                                'min' => 10,
                                'max' => 500,
                                'step' => 1,
                            ],
                            [
                                'id' => 'geweb_ai_search_stored_context_char_limit',
                                'name' => 'geweb_ai_search_stored_context_char_limit',
                                'label' => 'Hard max stored context characters (definitive purge)',
                                'default_label' => $storedContextCharLimitField['default_label'],
                                'value' => $storedContextCharLimitField['value'],
                                'class' => $storedContextCharLimitField['class'],
                                'min' => 5000,
                                'max' => 500000,
                                'step' => 1000,
                            ],
                        ];
                        ?>
                        <?php foreach ($timeoutFields as $index => $field): ?>
                            <p style="margin-top:<?php echo $index === 0 ? '0' : '12px'; ?>;">
                                <label for="<?php echo esc_attr($field['id']); ?>"><strong><?php echo esc_html($field['label']); ?></strong> <span class="description">Default: <?php echo esc_html($field['default_label']); ?></span></label><br>
                                <input type="number" id="<?php echo esc_attr($field['id']); ?>" name="<?php echo esc_attr($field['name']); ?>" min="<?php echo esc_attr((string) $field['min']); ?>" max="<?php echo esc_attr((string) $field['max']); ?>" step="<?php echo esc_attr((string) $field['step']); ?>" value="<?php echo esc_attr($field['value']); ?>" class="<?php echo esc_attr($field['class']); ?>">
                            </p>
                        <?php endforeach; ?>
                        <p class="description">Maximum time WordPress will wait for a response from the Gemini API. Complex queries or large document contexts take longer to process. Defaults are shown next to each field, and non-default values are highlighted.</p>
                        <p class="description">Retries are tracked per same question + model + prompt combination. The total maximum attempts for the same combination are currently <strong><?php echo esc_html((string) $maxAttempts); ?></strong>. Default retries are <strong><?php echo esc_html((string) \Geweb\AISearch\Gemini::DEFAULT_SYSTEM_RETRIES); ?></strong> automatic and <strong><?php echo esc_html((string) \Geweb\AISearch\Gemini::DEFAULT_HUMAN_RETRIES); ?></strong> manual rounds.</p>
                        <p class="description">Current frontend browser wait for the selected model (<code><?php echo esc_html($selectedModel !== '' ? $selectedModel : 'default'); ?></code>) is about <strong><?php echo esc_html((string) $frontendAjaxTimeoutSeconds); ?> seconds</strong> with the current setting, which includes the <strong><?php echo esc_html((string) $frontendAjaxTimeoutBufferSeconds); ?> second</strong> buffer on top of the selected model timeout.</p>
                    </td>
                </tr>

                    <tr>
                        <th><label for="geweb_ai_search_frontend_ai_interface">AI Search Interface:</label></th>
                        <td>
                            <select name="geweb_ai_search_frontend_ai_interface" id="geweb_ai_search_frontend_ai_interface">
                                <option value="modal" <?php selected($frontendAiInterface, 'modal'); ?>>Modal chat</option>
                                <option value="fullscreen" <?php selected($frontendAiInterface, 'fullscreen'); ?>>Full-screen workspace</option>
                            </select>
                            <p class="description">Choose how the frontend AI search opens. Modal chat keeps a compact conversation window. Full-screen workspace shows conversation history on the left, the chat in the middle, and sources on the right.</p>
                            <p class="description">Preferred page-based setup: use the automatically created AI Search page or place the shortcode <code>[geweb_ai_search]</code> on a normal WordPress page that should own the menu and layout.</p>
                            <p class="description">Supported shortcode attributes: <code>title</code>, <code>search_results="show|hide"</code>, <code>search_results_height="0-100"</code>, <code>manage_link="show|hide"</code>.</p>
                            <?php if ($frontendAiPageId > 0 && $frontendAiPageUrl !== ''): ?>
                                <p class="description">Current AI Search page: <a href="<?php echo esc_url((string) $frontendAiPageUrl); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html((string) ($frontendAiPageTitle ?: 'AI Search')); ?></a></p>
                            <?php endif; ?>
                            <p style="margin-top:12px;">
                                <label for="geweb_ai_search_share_gemini_config_with_frontend" style="display:inline-flex; align-items:flex-start; gap:8px;">
                                    <input type="checkbox" id="geweb_ai_search_share_gemini_config_with_frontend" name="geweb_ai_search_share_gemini_config_with_frontend" value="1" <?php checked(!empty($shareGeminiConfigWithFrontend)); ?>>
                                    <span>
                                        <strong>Share Gemini search configuration with frontend users</strong><br>
                                        <span class="description" style="display:inline;">Allows non-admin frontend users to use the shared Gemini API key, shared file store, and shared prompt/model search configuration in read-only mode. Admin-only indexing, upload, and store-management actions remain restricted.</span>
                                    </span>
                                </label>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_conversation_trim_message_limit">Compaction:</label></th>
                        <td>
                            <?php foreach ($compactionFields as $index => $field): ?>
                                <p style="margin-top:<?php echo $index === 0 ? '0' : '12px'; ?>;">
                                    <label for="<?php echo esc_attr($field['id']); ?>"><strong><?php echo esc_html($field['label']); ?></strong> <span class="description">Default: <?php echo esc_html($field['default_label']); ?></span></label><br>
                                    <input type="number" id="<?php echo esc_attr($field['id']); ?>" name="<?php echo esc_attr($field['name']); ?>" min="<?php echo esc_attr((string) $field['min']); ?>"<?php echo $field['max'] !== null ? ' max="' . esc_attr((string) $field['max']) . '"' : ''; ?> step="<?php echo esc_attr((string) $field['step']); ?>" value="<?php echo esc_attr($field['value']); ?>" class="<?php echo esc_attr($field['class']); ?>">
                                </p>
                            <?php endforeach; ?>
                        <p class="description">Number of saved chats to show in the frontend chat sidebar at once. Full chat history is stored in WordPress, while only a shorter trimmed context is sent to the AI model.</p>
                        <p class="description">Stored context limits are validated and clamped on save and again at runtime (messages: 10-500, characters: 5000-500000), so values cannot become too small or too large.</p>
                        <p class="description">Defaults are shown next to each field, and any non-default value is highlighted.</p>
                    </td>
                </tr>

                    <tr>
                        <th>Select Post Types for AI Search:</th>
                        <td>
                            <?php foreach ($allPostTypes as $postType): ?>
                            <p>
                                <label>
                                    <input type="checkbox" name="geweb_ai_search_post_types[]" value="<?php echo esc_attr((string) $postType->name); ?>" <?php checked(in_array($postType->name, $postTypes), true); ?>>
                                    <?php echo esc_html((string) $postType->label); ?>
                                </label>
                            </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_include_referenced_documents">Referenced Documents:</label></th>
                        <td>
                            <label for="geweb_ai_search_include_referenced_documents">
                                <input type="checkbox" id="geweb_ai_search_include_referenced_documents" name="geweb_ai_search_include_referenced_documents" value="1" <?php checked($includeReferencedDocuments); ?>>
                                Upload referenced local documents together with indexed pages
                            </label>
                            <p class="description">When enabled, files linked from post content in the WordPress uploads folder are uploaded to the Gemini store as separate documents. When disabled, linked files are detected but not uploaded.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_ocr_all_upload_images">OCR Images:</label></th>
                        <td>
                            <label for="geweb_ai_search_ocr_all_upload_images">
                                <input type="checkbox" id="geweb_ai_search_ocr_all_upload_images" name="geweb_ai_search_ocr_all_upload_images" value="1" <?php checked($ocrAllUploadImages); ?>>
                                OCR all WordPress uploads-library images by default
                            </label>
                            <p class="description">When enabled, uploads-library images are sent through Gemini image OCR during indexing and their extracted text is inserted into the generated Markdown. When disabled, only images explicitly marked with the OCR checkbox in Media Library rows are OCRed.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_document_ai_project_id">Document AI OCR:</label></th>
                        <td>
                            <p>
                                <label for="geweb_ai_search_document_ai_project_id">Project ID</label><br>
                                <input type="text" id="geweb_ai_search_document_ai_project_id" name="geweb_ai_search_document_ai_project_id" value="<?php echo esc_attr((string) $documentAiProjectId); ?>" class="regular-text">
                            </p>
                            <p>
                                <label for="geweb_ai_search_document_ai_location">Location</label><br>
                                <select id="geweb_ai_search_document_ai_location" name="geweb_ai_search_document_ai_location">
                                    <option value="">Select location</option>
                                    <option value="eu" <?php selected($documentAiLocation, 'eu'); ?>>EU</option>
                                    <option value="us" <?php selected($documentAiLocation, 'us'); ?>>US</option>
                                </select>
                            </p>
                            <p>
                                <label for="geweb_ai_search_document_ai_processor_id">Processor ID</label><br>
                                <input type="text" id="geweb_ai_search_document_ai_processor_id" name="geweb_ai_search_document_ai_processor_id" value="<?php echo esc_attr((string) $documentAiProcessorId); ?>" class="regular-text">
                            </p>
                            <p>
                                <label for="geweb_ai_search_document_ai_service_account_json">Service account JSON</label><br>
                                <textarea id="geweb_ai_search_document_ai_service_account_json" name="geweb_ai_search_document_ai_service_account_json" rows="5" class="large-text code" placeholder="<?php echo esc_attr($documentAiHasServiceAccountJson ? 'Saved. Leave empty to keep current credential.' : 'Paste service account JSON.'); ?>"></textarea>
                            </p>
                            <?php if ($documentAiHasServiceAccountJson): ?>
                                <p>
                                    <label>
                                        <input type="checkbox" name="geweb_ai_search_document_ai_clear_service_account" value="1">
                                        Clear saved service account credential
                                    </label>
                                </p>
                            <?php endif; ?>
                            <p class="description">Enables the PDF-only Document AI OCR option. Requires a Google Cloud Document AI Layout Parser processor and a service account with permission to process documents.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_date_display_format">Date Display:</label></th>
                        <td>
                            <select id="geweb_ai_search_date_display_format" name="geweb_ai_search_date_display_format">
                                <?php foreach ($availableDateDisplayFormats as $formatKey => $formatLabel): ?>
                                    <option value="<?php echo esc_attr((string) $formatKey); ?>" <?php selected($dateDisplayFormat, $formatKey); ?>>
                                        <?php echo esc_html((string) $formatLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Default is <code>yyyy-mm-dd</code>. This setting applies to plugin date displays for the current group scope.</p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_preserve_data_on_uninstall">Uninstall Cleanup:</label></th>
                        <td>
                            <label for="geweb_ai_search_preserve_data_on_uninstall">
                                <input type="checkbox" id="geweb_ai_search_preserve_data_on_uninstall" name="geweb_ai_search_preserve_data_on_uninstall" value="1" <?php checked($preserveDataOnUninstall); ?>>
                                Keep plugin data when uninstalling
                            </label>
                            <p class="description">When enabled, uninstalling the plugin keeps its settings, status metadata, and indexed document tables in the WordPress database. The stored API key and encryption key are always removed on uninstall.</p>
                        </td>
                    </tr>

                    <?php if ($storeEnabled): ?>
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
                        <?php if (!empty($postTypes)): ?>
                            <?php PostIndexManager::renderButton(); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </table>
                </div>

                <div class="geweb-settings-tab-panel" data-geweb-tab-panel="workspaces" <?php echo $activeTab === 'workspaces' ? '' : (string) $inlineStyleHidden; ?>>
                <table class="form-table">
                    <tr>
                        <th>Workspace Definitions</th>
                        <td>
                            <p class="description" style="margin-top:0; max-width:900px;">
                                Workspace IDs are treated as durable primary keys. This screen lets you rename the display label and manage LDAP group mappings without changing the underlying ID.
                            </p>
                            <div style="display:flex; flex-direction:column; gap:12px; max-width:1000px;">
                                <?php foreach ($workspaceRows as $index => $workspaceRow): ?>
                                    <div style="padding:12px; border:1px solid #dcdcde; border-radius:6px; background:#fff;">
                                        <p style="margin-top:0;">
                                            <label for="geweb_ai_workspace_id_<?php echo esc_attr((string) $index); ?>"><strong>Workspace ID</strong></label><br>
                                            <input type="hidden" name="geweb_ai_workspaces[<?php echo esc_attr((string) $index); ?>][workspace_id]" value="<?php echo esc_attr((string) ($workspaceRow['workspace_id'] ?? '')); ?>">
                                            <input type="text" id="geweb_ai_workspace_id_<?php echo esc_attr((string) $index); ?>" value="<?php echo esc_attr((string) ($workspaceRow['workspace_id'] ?? '')); ?>" class="regular-text" readonly>
                                        </p>
                                        <p>
                                            <label for="geweb_ai_workspace_name_<?php echo esc_attr((string) $index); ?>"><strong>Display name</strong></label><br>
                                            <input type="text" id="geweb_ai_workspace_name_<?php echo esc_attr((string) $index); ?>" name="geweb_ai_workspaces[<?php echo esc_attr((string) $index); ?>][name]" value="<?php echo esc_attr((string) ($workspaceRow['name'] ?? '')); ?>" class="regular-text" placeholder="Example Team">
                                        </p>
                                        <p style="margin-bottom:0;">
                                            <label for="geweb_ai_workspace_groups_<?php echo esc_attr((string) $index); ?>"><strong>LDAP / external groups</strong></label><br>
                                            <input type="text" id="geweb_ai_workspace_groups_<?php echo esc_attr((string) $index); ?>" name="geweb_ai_workspaces[<?php echo esc_attr((string) $index); ?>][external_groups_csv]" value="<?php echo esc_attr((string) ($workspaceRow['external_groups_csv'] ?? '')); ?>" class="large-text" placeholder="cn=editors,ou=groups,dc=example,dc=com, team-alpha">
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th>User Memberships</th>
                        <td>
                            <p class="description" style="margin-top:0; max-width:900px;">
                                Select one workspace and then manage who is assigned to it. Assigned users keep their role within this workspace; unassigned users can be added with a role.
                            </p>
                            <p style="margin:12px 0;">
                                <label for="geweb-workspace-assignment-selector"><strong>Workspace to manage</strong></label><br>
                                <select id="geweb-workspace-assignment-selector" onchange="if (this.value) { window.location = this.value; }" class="regular-text" style="max-width:360px;">
                                    <?php foreach ($workspaceSelectorOptions as $workspaceOption): ?>
                                        <?php
                                        $workspaceOptionId = (string) ($workspaceOption['workspace_id'] ?? '');
                                        $workspaceOptionName = (string) ($workspaceOption['name'] ?? $workspaceOptionId);
                                        $workspaceOptionUrl = add_query_arg(
                                            [
                                                'page' => 'geweb-ai-search',
                                                'geweb_tab' => 'workspaces',
                                                'geweb_workspace_id' => $workspaceOptionId,
                                            ],
                                            admin_url('admin.php')
                                        );
                                        ?>
                                        <option value="<?php echo esc_url($workspaceOptionUrl); ?>" <?php selected((string) ($workspaceAssignmentView['workspace_id'] ?? ''), $workspaceOptionId); ?>>
                                            <?php echo esc_html($workspaceOptionName . ' [' . $workspaceOptionId . ']'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <input type="hidden" name="geweb_workspace_assignment_workspace_id" value="<?php echo esc_attr((string) ($workspaceAssignmentView['workspace_id'] ?? '')); ?>">
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px; max-width:1100px;">
                                <div style="padding:12px; border:1px solid #dcdcde; border-radius:6px; background:#fff;">
                                    <h3 style="margin-top:0;">Assigned users</h3>
                                    <?php if (!empty($workspaceAssignmentView['assigned'])): ?>
                                        <div style="display:flex; flex-direction:column; gap:12px;">
                                            <?php foreach ($workspaceAssignmentView['assigned'] as $assignedRow): ?>
                                                <div style="padding:12px; border:1px solid #f0f0f1; border-radius:6px; background:#fcfcfc;">
                                                    <p style="margin:0 0 8px;">
                                                        <strong><?php echo esc_html((string) ($assignedRow['label'] ?? '')); ?></strong>
                                                        <?php if (!empty($assignedRow['email'])): ?>
                                                            <br><span class="description"><?php echo esc_html((string) $assignedRow['email']); ?></span>
                                                        <?php endif; ?>
                                                    </p>
                                                    <p style="margin:0 0 8px;">
                                                        <label><strong>Role</strong></label><br>
                                                        <select name="geweb_ai_workspace_assigned_roles[<?php echo esc_attr((string) ($assignedRow['user_id'] ?? 0)); ?>]" class="regular-text">
                                                            <option value="member" <?php selected((string) ($assignedRow['role'] ?? ''), 'member'); ?>>member</option>
                                                            <option value="editor" <?php selected((string) ($assignedRow['role'] ?? ''), 'editor'); ?>>editor</option>
                                                            <option value="admin" <?php selected((string) ($assignedRow['role'] ?? ''), 'admin'); ?>>admin</option>
                                                        </select>
                                                    </p>
                                                    <label>
                                                        <input type="checkbox" name="geweb_ai_workspace_remove_user_ids[]" value="<?php echo esc_attr((string) ($assignedRow['user_id'] ?? 0)); ?>">
                                                        Remove from this workspace
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="description" style="margin-bottom:0;">No users are currently assigned to this workspace.</p>
                                    <?php endif; ?>
                                </div>
                                <div style="padding:12px; border:1px solid #dcdcde; border-radius:6px; background:#fff;">
                                    <h3 style="margin-top:0;">Unassigned users</h3>
                                    <?php if (!empty($workspaceAssignmentView['unassigned'])): ?>
                                        <div style="display:flex; flex-direction:column; gap:12px;">
                                            <?php foreach ($workspaceAssignmentView['unassigned'] as $unassignedRow): ?>
                                                <div style="padding:12px; border:1px solid #f0f0f1; border-radius:6px; background:#fcfcfc;">
                                                    <p style="margin:0 0 8px;">
                                                        <strong><?php echo esc_html((string) ($unassignedRow['label'] ?? '')); ?></strong>
                                                        <?php if (!empty($unassignedRow['email'])): ?>
                                                            <br><span class="description"><?php echo esc_html((string) $unassignedRow['email']); ?></span>
                                                        <?php endif; ?>
                                                    </p>
                                                    <p style="margin:0 0 8px;">
                                                        <label><strong>Role if assigned</strong></label><br>
                                                        <select name="geweb_ai_workspace_add_roles[<?php echo esc_attr((string) ($unassignedRow['user_id'] ?? 0)); ?>]" class="regular-text">
                                                            <option value="member" <?php selected((string) ($unassignedRow['default_role'] ?? ''), 'member'); ?>>member</option>
                                                            <option value="editor" <?php selected((string) ($unassignedRow['default_role'] ?? ''), 'editor'); ?>>editor</option>
                                                            <option value="admin" <?php selected((string) ($unassignedRow['default_role'] ?? ''), 'admin'); ?>>admin</option>
                                                        </select>
                                                    </p>
                                                    <label>
                                                        <input type="checkbox" name="geweb_ai_workspace_add_user_ids[]" value="<?php echo esc_attr((string) ($unassignedRow['user_id'] ?? 0)); ?>">
                                                        Assign to this workspace
                                                    </label>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <p class="description" style="margin-bottom:0;">All users are already assigned to this workspace.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>
                </div>

                <div class="geweb-settings-tab-panel" data-geweb-tab-panel="prompts" <?php echo $activeTab === 'prompts' ? '' : (string) $inlineStyleHidden; ?>>
                <table class="form-table">
                    <tr>
                        <th><label for="geweb_ai_search_custom_prompt">AI Prompt:</label></th>
                        <td>
                            <?php if (!empty($isGeminiProvider)): ?>
                                <p class="description" style="margin-top:0;">
                                    Gemini model info:
                                    <a href="<?php echo esc_url((string) $geminiChangelogUrl); ?>" target="_blank" rel="noopener noreferrer">Release notes</a>
                                    |
                                    <a href="<?php echo esc_url((string) $geminiDeprecationsUrl); ?>" target="_blank" rel="noopener noreferrer">Deprecations</a>
                                </p>
                            <?php endif; ?>
                            <p class="description" style="margin-top:0;">
                                <strong>Currently using:</strong>
                                <?php echo esc_html((string) $currentPromptLabel); ?>
                            </p>
                            <p style="margin-top:0;">
                                <label for="geweb_ai_search_custom_prompt_name"><strong>Prompt name</strong></label><br>
                                <input type="text" id="geweb_ai_search_custom_prompt_name" name="geweb_ai_search_custom_prompt_name" value="<?php echo esc_attr((string) $customPromptName); ?>" class="regular-text" placeholder="Optional name for this prompt version">
                            </p>
                            <textarea id="geweb_ai_search_custom_prompt" name="geweb_ai_search_custom_prompt" rows="10" class="large-text code" placeholder="Enter the AI prompt used for Gemini requests."><?php echo esc_textarea((string) $customPrompt); ?></textarea>
                            <textarea id="geweb_ai_search_default_prompt" <?php echo (string) $inlineStyleHidden; ?> readonly><?php echo esc_textarea((string) $defaultPrompt); ?></textarea>
                            <p>
                                <button type="button" class="button" id="geweb-ai-restore-default-prompt" data-default-prompt="<?php echo esc_attr(base64_encode((string) $defaultPrompt)); ?>">Restore default prompt</button>
                            </p>
                            <p class="description">If this field is empty, the built-in default prompt is used. This is the generic base prompt.</p>
                            <p class="description">Below you can optionally add a model-specific prompt to this generic prompt, or explicitly override it for a specific model.</p>
                            <?php if (!empty($models)): ?>
                                <p style="margin-top:12px; max-width:360px;">
                                    <label for="geweb_ai_search_prompt_model_jump"><strong>Jump to model prompt</strong></label><br>
                                    <select id="geweb_ai_search_prompt_model_jump" class="regular-text">
                                        <option value="">Select a model...</option>
                                        <?php foreach ($dropdownModels as $model): ?>
                                            <option value="<?php echo esc_attr((string) $model); ?>" <?php selected($model, $selectedModel); ?>>
                                                <?php echo esc_html((string) $model); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </p>
                                <div style="margin-top:16px; display:flex; flex-direction:column; gap:12px; max-width:900px;">
                                    <?php foreach ($modelPromptRows as $row): ?>
                                        <details data-geweb-model-prompt-details="<?php echo esc_attr((string) $row['model']); ?>" style="border:1px solid #dcdcde; border-radius:6px; padding:10px 12px;" <?php echo !empty($row['open']) ? 'open' : ''; ?>>
                                            <summary style="cursor:pointer; font-weight:600;">
                                                <?php echo esc_html((string) $row['model']); ?>
                                                <?php if (!empty($row['is_current'])): ?>
                                                    <span style="color:#646970; font-weight:400;">(current default model)</span>
                                                <?php endif; ?>
                                            </summary>
                                            <div style="margin-top:12px;">
                                                <input type="hidden" name="geweb_ai_search_model_prompt_models[]" value="<?php echo esc_attr((string) $row['model']); ?>">
                                                <p style="margin-top:0;">
                                                    <label for="geweb_ai_search_model_prompt_name_<?php echo esc_attr((string) $row['index']); ?>"><strong>Prompt name</strong></label><br>
                                                    <input type="text" id="geweb_ai_search_model_prompt_name_<?php echo esc_attr((string) $row['index']); ?>" name="geweb_ai_search_model_prompt_names[]" value="<?php echo esc_attr((string) $row['name']); ?>" data-geweb-model-prompt-name="<?php echo esc_attr((string) $row['model']); ?>" class="regular-text" placeholder="Optional name for the prompt override used with <?php echo esc_attr((string) $row['model']); ?>">
                                                </p>
                                                <p style="margin-top:12px;">
                                                    <span><strong>How this model-specific prompt should be applied</strong></span><br>
                                                    <label style="margin-right:16px;">
                                                        <input type="radio" name="geweb_ai_search_model_prompt_modes[<?php echo esc_attr((string) $row['index']); ?>]" value="append" data-geweb-model-prompt-mode="<?php echo esc_attr((string) $row['model']); ?>" <?php checked($row['mode'], 'append'); ?>>
                                                        Add to generic prompt
                                                    </label>
                                                    <label>
                                                        <input type="radio" name="geweb_ai_search_model_prompt_modes[<?php echo esc_attr((string) $row['index']); ?>]" value="override" data-geweb-model-prompt-mode="<?php echo esc_attr((string) $row['model']); ?>" <?php checked($row['mode'], 'override'); ?>>
                                                        Override generic prompt
                                                    </label>
                                                </p>
                                                <textarea id="geweb_ai_search_model_prompt_<?php echo esc_attr((string) $row['index']); ?>" name="geweb_ai_search_model_prompts[]" rows="8" data-geweb-model-prompt="<?php echo esc_attr((string) $row['model']); ?>" class="large-text code" placeholder="Leave empty to use the general prompt for <?php echo esc_attr((string) $row['model']); ?>."><?php echo esc_textarea((string) $row['prompt']); ?></textarea>
                                                <p class="description">Built-in prompt for this model starts with:</p>
                                                <textarea id="geweb_ai_search_default_model_prompt_<?php echo esc_attr((string) $row['index']); ?>" name="geweb_ai_search_default_model_prompts[]" readonly rows="4" class="large-text code" style="opacity:.75;"><?php echo esc_textarea((string) $row['default_prompt']); ?></textarea>
                                            </div>
                                        </details>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="geweb_ai_search_prompt_history_limit">Prompt History:</label></th>
                        <td>
                            <label for="geweb_ai_search_prompt_history_limit"><strong>Prompt versions to keep per scope</strong> <span class="description">Default: <?php echo esc_html($promptHistoryLimitField['default_label']); ?></span></label><br>
                            <input type="number" id="geweb_ai_search_prompt_history_limit" name="geweb_ai_search_prompt_history_limit" min="1" step="1" value="<?php echo esc_attr($promptHistoryLimitField['value']); ?>" class="<?php echo esc_attr($promptHistoryLimitField['class']); ?>">
                            <p class="description">Number of prompt versions to keep per scope. The generic prompt keeps its own history, and each model-specific prompt keeps its own history too. Defaults are shown next to each field, and non-default values are highlighted.</p>
                            <?php if (!empty($promptHistoryItems)): ?>
                                <div id="geweb-ai-prompt-history-list" style="margin-top: 12px; display: flex; flex-direction: column; gap: 8px; max-width: 800px;">
                                    <?php foreach ($promptHistoryItems as $item): ?>
                                        <div class="geweb-ai-prompt-history-item" data-entry-id="<?php echo esc_attr((string) $item['entry_id']); ?>" data-timestamp="<?php echo esc_attr((string) $item['saved_at']); ?>" data-prompt="<?php echo esc_attr((string) $item['prompt_b64']); ?>" data-is-default="<?php echo !empty($item['is_default']) ? '1' : '0'; ?>" data-can-rename="<?php echo !empty($item['can_rename']) ? '1' : '0'; ?>" data-scope="<?php echo esc_attr((string) $item['scope']); ?>" data-model="<?php echo esc_attr((string) $item['model']); ?>" data-mode="<?php echo esc_attr((string) $item['mode']); ?>" style="padding: 8px; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer;">
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <div style="flex-grow: 1; margin-right: 10px;">
                                                    <div style="font-size:12px; color:#646970; margin-bottom:4px;"><?php echo esc_html((string) $item['scope_label']); ?></div>
                                                    <span class="geweb-ai-prompt-history-name-label"><?php echo esc_html((string) $item['name']); ?></span>
                                                    <?php if (!empty($item['can_rename'])): ?>
                                                        <input type="text" name="geweb_ai_search_prompt_history_names[<?php echo esc_attr((string) $item['entry_id']); ?>]" value="<?php echo esc_attr((string) $item['name']); ?>" class="regular-text geweb-ai-prompt-history-name-input" style="display:none; width:100%;" />
                                                    <?php endif; ?>
                                                </div>
                                                <span style="color: #646970; white-space: nowrap; margin-right: 10px;"><?php echo esc_html((string) $item['saved_at_label']); ?></span>
                                                <?php if (!empty($item['can_rename'])): ?>
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
                                    <div id="geweb-ai-prompt-history-diff" style="margin-top:8px; padding:12px; background:#fff; border:1px solid #dcdcde; min-height:20em; max-height:none; overflow:auto;">Select a saved prompt version to compare it with the current AI Prompt.</div>
                                </div>
                            <?php else: ?>
                                <p class="description">No previous prompts saved yet.</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                <div class="notice notice-warning inline geweb-admin-view-stale-notice" data-cache-tab="prompts" style="display:none; margin-top:12px;">
                    <p>
                        Prompt data changed in another session. Refresh this view to see the latest server state.
                        <button type="button" class="button button-link geweb-refresh-admin-view" data-cache-tab="prompts">Refresh View</button>
                    </p>
                </div>
                </div>
                <p class="submit">
                    <input type="submit" id="geweb-save-settings" class="button-primary" value="Save Settings" disabled>
                </p>
            </form>

            <div class="geweb-settings-tab-panel" data-geweb-tab-panel="documents" <?php echo $activeTab === 'documents' ? '' : (string) $inlineStyleHidden; ?>>
                <p class="description" style="margin-top:0; max-width: 900px;">
                    This Files tab shows documents from Simple File List, whether they have been uploaded to the Gemini store, and whether they are referenced from pages or posts.
                </p>
                <p class="description" style="max-width: 900px;">
                    Some Simple File List documents are referenced in your site content and some are not. The table helps you see both states, jump to the matching File List item, and remove unreferenced File List entries when needed.
                </p>
                <?php if (!empty($documentsApiStatusLabel)): ?>
                    <p class="description" style="color: <?php echo esc_attr((string) $documentsApiStatusColor); ?>;">
                        <strong>API key status:</strong> <?php echo esc_html((string) $documentsApiStatusLabel); ?>
                        <?php if ($documentsApiStatusMessage !== '' && $documentsApiStatusLabel !== 'Valid'): ?>
                            <br><small><?php echo esc_html((string) $documentsApiStatusMessage); ?></small>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <p>
                    <button type="button" class="button" id="geweb-refresh-referenced-documents">Refresh List</button>
                    <span id="geweb-referenced-documents-status" style="margin-left:10px; color:#646970;">
                        <?php if ($referencedCacheTime > 0): ?>
                            Showing cached list. Last refreshed: <?php echo esc_html((string) $referencedCacheLabel); ?>
                        <?php else: ?>
                            Loading files...
                        <?php endif; ?>
                    </span>
                </p>
                <div class="notice notice-warning inline geweb-admin-view-stale-notice" data-cache-tab="files" style="display:none; margin:12px 0 0;">
                    <p>
                        Files changed in another session. Refresh this view to load the latest server data.
                        <button type="button" class="button button-link geweb-refresh-admin-view" data-cache-tab="files">Refresh View</button>
                    </p>
                </div>
                <?php if (!empty($referencedDebug)): ?>
                    <p class="description">
                        Scanned content items: <?php echo esc_html((string) ($referencedDebug['managed_posts'] ?? 0)); ?>,
                        content items with document links: <?php echo esc_html((string) ($referencedDebug['posts_with_document_links'] ?? 0)); ?>,
                        accepted document references: <?php echo esc_html((string) ($referencedDebug['accepted_documents'] ?? 0)); ?>
                        <?php if (!empty($referencedDebug['using_all_public_post_types'])): ?>
                            <br><small>No post types are configured yet, so this overview is scanning all public post types, including pages.</small>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <div id="geweb-referenced-documents-container" data-needs-refresh="<?php echo $hasReferencedDocumentCache ? '0' : '1'; ?>" style="margin-top:16px;">
                    <?php echo $referencedDocumentsHtml; ?>
                </div>
            </div>

            <div class="geweb-settings-tab-panel" data-geweb-tab-panel="stores" <?php echo $activeTab === 'stores' ? '' : (string) $inlineStyleHidden; ?>>
                <p class="description" style="margin-top:0; max-width: 900px;">
                    This table shows all Gemini File Search Stores visible to the configured API key, marks the one used by this plugin, and helps spot likely orphaned stores. Select a store to inspect its uploaded items below.
                </p>
                <?php if (is_array($storageEstimate ?? null)): ?>
                    <?php
                    $markdownCount = (int) ($storageEstimate['markdown_count'] ?? 0);
                    $markdownBytes = (int) ($storageEstimate['markdown_bytes'] ?? 0);
                    $referencedCount = (int) ($storageEstimate['referenced_count'] ?? 0);
                    $referencedBytes = (int) ($storageEstimate['referenced_bytes'] ?? 0);
                    $remoteDocumentCount = (int) ($storageEstimate['remote_document_count'] ?? 0);
                    $remoteDocumentsWithSize = (int) ($storageEstimate['remote_documents_with_size'] ?? 0);
                    $remoteDocumentsWithoutSize = (int) ($storageEstimate['remote_documents_without_size'] ?? 0);
                    $remoteReferencedBytes = (int) ($storageEstimate['remote_referenced_bytes'] ?? 0);
                    $unknownReferencedCount = (int) ($storageEstimate['unknown_referenced_count'] ?? 0);
                    $rawKnownBytes = (int) ($storageEstimate['raw_known_bytes'] ?? 0);
                    $estimatedBackendBytes = (int) ($storageEstimate['estimated_backend_bytes'] ?? 0);
                    $recommendedStoreLimitBytes = (int) ($storageEstimate['recommended_store_limit_bytes'] ?? 0);
                    $tierLimits = is_array($storageEstimate['tier_limits'] ?? null) ? $storageEstimate['tier_limits'] : [];
                    ?>
                    <div style="margin:12px 0 16px; padding:12px 14px; background:#fff; border:1px solid #dcdcde; max-width: 950px;">
                        <strong>Gemini Storage Estimate</strong>
                        <p class="description" style="margin:8px 0 0;">
                            Local Markdown cache: <?php echo esc_html((string) $markdownCount); ?> item(s), <?php echo esc_html(GeminiStorageEstimator::formatBytes($markdownBytes)); ?>.<br>
                            Uploaded referenced files tracked by this plugin: <?php echo esc_html((string) $referencedCount); ?> item(s).
                            <?php if ($remoteDocumentCount > 0): ?>
                                <br>Remote size returned by Gemini for current store documents: <?php echo esc_html((string) $remoteDocumentCount); ?> item(s) checked, <?php echo esc_html(GeminiStorageEstimator::formatBytes($remoteReferencedBytes)); ?> used in this estimate.
                                <br><small>Debug: Gemini returned a usable <code>sizeBytes</code> value for <?php echo esc_html((string) $remoteDocumentsWithSize); ?> document(s) and no usable size for <?php echo esc_html((string) $remoteDocumentsWithoutSize); ?>.</small>
                            <?php endif; ?>
                            <?php if ($referencedBytes > 0): ?>
                                <br>Fallback local file-size total where remote size was unavailable: <?php echo esc_html(GeminiStorageEstimator::formatBytes($referencedBytes)); ?>.
                            <?php endif; ?>
                            <?php if ($unknownReferencedCount > 0): ?>
                                <br>Referenced uploads with unknown local size: <?php echo esc_html((string) $unknownReferencedCount); ?>.
                            <?php endif; ?>
                            <br>Known raw input total: <?php echo esc_html(GeminiStorageEstimator::formatBytes($rawKnownBytes)); ?>.
                            <br>Estimated File Search backend footprint at Google's documented ~3x multiplier: <strong><?php echo esc_html(GeminiStorageEstimator::formatBytes($estimatedBackendBytes)); ?></strong>.
                            <br>Google also recommends keeping a store below about <?php echo esc_html(GeminiStorageEstimator::formatBytes($recommendedStoreLimitBytes)); ?> for better latency.
                        </p>
                        <p class="description" style="margin:8px 0 0;">
                            The actual Gemini billing tier for this API key/project is not exposed to this plugin, so the limits below are a comparison against Google's documented tier thresholds rather than a verified live tier.
                        </p>
                        <?php if (!empty($tierLimits)): ?>
                            <ul style="margin:8px 0 0 18px;">
                                <?php foreach ($tierLimits as $tierLabel => $tierBytes): ?>
                                    <li>
                                        <?php echo esc_html((string) $tierLabel); ?>:
                                        <?php echo esc_html(GeminiStorageEstimator::formatBytes((int) $tierBytes)); ?>
                                        <?php echo $estimatedBackendBytes > 0 ? esc_html(' (' . round(($estimatedBackendBytes / max((int) $tierBytes, 1)) * 100, 1) . '% of this limit based on the current estimate)') : ''; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!$isGeminiProvider): ?>
                    <p>This tab is only available when the Gemini provider is active.</p>
                <?php else: ?>
                    <p>
                        <button type="button" class="button" id="geweb-refresh-gemini-stores">Refresh List</button>
                        <span id="geweb-gemini-stores-status" style="margin-left:10px; color:#646970;">
                            <?php if ($providerStoreCacheTime > 0): ?>
                                Last refreshed: <?php echo esc_html((string) $providerStoreCacheLabel); ?>
                            <?php else: ?>
                                Loading Gemini stores...
                            <?php endif; ?>
                        </span>
                    </p>
                    <p id="geweb-gemini-stores-error" class="description" style="color:<?php echo esc_attr((string) $statusColorError); ?>;<?php echo $providerStoreError !== '' ? '' : ' display:none;'; ?>"><?php echo esc_html((string) $providerStoreError); ?></p>
                    <div id="geweb-gemini-stores-container" data-needs-refresh="<?php echo $providerHasStoreCache ? '0' : '1'; ?>" style="margin-top:16px;">
                        <?php echo $geminiStoresHtml; ?>
                    </div>
                    <?php echo $geminiStoreDocumentsPanelHtml; ?>
                <?php endif; ?>
            </div>

            <div class="geweb-settings-tab-panel" data-geweb-tab-panel="conversations" <?php echo $activeTab === 'conversations' ? '' : (string) $inlineStyleHidden; ?>>
                <p class="description" style="margin-top:0; max-width: 900px;">
                    Saved AI chats are grouped by browser-side chat ID and show the latest summary, last usage time, total token usage, and an estimated Gemini text-generation cost when usage metadata is available. Entries appear after a successful AI response, not only when the dialog is closed.
                </p>
                <p>
                    <button type="button" class="button" id="geweb-refresh-conversations">Refresh List</button>
                    <span id="geweb-conversations-status" style="margin-left:10px; color:#646970;">Loading chats...</span>
                </p>
                <div class="notice notice-warning inline geweb-admin-view-stale-notice" data-cache-tab="chats" style="display:none; margin:12px 0 0;">
                    <p>
                        Chats changed in another session. Refresh this view to load the latest server data.
                        <button type="button" class="button button-link geweb-refresh-admin-view" data-cache-tab="chats">Refresh View</button>
                    </p>
                </div>
                <div style="margin-top:16px;">
                    <div id="geweb-conversations-container">
                        <?php echo $conversationsHtml; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
