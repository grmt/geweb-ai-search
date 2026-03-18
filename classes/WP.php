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
    private const OPTION_INCLUDE_REFERENCED_DOCUMENTS = 'geweb_aisearch_include_referenced_documents';
    private const OPTION_PRESERVE_DATA_ON_UNINSTALL = 'geweb_aisearch_preserve_data_on_uninstall';
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
            'Settings',
            'Settings',
            'manage_options',
            'geweb-ai-search',
            [$this, 'renderOptionsPage']
        );

        add_submenu_page(
            'geweb-ai-search',
            'Referenced Documents',
            'Referenced Documents',
            'manage_options',
            'geweb-ai-search-referenced-documents',
            [$this, 'renderReferencedDocumentListPage']
        );
    }

    /**
     * Render referenced documents overview page.
     *
     * @return void
     */
    public function renderReferencedDocumentListPage(): void {
        $table = new ReferencedDocumentListTable();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Referenced Documents</h1>
            <p>This table shows local files found in managed content, whether they have been uploaded to the Gemini store, and any uploaded documents that are no longer referenced.</p>
            <hr class="wp-header-end">

            <form method="get">
                <input type="hidden" name="page" value="geweb-ai-search-referenced-documents">
                <?php $table->display(); ?>
            </form>
        </div>
        <?php
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
        if (!empty($_POST['geweb_api_key'])) {
            $encryption = new Encryption();
            $encryption->saveApiKey(sanitize_text_field(wp_unslash($_POST['geweb_api_key'])));

            // Create store if doesn't exist or if forced
            $gemini = new Gemini();
            $gemini->clearModelsCache();
            if (empty($gemini->getStoreData()) || isset($_POST['geweb_ai_search_create_store'])) {
                $gemini->createStore();
            }
        }

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

        $historyLimit = self::DEFAULT_PROMPT_HISTORY_LIMIT;
        if (isset($_POST['geweb_ai_search_prompt_history_limit'])) {
            $historyLimit = max(1, intval($_POST['geweb_ai_search_prompt_history_limit']));
        }
        update_option(self::OPTION_PROMPT_HISTORY_LIMIT, $historyLimit);
        $this->trimPromptHistory(max(0, $historyLimit - 1));

        // Save custom prompt
        if (isset($_POST['geweb_ai_search_custom_prompt'])) {
            $newPrompt = sanitize_textarea_field(wp_unslash($_POST['geweb_ai_search_custom_prompt']));
            $currentPrompt = (string) get_option(self::OPTION_CUSTOM_PROMPT, '');

            if ($newPrompt !== $currentPrompt) {
                $this->storePromptHistory($currentPrompt, max(0, $historyLimit - 1));
            }

            if ($newPrompt === '') {
                delete_option(self::OPTION_CUSTOM_PROMPT);
            } else {
                update_option(self::OPTION_CUSTOM_PROMPT, $newPrompt);
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
        $gemini = new Gemini();
        $storeEnabled = !empty($gemini->getStoreData());

        $models = $gemini->getModels();
        $selectedModel = $gemini->getModel();
        if (!in_array($selectedModel, $models, true)) {
            array_unshift($models, $selectedModel);
            $models = array_values(array_unique($models));
        }
        $defaultModel = $gemini->getDefaultModel($models);
        $modelStatuses = $gemini->getModelStatuses();
        $customPrompt = get_option(self::OPTION_CUSTOM_PROMPT, '');
        $defaultPrompt = $gemini->getDefaultSystemInstruction();
        $effectivePrompt = $gemini->getSystemInstruction();
        $promptHistoryLimit = (int) get_option(self::OPTION_PROMPT_HISTORY_LIMIT, self::DEFAULT_PROMPT_HISTORY_LIMIT);
        $promptHistory = get_option(self::OPTION_PROMPT_HISTORY, []);
        if (!is_array($promptHistory)) {
            $promptHistory = [];
        }
        $postTypes = get_option('geweb_aisearch_post_types', []);
        $includeReferencedDocuments = get_option(self::OPTION_INCLUDE_REFERENCED_DOCUMENTS, '0') === '1';
        $preserveDataOnUninstall = get_option(self::OPTION_PRESERVE_DATA_ON_UNINSTALL, '0') === '1';
        $allPostTypes = get_post_types(['public' => true], 'objects');
        ?>
        <div class="wrap">
            <h1>Geweb AI Search</h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="geweb_save">
                <?php wp_nonce_field('geweb_ai_search_save_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="geweb_api_key">API Key:</label></th>
                        <td>
                            <input type="password" id="geweb_api_key" name="geweb_api_key" placeholder="<?php echo esc_attr($storeEnabled ? 'API Key is set' : 'Enter API Key'); ?>" class="regular-text">
                            <p class="description">Get your API key from <a href="https://aistudio.google.com/app/api-keys" target="_blank">Google AI Studio</a></p>
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
                        <th><label for="geweb_ai_search_custom_prompt">AI Prompt:</label></th>
                        <td>
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
                                        $name = (string) ($entry['name'] ?? '');
                                        if ($name === '') {
                                            $name = 'Version from ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), $saved_at);
                                        }
                                        $prompt_b64 = base64_encode((string) ($entry['prompt'] ?? ''));
                                        ?>
                                        <div class="geweb-ai-prompt-history-item" data-timestamp="<?php echo esc_attr($saved_at); ?>" data-prompt="<?php echo esc_attr($prompt_b64); ?>" style="padding: 8px; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer;">
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <input type="text" name="geweb_ai_search_prompt_history_names[<?php echo esc_attr($saved_at); ?>]" value="<?php echo esc_attr($name); ?>" class="regular-text" style="flex-grow: 1; margin-right: 10px;" />
                                                <span style="color: #646970; white-space: nowrap; margin-right: 10px;"><?php echo wp_date(get_option('date_format') . ' ' . get_option('time_format'), $saved_at); ?></span>
                                                <button type="button" class="button geweb-ai-use-history-prompt">Use</button>
                                                <button type="button" class="button-link button-link-delete geweb-ai-delete-history-prompt" style="margin-left: 5px;" title="Delete"><span class="dashicons dashicons-trash"></span></button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" id="geweb-ai-clear-history" style="margin-top: 12px;">Clear All History</button>
                                <div style="margin-top:12px; max-width: 800px;">
                                    <pre
                                        id="geweb-ai-prompt-history-diff"
                                        style="margin-top:8px; padding:12px; background:#fff; border:1px solid #dcdcde; min-height:20em; max-height:none; overflow:auto; white-space:pre-wrap;"
                                    >Click on a prompt version to preview the full text and diff.</pre>
                                </div>
                            <?php else: ?>
                                <p class="description">No previous prompts saved yet.</p>
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

                <p class="submit">
                    <input type="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
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
        $prompt = trim($prompt);
        if ($prompt === '' || $limit <= 0) {
            return;
        }

        $history = get_option(self::OPTION_PROMPT_HISTORY, []);
        if (!is_array($history)) {
            $history = [];
        }

        array_unshift($history, [
            'prompt' => $prompt,
            'saved_at' => current_time('timestamp'),
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
        foreach ($history as $entry) {
            $saved_at = (int) ($entry['saved_at'] ?? 0);
            if (isset($names[$saved_at])) {
                $entry['name'] = sanitize_text_field($names[$saved_at]);
            }
            $newHistory[] = $entry;
        }

        if ($newHistory !== $history) {
            update_option(self::OPTION_PROMPT_HISTORY, $newHistory);
        }
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
            $gemini = new Gemini();
            $result = $gemini->search($messages);

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
