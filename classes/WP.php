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
    private const OPTION_MODEL_PROMPTS = 'geweb_aisearch_model_prompts';
    private const OPTION_MODEL_PROMPT_NAMES = 'geweb_aisearch_model_prompt_names';
    private const OPTION_MODEL_PROMPT_MODES = 'geweb_aisearch_model_prompt_modes';
    private const OPTION_INCLUDE_REFERENCED_DOCUMENTS = 'geweb_aisearch_include_referenced_documents';
    private const OPTION_PRESERVE_DATA_ON_UNINSTALL = 'geweb_aisearch_preserve_data_on_uninstall';
    private const OPTION_CONNECTION_STATUS = 'geweb_aisearch_connection_status';
    private const OPTION_CONVERSATIONS = 'geweb_aisearch_conversations';
    private const OPTION_FRONTEND_AI_INTERFACE = 'geweb_aisearch_frontend_ai_interface';
    private const OPTION_FRONTEND_AI_PAGE_ID = 'geweb_aisearch_frontend_ai_page_id';
    private const OPTION_REWRITE_VERSION = 'geweb_aisearch_rewrite_version';
    private const OPTION_CONVERSATION_TRIM_MESSAGE_LIMIT = 'geweb_aisearch_conversation_trim_message_limit';
    private const OPTION_CONVERSATION_TRIM_CHAR_LIMIT = 'geweb_aisearch_conversation_trim_char_limit';
    private const OPTION_LOCAL_CONVERSATION_ARCHIVE_LIMIT = 'geweb_aisearch_local_conversation_archive_limit';
    private const FRONTEND_AI_QUERY_VAR = 'geweb_ai_query';
    private const FRONTEND_AI_SLUG = 'ai-search';
    private const FRONTEND_AI_REWRITE_SLUG = 'ai-search-workspace';
    private const FRONTEND_AI_REWRITE_VERSION = '2';
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private const MESSAGE_MISSING_CONVERSATION_ID = 'Missing conversation ID.';
    private const MESSAGE_CONVERSATION_NOT_FOUND = 'Conversation not found.';
    private const LABEL_DEFAULT_PROMPT = 'Default prompt';
    private const INLINE_STYLE_HIDDEN = 'style="display:none;"';
    private const STATUS_COLOR_SUCCESS = '#46b450';
    private const STATUS_COLOR_ERROR = '#d63638';
    private const STATUS_COLOR_NEUTRAL = '#646970';
    private const REGEX_WHITESPACE = '/\s+/';
    private const DEFAULT_PROMPT_HISTORY_LIMIT = 10;
    private const DEFAULT_CONVERSATION_LIMIT = 50;
    private const DEFAULT_FRONTEND_AI_INTERFACE = 'fullscreen';
    private const DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT = 12;
    private const DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT = 12000;
    private const DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT = 12;
    private bool $frontendAiPageModalRendered = false;
    private bool $shortcodePageViewActive = false;
    /**
     * @var array<string,mixed>
     */
    private array $renderOverrides = [];

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
        add_action('wp_ajax_geweb_get_frontend_conversations', [$this, 'ajaxGetFrontendConversations']);
        add_action('wp_ajax_nopriv_geweb_get_frontend_conversations', [$this, 'ajaxGetFrontendConversations']);
        add_action('wp_ajax_geweb_get_frontend_conversation', [$this, 'ajaxGetFrontendConversation']);
        add_action('wp_ajax_nopriv_geweb_get_frontend_conversation', [$this, 'ajaxGetFrontendConversation']);
        add_action('wp_ajax_geweb_resolve_source_references', [$this, 'ajaxResolveSourceReferences']);
        add_action('wp_ajax_nopriv_geweb_resolve_source_references', [$this, 'ajaxResolveSourceReferences']);
        add_action('wp_ajax_geweb_frontend_rename_conversation', [$this, 'ajaxFrontendRenameConversation']);
        add_action('wp_ajax_nopriv_geweb_frontend_rename_conversation', [$this, 'ajaxFrontendRenameConversation']);
        add_action('wp_ajax_geweb_frontend_delete_conversation', [$this, 'ajaxFrontendDeleteConversation']);
        add_action('wp_ajax_nopriv_geweb_frontend_delete_conversation', [$this, 'ajaxFrontendDeleteConversation']);
        add_action('wp_ajax_geweb_clear_prompt_history', [$this, 'ajaxClearPromptHistory']);
        add_action('wp_ajax_geweb_delete_prompt_history_item', [$this, 'ajaxDeletePromptHistoryItem']);
        add_action('wp_ajax_geweb_rename_conversation', [$this, 'ajaxRenameConversation']);
        add_action('wp_ajax_geweb_delete_conversation', [$this, 'ajaxDeleteConversation']);
        add_action('wp_ajax_geweb_refresh_referenced_documents', [$this, 'ajaxRefreshReferencedDocuments']);
        add_action('wp_ajax_geweb_update_referenced_document', [$this, 'ajaxUpdateReferencedDocument']);
        add_action('wp_ajax_geweb_toggle_referenced_document_exclude', [$this, 'ajaxToggleReferencedDocumentExclude']);
        add_action('wp_ajax_geweb_update_referenced_document_nice_name', [$this, 'ajaxUpdateReferencedDocumentNiceName']);
        add_action('wp_ajax_geweb_refresh_gemini_stores', [$this, 'ajaxRefreshGeminiStores']);
        add_action('wp_ajax_geweb_refresh_gemini_store_documents', [$this, 'ajaxRefreshGeminiStoreDocuments']);
        add_action('wp_ajax_geweb_delete_gemini_store', [$this, 'ajaxDeleteGeminiStore']);
        add_action('wp_ajax_geweb_refresh_models', [$this, 'ajaxRefreshModels']);

        add_action('wp_ajax_geweb_get_nonce', [$this, 'ajaxGetNonce']);
        add_action('wp_ajax_nopriv_geweb_get_nonce', [$this, 'ajaxGetNonce']);

        add_action('init', [self::class, 'registerFrontendAiRewrite']);
        add_action('init', [self::class, 'maybeRefreshFrontendAiRewrite'], 20);
        add_filter('query_vars', [$this, 'registerFrontendAiQueryVars']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminScripts']);
        add_action('template_redirect', [$this, 'maybeRenderFrontendAiPage']);
        add_action('admin_bar_menu', [$this, 'maybeRemoveFrontendAdminBarSearch'], 999);
        add_shortcode('geweb_ai_search', [$this, 'renderFrontendAiSearchShortcode']);

        add_action('wp_footer', [$this, 'renderModals']);
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
     * Render conversations table HTML.
     *
     * @return void
     */
    private function renderConversationsTable(): void {
        $conversations = $this->getConversationLog();
        $frontendAiPageUrl = $this->getFrontendAiPageUrl();
        $latestConversation = isset($conversations[0]) && is_array($conversations[0]) ? $conversations[0] : [];
        $latestConversationId = isset($latestConversation['id']) ? (string) $latestConversation['id'] : '';

        echo '<p class="description" style="margin:0 0 12px;">';
        echo esc_html(sprintf(_n('%d saved conversation.', '%d saved conversations.', count($conversations), 'geweb-ai-search'), count($conversations)));
        echo '</p>';
        echo '<p class="description" style="margin:0 0 12px;">';
        echo esc_html(sprintf(__('The %d most recently used conversations are kept automatically; the oldest unused ones are pruned first.', 'geweb-ai-search'), self::DEFAULT_CONVERSATION_LIMIT));
        echo '</p>';

        if (empty($conversations)) {
            echo '<div class="notice notice-info inline" style="margin:0 0 12px;"><p>';
            echo esc_html__('No saved conversations yet. A conversation is added here after a successful AI response.', 'geweb-ai-search');
            echo '</p></div>';
            return;
        }

        if ($frontendAiPageUrl !== '' && $latestConversationId !== '') {
            $latestConversationUrl = $this->getFrontendAiConversationUrl($latestConversationId);
            echo '<p style="margin:0 0 12px;">';
            echo '<a class="button button-primary" href="' . esc_url($latestConversationUrl) . '">Open Latest Conversation</a>';
            echo '</p>';
        }

        $table = new ConversationListTable($conversations, $frontendAiPageUrl);
        $table->prepare_items();
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="geweb-conversations-table-form">';
        echo '<input type="hidden" name="page" value="geweb-ai-search">';
        echo '<input type="hidden" name="geweb_tab" value="conversations">';
        $table->search_box(__('Search conversations', 'geweb-ai-search'), 'geweb-conversations');
        $table->display();
        echo '</form>';
    }

    /**
     * Render the selected Gemini store documents panel.
     *
     * @param string $storeName
     * @param string $storeLabel
     * @param array<int,array<string,mixed>> $documents
     * @return void
     */
    private function renderGeminiStoreDocumentsPanel(string $storeName, string $storeLabel, array $documents): void {
        ?>
        <div id="geweb-gemini-store-documents-panel" data-store-name="<?php echo esc_attr($storeName); ?>" style="margin-top:20px; padding:16px; background:#fff; border:1px solid #dcdcde;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;">
                <div>
                    <strong id="geweb-gemini-store-documents-title"><?php echo esc_html($storeLabel !== '' ? $storeLabel : $storeName); ?></strong>
                    <div id="geweb-gemini-store-documents-subtitle" class="description" style="margin-top:4px;">
                        Uploaded items in the selected Gemini File Search Store.
                    </div>
                </div>
                <button type="button" class="button" id="geweb-refresh-gemini-store-documents" <?php disabled($storeName === ''); ?>>Refresh List</button>
            </div>
            <div id="geweb-gemini-store-documents-status" class="description" style="margin-bottom:12px; color:#646970;">
                <?php echo $storeName === '' ? 'Select a store to view uploaded items.' : 'Showing uploaded items for the selected store.'; ?>
            </div>
            <p id="geweb-gemini-store-documents-error" class="description" style="margin:0 0 12px; color:<?php echo esc_attr(self::STATUS_COLOR_ERROR); ?>; display:none;"></p>
            <div id="geweb-gemini-store-documents-container">
                <?php
                if ($storeName === '') {
                    echo '<p style="margin:0;">Select a store to view uploaded items.</p>';
                } else {
                    echo GeminiStoreListTable::renderDocumentList($documents); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{name:string,label:string,documents:array<int,array<string,mixed>>}
     */
    private function getInitialGeminiStoreSelection(array $items): array {
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['is_active'])) {
                continue;
            }

            return [
                'name' => (string) ($item['name'] ?? ''),
                'label' => (string) (($item['display_name'] ?? '') !== '' ? $item['display_name'] : ($item['name'] ?? '')),
                'documents' => isset($item['documents']) && is_array($item['documents']) ? $item['documents'] : [],
            ];
        }

        $first = isset($items[0]) && is_array($items[0]) ? $items[0] : [];

        return [
            'name' => (string) ($first['name'] ?? ''),
            'label' => (string) (($first['display_name'] ?? '') !== '' ? $first['display_name'] : ($first['name'] ?? '')),
            'documents' => isset($first['documents']) && is_array($first['documents']) ? $first['documents'] : [],
        ];
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
        $storedId = (int) get_option(self::OPTION_FRONTEND_AI_PAGE_ID, 0);
        if ($storedId > 0) {
            $post = get_post($storedId);
            if ($post instanceof \WP_Post && $post->post_status !== 'trash') {
                self::markFrontendAiPageExcluded($storedId);
                return $storedId;
            }
        }

        $existing = get_page_by_path(self::FRONTEND_AI_SLUG, OBJECT, 'page');
        if ($existing instanceof \WP_Post) {
            update_option(self::OPTION_FRONTEND_AI_PAGE_ID, (int) $existing->ID, false);
            self::markFrontendAiPageExcluded((int) $existing->ID);
            return (int) $existing->ID;
        }

        $existingShortcodePages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => -1,
            'suppress_filters' => false,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        foreach ($existingShortcodePages as $page) {
            if ($page instanceof \WP_Post && has_shortcode((string) $page->post_content, 'geweb_ai_search')) {
                update_option(self::OPTION_FRONTEND_AI_PAGE_ID, (int) $page->ID, false);
                self::markFrontendAiPageExcluded((int) $page->ID);
                return (int) $page->ID;
            }
        }

        $pageId = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'AI Search',
            'post_name' => self::FRONTEND_AI_SLUG,
            'post_content' => '[geweb_ai_search]',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);

        if (is_wp_error($pageId) || (int) $pageId <= 0) {
            return 0;
        }

        update_option(self::OPTION_FRONTEND_AI_PAGE_ID, (int) $pageId, false);
        self::markFrontendAiPageExcluded((int) $pageId);
        return (int) $pageId;
    }

    /**
     * @param int $pageId
     * @return void
     */
    private static function markFrontendAiPageExcluded(int $pageId): void {
        if ($pageId <= 0) {
            return;
        }

        update_post_meta($pageId, 'geweb_aisearch_exclude', '1');
        update_post_meta($pageId, 'geweb_aisearch_status', 'excluded');
    }

    /**
     * AJAX: Refresh referenced documents cache and return rendered table.
     *
     * @return void
     */
    public function ajaxRefreshReferencedDocuments(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
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
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
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
            if ($success) {
                $documentStore->saveReferencedDocumentSelectionTarget($fileHash, true);
            }
        } elseif ($actionName === 'remove') {
            $success = $documentStore->removeReferencedDocumentByHash($fileHash);
            if ($success) {
                $documentStore->saveReferencedDocumentSelectionTarget($fileHash, false);
            }
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
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $fileHash = isset($_POST['file_hash']) ? sanitize_text_field(wp_unslash($_POST['file_hash'])) : '';
        $exclude = !empty($_POST['exclude']);

        if ($fileHash === '') {
            wp_send_json_error(['message' => 'Missing file hash.'], 400);
        }

        $documentStore = new DocumentStore();
        if ($exclude) {
            $removed = $documentStore->removeReferencedDocumentByHash($fileHash);
            if (!$removed) {
                wp_send_json_error(['message' => 'Could not remove this source from the Gemini store. It is still included.'], 500);
            }
            $documentStore->saveReferencedDocumentSelectionTarget($fileHash, false);
        } else {
            $documentStore->saveReferencedDocumentSelectionTarget($fileHash, true);
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
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
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
        $this->renderGeminiStoresTable();
        $html = ob_get_clean();

        error_log('geweb-ai-search: ajaxRefreshGeminiStores completed in ' . number_format(microtime(true) - $startedAt, 3) . 's.');

        wp_send_json_success([
            'html' => $html,
            'refreshed_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), time()),
            'count' => count($items),
            'error' => $provider->getStoreOverviewError(),
        ]);
    }

    /**
     * AJAX: Refresh the uploaded-items list for a selected Gemini store.
     *
     * @return void
     */
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
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
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
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
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
        $modelPromptOverrides = get_option(self::OPTION_MODEL_PROMPTS, []);
        $modelPromptOverrideNames = get_option(self::OPTION_MODEL_PROMPT_NAMES, []);
        $modelPromptOverrideModes = get_option(self::OPTION_MODEL_PROMPT_MODES, []);
        $defaultPrompt = $provider->getDefaultSystemInstruction();
        $isUsingDefaultPrompt = trim((string) $customPrompt) === '';
        $modelPromptOverrides = is_array($modelPromptOverrides) ? $modelPromptOverrides : [];
        $modelPromptOverrideNames = is_array($modelPromptOverrideNames) ? $modelPromptOverrideNames : [];
        $modelPromptOverrideModes = is_array($modelPromptOverrideModes) ? $modelPromptOverrideModes : [];
        $promptHistoryLimit = (int) get_option(self::OPTION_PROMPT_HISTORY_LIMIT, self::DEFAULT_PROMPT_HISTORY_LIMIT);
        $promptHistory = PromptSupport::normalizePromptHistoryEntries(get_option(self::OPTION_PROMPT_HISTORY, []));
        $postTypes = get_option('geweb_aisearch_post_types', []);
        $includeReferencedDocuments = get_option(self::OPTION_INCLUDE_REFERENCED_DOCUMENTS, '0') === '1';
        $preserveDataOnUninstall = get_option(self::OPTION_PRESERVE_DATA_ON_UNINSTALL, '0') === '1';
        $frontendAiInterface = $this->getFrontendAiInterface();
        $frontendAiPageId = $this->getFrontendAiPageId();
        $frontendAiPageUrl = $this->getFrontendAiPageUrl();
        $conversationTrimMessageLimit = $this->getConversationTrimMessageLimit();
        $conversationTrimCharLimit = $this->getConversationTrimCharLimit();
        $localConversationArchiveLimit = $this->getLocalConversationArchiveLimit();
        $allPostTypes = get_post_types(['public' => true], 'objects');
        $activeTab = isset($_GET['geweb_tab']) ? sanitize_key(wp_unslash($_GET['geweb_tab'])) : 'general';
        if ($activeTab === 'ai') {
            $activeTab = 'prompts';
        }
        if (!in_array($activeTab, ['general', 'prompts', 'documents', 'stores', 'conversations'], true)) {
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
        if ($isUsingDefaultPrompt) {
            $currentPromptLabel = 'Built-in default prompt';
        } elseif ($customPromptName !== '') {
            $currentPromptLabel = $customPromptName;
        } else {
            $currentPromptLabel = 'Custom prompt';
        }

        $frontendAiPageTitle = $frontendAiPageId > 0 ? get_the_title($frontendAiPageId) : '';
        $generalTabUrl = $this->getTabUrl('general');
        $promptsTabUrl = $this->getTabUrl('prompts');
        $documentsTabUrl = $this->getTabUrl('documents');
        $storesTabUrl = $this->getTabUrl('stores');
        $conversationsTabUrl = $this->getTabUrl('conversations');
        $modelPromptRows = AdminPageSupport::buildModelPromptRows($models, $selectedModel, $modelPromptOverrides, $modelPromptOverrideNames, $modelPromptOverrideModes, $provider, $defaultPrompt);
        $promptHistoryItems = AdminPageSupport::buildPromptHistoryItems($promptHistory, $defaultPrompt, self::LABEL_DEFAULT_PROMPT);
        $documentsApiStatus = AdminPageSupport::buildApiStatusDisplay($connectionStatus, self::STATUS_COLOR_SUCCESS, self::STATUS_COLOR_NEUTRAL, self::STATUS_COLOR_ERROR);
        $referencedDocumentsHtml = AdminPageSupport::renderPanelHtml($hasReferencedDocumentCache, function (): void {
            $this->renderReferencedDocumentsTable();
        }, 'Referenced documents could not be loaded yet. Use Refresh List to try again.');
        $geminiStoresHtml = AdminPageSupport::renderPanelHtml($providerHasStoreCache, function (): void {
            $this->renderGeminiStoresTable();
        }, 'Loading Gemini stores for the first time. This can take a moment if multiple stores need to be checked.');
        $geminiStoreDocumentsPanelHtml = AdminPageSupport::renderInitialGeminiStoreDocumentsPanel(
            $providerHasStoreCache,
            $provider,
            function (array $storeOverview): array {
                return $this->getInitialGeminiStoreSelection($storeOverview);
            },
            function (string $storeName, string $storeLabel, array $documents): void {
                $this->renderGeminiStoreDocumentsPanel($storeName, $storeLabel, $documents);
            }
        );
        $conversationsHtml = AdminPageSupport::captureHtml(function (): void {
            $this->renderConversationsTable();
        });

        $renderer = new AdminPageRenderer();
        $renderer->render([
            'activeTab' => $activeTab,
            'allPostTypes' => $allPostTypes,
            'availableProviders' => $availableProviders,
            'connectionStatus' => $connectionStatus,
            'conversationTrimCharLimit' => $conversationTrimCharLimit,
            'conversationTrimMessageLimit' => $conversationTrimMessageLimit,
            'conversationsHtml' => $conversationsHtml,
            'currentPromptLabel' => $currentPromptLabel,
            'customPrompt' => $customPrompt,
            'customPromptName' => $customPromptName,
            'defaultModel' => $defaultModel,
            'defaultPrompt' => $defaultPrompt,
            'documentsApiStatusColor' => $documentsApiStatus['color'],
            'documentsApiStatusLabel' => $documentsApiStatus['label'],
            'documentsApiStatusMessage' => $documentsApiStatus['message'],
            'documentsTabUrl' => $documentsTabUrl,
            'frontendAiInterface' => $frontendAiInterface,
            'frontendAiPageId' => $frontendAiPageId,
            'frontendAiPageTitle' => $frontendAiPageTitle,
            'frontendAiPageUrl' => $frontendAiPageUrl,
            'generalTabUrl' => $generalTabUrl,
            'geminiStoreDocumentsPanelHtml' => $geminiStoreDocumentsPanelHtml,
            'geminiStoresHtml' => $geminiStoresHtml,
            'hasReferencedDocumentCache' => $hasReferencedDocumentCache,
            'hasValidSavedApiKey' => $hasValidSavedApiKey,
            'includeReferencedDocuments' => $includeReferencedDocuments,
            'inlineStyleHidden' => self::INLINE_STYLE_HIDDEN,
            'isGeminiProvider' => $provider instanceof Gemini,
            'localConversationArchiveLimit' => $localConversationArchiveLimit,
            'modelPromptRows' => $modelPromptRows,
            'modelStatuses' => $modelStatuses,
            'models' => $models,
            'postTypes' => $postTypes,
            'preserveDataOnUninstall' => $preserveDataOnUninstall,
            'promptHistoryItems' => $promptHistoryItems,
            'promptHistoryLimit' => $promptHistoryLimit,
            'promptsTabUrl' => $promptsTabUrl,
            'providerHasStoreCache' => $providerHasStoreCache,
            'providerStoreCacheLabel' => $providerStoreCacheTime > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $providerStoreCacheTime) : '',
            'providerStoreCacheTime' => $providerStoreCacheTime,
            'providerStoreError' => $providerStoreError,
            'referencedCacheLabel' => $referencedCacheTime > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $referencedCacheTime) : '',
            'referencedCacheTime' => $referencedCacheTime,
            'referencedDebug' => $referencedDebug,
            'referencedDocumentsHtml' => $referencedDocumentsHtml,
            'selectedModel' => $selectedModel,
            'selectedProvider' => $selectedProvider,
            'statusColorError' => self::STATUS_COLOR_ERROR,
            'statusColorNeutral' => self::STATUS_COLOR_NEUTRAL,
            'statusColorSuccess' => self::STATUS_COLOR_SUCCESS,
            'storeEnabled' => $storeEnabled,
            'storesTabUrl' => $storesTabUrl,
            'conversationsTabUrl' => $conversationsTabUrl,
        ]);
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
     * Clear local tracking after switching/deleting the active Gemini store.
     *
     * @return void
     */
    private function clearLocalIndexTracking(): void {
        $documentStore = new DocumentStore();
        $documentStore->clearAllTrackedDocuments();
        HTML2MD::clearAllIndexedState();
    }

    /**
     * Reconcile local indexed status with the active Gemini store content.
     *
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

        HTML2MD::reconcileIndexedPostsWithRemoteDocuments($activeStoreDocuments);

        $documentStore = new DocumentStore();
        $documentStore->reconcileSelectionTargetsWithRemote($activeStoreDocuments);
        $documentStore->reconcileTrackedDocumentsWithRemote($activeStoreDocuments);
    }

    /**
     * AJAX: Clear saved prompt history immediately
     *
     * @return void
     */
    public function ajaxClearPromptHistory(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
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
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $entryId = isset($_POST['entry_id']) ? sanitize_text_field(wp_unslash($_POST['entry_id'])) : '';
        if ($entryId === '') {
            wp_send_json_error(['message' => 'Invalid prompt history entry.'], 400);
        }

        $history = PromptSupport::normalizePromptHistoryEntries(get_option(self::OPTION_PROMPT_HISTORY, []));
        if (empty($history)) {
            wp_send_json_success(['message' => 'History is already empty.']);
            return;
        }

        $newHistory = [];
        $found = false;
        foreach ($history as $entry) {
            if ((string) ($entry['entry_id'] ?? '') === $entryId) {
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
        $conversationId = isset($_POST['conversation_id']) ? $this->sanitizeConversationId(wp_unslash($_POST['conversation_id'])) : '';
        $requestedModel = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
        $temporaryPrompt = isset($_POST['temporary_prompt']) ? PromptSupport::normalizePromptInput($_POST['temporary_prompt']) : '';

        if (empty($rawMessages)) {
            wp_send_json_error(['message' => 'No messages provided']);
        }

        $messages = $this->normalizeAjaxChatMessages($rawMessages);

        try {
            $provider = ProviderFactory::make();
            $selectedModel = $this->resolveRequestedModel($provider, $requestedModel);

            $latestUserMessage = $this->extractLatestUserMessage($messages);
            $fullMessages = $this->buildFullConversationMessages($conversationId, $messages, $latestUserMessage);
            $context = $this->compactConversationForRequest($fullMessages);

            $result = $provider->search($context['messages'], $selectedModel, $temporaryPrompt !== '' ? $temporaryPrompt : null);
            $this->appendAiResponseToConversation($fullMessages, $result);

            $this->recordConversationUsage($conversationId, $fullMessages, $context['summary'], $result, $provider);

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
     * AJAX: Rename a stored AI conversation summary.
     *
     * @return void
     */
    public function ajaxRenameConversation(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $conversationId = $this->requireConversationIdFromPost();

        $summary = isset($_POST['summary']) ? sanitize_text_field(wp_unslash($_POST['summary'])) : '';
        $summary = trim($summary);
        if ($summary === '') {
            wp_send_json_error(['message' => 'Conversation name cannot be empty.'], 400);
        }

        $conversations = $this->getConversationOption();
        $this->requireStoredConversation($conversations, $conversationId);

        $conversations[$conversationId]['summary'] = $summary;
        update_option(self::OPTION_CONVERSATIONS, $conversations, false);

        wp_send_json_success([
            'message' => 'Conversation renamed.',
            'summary' => $summary,
        ]);
    }

    /**
     * AJAX: Return frontend conversation summaries for the AI workspace.
     *
     * @return void
     */
    public function ajaxGetFrontendConversations(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $conversations = array_map(function (array $entry): array {
            return $this->exportConversationSummaryForFrontend($entry);
        }, $this->getConversationLog());

        wp_send_json_success([
            'conversations' => $conversations,
        ]);
    }

    /**
     * AJAX: Return a single stored frontend conversation with messages.
     *
     * @return void
     */
    public function ajaxGetFrontendConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $conversationId = $this->requireConversationIdFromPost();

        $conversations = $this->getConversationOption();
        $conversation = $this->requireStoredConversation($conversations, $conversationId);

        wp_send_json_success([
            'conversation' => $this->exportConversationForFrontend($conversation),
        ]);
    }

    /**
     * AJAX: Resolve managed source URLs to canonical page/document labels.
     *
     * @return void
     */
    public function ajaxResolveSourceReferences(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $rawUrls = isset($_POST['urls']) && is_array($_POST['urls']) ? wp_unslash($_POST['urls']) : [];
        $resolved = [];

        foreach ($rawUrls as $rawUrl) {
            $url = is_string($rawUrl) ? trim($rawUrl) : '';
            if ($url === '') {
                continue;
            }

            $reference = $this->resolveManagedSourceReference($url);
            if (empty($reference)) {
                continue;
            }

            $resolved[$url] = $reference;
        }

        wp_send_json_success([
            'references' => $resolved,
        ]);
    }

    /**
     * AJAX: Rename a frontend conversation without requiring wp-admin access.
     *
     * @return void
     */
    public function ajaxFrontendRenameConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $conversationId = $this->requireConversationIdFromPost();
        $summary = isset($_POST['summary']) ? sanitize_text_field(wp_unslash($_POST['summary'])) : '';
        $summary = trim($summary);

        if ($summary === '') {
            wp_send_json_error(['message' => 'Conversation name cannot be empty.'], 400);
        }

        $conversations = $this->getConversationOption();
        $this->requireStoredConversation($conversations, $conversationId);

        $conversations[$conversationId]['summary'] = $summary;
        update_option(self::OPTION_CONVERSATIONS, $conversations, false);

        wp_send_json_success([
            'summary' => $summary,
        ]);
    }

    /**
     * AJAX: Delete a stored AI conversation.
     *
     * @return void
     */
    public function ajaxDeleteConversation(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS], 403);
        }

        $conversationId = $this->requireConversationIdFromPost();

        $conversations = $this->getConversationOption();
        $this->requireStoredConversation($conversations, $conversationId);

        unset($conversations[$conversationId]);
        update_option(self::OPTION_CONVERSATIONS, $conversations, false);

        wp_send_json_success([
            'message' => 'Conversation deleted.',
        ]);
    }

    /**
     * AJAX: Delete a frontend conversation without requiring wp-admin access.
     *
     * @return void
     */
    public function ajaxFrontendDeleteConversation(): void {
        check_ajax_referer('geweb_ai_search_search', 'nonce');

        $conversationId = $this->requireConversationIdFromPost();

        $conversations = $this->getConversationOption();
        $this->requireStoredConversation($conversations, $conversationId);

        unset($conversations[$conversationId]);
        update_option(self::OPTION_CONVERSATIONS, $conversations, false);

        wp_send_json_success([
            'deleted' => true,
        ]);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function getConversationOption(): array {
        $conversations = get_option(self::OPTION_CONVERSATIONS, []);
        return is_array($conversations) ? $conversations : [];
    }

    /**
     * @return string
     */
    private function requireConversationIdFromPost(): string {
        $conversationId = isset($_POST['conversation_id']) ? $this->sanitizeConversationId(wp_unslash($_POST['conversation_id'])) : '';
        if ($conversationId === '') {
            wp_send_json_error(['message' => self::MESSAGE_MISSING_CONVERSATION_ID], 400);
        }

        return $conversationId;
    }

    /**
     * @param array<string,array<string,mixed>> $conversations
     * @param string $conversationId
     * @return array<string,mixed>
     */
    private function requireStoredConversation(array $conversations, string $conversationId): array {
        if (!isset($conversations[$conversationId]) || !is_array($conversations[$conversationId])) {
            wp_send_json_error(['message' => self::MESSAGE_CONVERSATION_NOT_FOUND], 404);
        }

        return $conversations[$conversationId];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getConversationLog(): array {
        return array_values($this->getConversationOption());
    }

    /**
     * @param string $conversationId
     * @param array<int,array<string,mixed>> $messages
     * @param string $contextSummary
     * @param array<string,mixed> $result
     * @param AIProviderInterface $provider
     * @return void
     */
    private function recordConversationUsage(string $conversationId, array $messages, string $contextSummary, array $result, AIProviderInterface $provider): void {
        $conversationId = $conversationId !== '' ? $conversationId : 'geweb-ai-' . wp_generate_password(12, false, false);
        $conversations = $this->getConversationOption();
        $now = time();
        $meta = isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [];
        $usage = isset($meta['usage']) && is_array($meta['usage']) ? $meta['usage'] : [];

        if (isset($conversations[$conversationId]) && is_array($conversations[$conversationId])) {
            $existing = $conversations[$conversationId];
        } else {
            $existing = [
                'id' => $conversationId,
                'summary' => $this->buildConversationSummary($messages),
                'started_at' => $now,
                'last_used_at' => $now,
                'provider' => $provider->getProviderLabel(),
                'model' => method_exists($provider, 'getModel') ? $provider->getModel() : '',
                'request_count' => 0,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'estimated_cost_usd' => 0.0,
                'messages' => [],
                'context_summary' => '',
            ];
        }

        if (trim((string) ($existing['summary'] ?? '')) === '') {
            $existing['summary'] = $this->buildConversationSummary($messages);
        }

        $existing['last_used_at'] = $now;
        $existing['provider'] = isset($meta['provider']) ? (string) $meta['provider'] : $provider->getProviderLabel();
        if (isset($meta['model'])) {
            $existing['model'] = (string) $meta['model'];
        } elseif (method_exists($provider, 'getModel')) {
            $existing['model'] = $provider->getModel();
        } else {
            $existing['model'] = '';
        }
        $existing['request_count'] = (int) ($existing['request_count'] ?? 0) + 1;
        $existing['input_tokens'] = (int) ($existing['input_tokens'] ?? 0) + (int) ($usage['input_tokens'] ?? 0);
        $existing['output_tokens'] = (int) ($existing['output_tokens'] ?? 0) + (int) ($usage['output_tokens'] ?? 0);
        $existing['total_tokens'] = (int) ($existing['total_tokens'] ?? 0) + (int) ($usage['total_tokens'] ?? 0);
        $existing['estimated_cost_usd'] = (float) ($existing['estimated_cost_usd'] ?? 0) + (float) ($meta['estimated_cost_usd'] ?? 0);
        $existing['messages'] = $this->normalizeConversationMessages($messages);
        $existing['context_summary'] = $contextSummary;

        $conversations[$conversationId] = $existing;

        uasort($conversations, static function (array $a, array $b): int {
            return ((int) ($b['last_used_at'] ?? 0)) <=> ((int) ($a['last_used_at'] ?? 0));
        });

        update_option(self::OPTION_CONVERSATIONS, array_slice($conversations, 0, self::DEFAULT_CONVERSATION_LIMIT, true), false);
    }

    /**
     * @param string $url
     * @return array<string,string>
     */
    private function resolveManagedSourceReference(string $url): array {
        $normalizedUrl = $this->normalizeManagedSourceUrl($url);
        if ($normalizedUrl === '') {
            return [];
        }

        $canonicalUrl = $normalizedUrl;
        $label = $this->formatManagedSourcePath($normalizedUrl);
        $title = '';

        $postId = $this->extractPostIdFromManagedUrl($normalizedUrl);
        if ($postId > 0) {
            $permalink = get_permalink($postId);
            if (is_string($permalink) && $permalink !== '') {
                $canonicalUrl = $permalink;
                $label = $this->formatManagedSourcePath($permalink);
            }

            $postTitle = get_the_title($postId);
            if (is_string($postTitle) && trim($postTitle) !== '') {
                $title = trim($postTitle);
            }

            $postLabel = $this->buildManagedSourceLabelFromPost($postId, $canonicalUrl, $title);
            if ($postLabel !== '') {
                $label = $postLabel;
            }
        }

        if ($label === '') {
            $label = $title !== '' ? $title : $normalizedUrl;
        }

        return [
            'url' => $canonicalUrl,
            'label' => $label,
            'title' => $title,
        ];
    }

    /**
     * @param int $postId
     * @param string $url
     * @param string $title
     * @return string
     */
    private function buildManagedSourceLabelFromPost(int $postId, string $url, string $title): string {
        $label = $this->formatManagedSourcePath($url);
        if ($label === '') {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
                $label = trim((string) $post->post_name);
            }
        }

        if ($label === '' && $title !== '') {
            $label = $title;
        }

        return $label;
    }

    /**
     * @param string $url
     * @return string
     */
    private function normalizeManagedSourceUrl(string $url): string {
        $url = trim($url);
        $normalizedUrl = '';
        if ($url === '') {
            return $normalizedUrl;
        }

        $siteUrl = home_url('/');
        $siteParts = wp_parse_url($siteUrl);
        $candidate = wp_http_validate_url($url);

        if ($candidate === false && strpos($url, '/') === 0) {
            $candidate = home_url($url);
        }

        if ($candidate !== false) {
            $candidateParts = wp_parse_url($candidate);
            if (
                !empty($siteParts['host']) &&
                !empty($candidateParts['host']) &&
                strtolower((string) $siteParts['host']) === strtolower((string) $candidateParts['host'])
            ) {
                $normalizedUrl = $candidate;
            }
        }

        return $normalizedUrl;
    }

    /**
     * @param string $url
     * @return int
     */
    private function extractPostIdFromManagedUrl(string $url): int {
        $parts = wp_parse_url($url);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        foreach (['page_id', 'p'] as $key) {
            if (!empty($query[$key])) {
                return (int) $query[$key];
            }
        }

        $postId = url_to_postid($url);
        return $postId > 0 ? $postId : 0;
    }

    /**
     * @param string $url
     * @return string
     */
    private function formatManagedSourcePath(string $url): string {
        $parts = wp_parse_url($url);
        $formattedPath = '';
        if (!is_array($parts)) {
            return $formattedPath;
        }

        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($path !== '') {
            $formattedPath = trailingslashit($path);
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        if ($formattedPath === '') {
            if (!empty($query['page_id'])) {
                $formattedPath = 'page ' . (int) $query['page_id'];
            } elseif (!empty($query['p'])) {
                $formattedPath = 'post ' . (int) $query['p'];
            }
        }

        return $formattedPath;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{role:string,content:string}|null
     */
    private function extractLatestUserMessage(array $messages): ?array {
        for ($index = count($messages) - 1; $index >= 0; $index -= 1) {
            $message = $messages[$index] ?? null;
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : '';
            $content = trim((string) ($message['content'] ?? ''));
            if ($role !== 'user' || $content === '') {
                continue;
            }

            return [
                'role' => 'user',
                'content' => $content,
            ];
        }

        return null;
    }

    /**
     * @param string $conversationId
     * @param array<int,array<string,mixed>> $incomingMessages
     * @param array{role:string,content:string}|null $latestUserMessage
     * @return array<int,array{role:string,content:string}>
     */
    private function buildFullConversationMessages(string $conversationId, array $incomingMessages, ?array $latestUserMessage): array {
        $conversations = $this->getConversationOption();
        $existing = $conversationId !== '' && isset($conversations[$conversationId]) && is_array($conversations[$conversationId])
            ? $conversations[$conversationId]
            : [];
        $storedMessages = $this->normalizeConversationMessages(isset($existing['messages']) && is_array($existing['messages']) ? $existing['messages'] : []);

        if (empty($storedMessages)) {
            return !empty($incomingMessages) ? $this->normalizeConversationMessages($incomingMessages) : [];
        }

        if ($latestUserMessage === null) {
            return $storedMessages;
        }

        $lastStored = end($storedMessages);
        if (!is_array($lastStored) || ($lastStored['role'] ?? '') !== 'user' || ($lastStored['content'] ?? '') !== $latestUserMessage['content']) {
            $storedMessages[] = $latestUserMessage;
        }

        return $storedMessages;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<int,array<string,mixed>>
     */
    private function normalizeConversationMessages(array $messages): array {
        $normalized = [];
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : 'user';
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $sources = isset($message['sources']) && is_array($message['sources'])
                ? array_values(array_filter($message['sources'], 'is_array'))
                : [];
            $meta = isset($message['meta']) && is_array($message['meta'])
                ? $message['meta']
                : [];

            $normalized[] = [
                'role' => $role === 'model' ? 'model' : 'user',
                'content' => $role === 'model' ? wp_kses_post($content) : sanitize_textarea_field($content),
                'sources' => $sources,
                'meta' => $role === 'model' ? $meta : [],
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool}
     */
    private function compactConversationForRequest(array $messages): array {
        $messages = $this->normalizeConversationMessages($messages);
        if (empty($messages)) {
            return [
                'messages' => [],
                'summary' => '',
                'compacted' => false,
            ];
        }

        $maxMessages = $this->getConversationTrimMessageLimit();
        $maxChars = $this->getConversationTrimCharLimit();
        if (count($messages) <= $maxMessages && $this->getConversationMessageLength($messages) <= $maxChars) {
            return [
                'messages' => $messages,
                'summary' => '',
                'compacted' => false,
            ];
        }

        $recentCount = max(2, $maxMessages - 1);
        $recentMessages = array_slice($messages, -$recentCount);
        $olderMessages = array_slice($messages, 0, max(0, count($messages) - $recentCount));
        $summary = $this->buildConversationContextSummary($olderMessages);

        $compactedMessages = $recentMessages;
        if ($summary !== '') {
            array_unshift($compactedMessages, [
                'role' => 'user',
                'content' => $summary,
            ]);
        }

        while ($this->getConversationMessageLength($compactedMessages) > $maxChars && count($compactedMessages) > 3) {
            $removalIndex = $summary !== '' ? 1 : 0;
            if (!isset($compactedMessages[$removalIndex])) {
                break;
            }
            array_splice($compactedMessages, $removalIndex, 1);
        }

        return [
            'messages' => array_values($compactedMessages),
            'summary' => $summary,
            'compacted' => true,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return int
     */
    private function getConversationMessageLength(array $messages): int {
        $total = 0;
        foreach ($messages as $message) {
            $total += strlen(wp_strip_all_tags((string) ($message['content'] ?? '')));
        }

        return $total;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return string
     */
    private function buildConversationContextSummary(array $messages): string {
        if (empty($messages)) {
            return '';
        }

        $lines = ['Earlier conversation summary:'];
        $maxLines = 8;
        foreach ($messages as $message) {
            if (!isset($message['content']) || trim($message['content']) === '') {
                continue;
            }

            $prefix = ($message['role'] ?? '') === 'model'
                ? 'Assistant answered: '
                : 'User asked: ';
            $content = wp_strip_all_tags((string) ($message['content'] ?? ''));
            $content = preg_replace(self::REGEX_WHITESPACE, ' ', $content);
            $content = is_string($content) ? trim($content) : (string) ($message['content'] ?? '');

            if (function_exists('mb_strimwidth')) {
                $content = mb_strimwidth($content, 0, 220, '...');
            } elseif (strlen($content) > 220) {
                $content = substr($content, 0, 217) . '...';
            }

            $lines[] = '- ' . $prefix . $content;
            if (count($lines) >= ($maxLines + 1)) {
                break;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $conversation
     * @return array<string,mixed>
     */
    private function exportConversationSummaryForFrontend(array $conversation): array {
        return [
            'id' => (string) ($conversation['id'] ?? ''),
            'summary' => trim((string) ($conversation['summary'] ?? '')) !== '' ? (string) $conversation['summary'] : 'Untitled conversation',
            'savedAt' => (int) (($conversation['last_used_at'] ?? $conversation['started_at'] ?? time()) * 1000),
            'compacted' => trim((string) ($conversation['context_summary'] ?? '')) !== '',
        ];
    }

    /**
     * @param array<string,mixed> $conversation
     * @return array<string,mixed>
     */
    private function exportConversationForFrontend(array $conversation): array {
        $export = $this->exportConversationSummaryForFrontend($conversation);
        $export['messages'] = $this->normalizeConversationMessages(
            isset($conversation['messages']) && is_array($conversation['messages']) ? $conversation['messages'] : []
        );

        return $export;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return string
     */
    private function buildConversationSummary(array $messages): string {
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : '';
            $content = trim((string) ($message['content'] ?? ''));
            if ($role !== 'user' || $content === '') {
                continue;
            }

            $normalized = preg_replace(self::REGEX_WHITESPACE, ' ', $content);
            $normalized = is_string($normalized) ? trim($normalized) : $content;

            if (function_exists('mb_strimwidth')) {
                return mb_strimwidth($normalized, 0, 120, '...');
            }

            return strlen($normalized) > 120 ? substr($normalized, 0, 117) . '...' : $normalized;
        }

        return 'Untitled conversation';
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
        $provider = ProviderFactory::make();
        $models = $provider->getModels();
        $selectedModel = $provider->getModel();
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
            'frontend_ai_interface' => $this->getFrontendAiInterface(),
            'frontend_ai_page_url' => $this->getCurrentFrontendAiPageUrl(),
            'frontend_ai_exit_url' => $this->getFrontendAiExitUrl(),
            'frontend_ai_manage_conversations_url' => current_user_can('manage_options') ? $this->getTabUrl('conversations') : '',
            'is_frontend_ai_page' => $this->isFrontendAiPageRequest(),
            'frontend_ai_conversation_id' => $this->getRequestedFrontendConversationId(),
            'frontend_ai_initial_query' => $this->getRequestedFrontendQuery(),
            'conversation_trim_message_limit' => $this->getConversationTrimMessageLimit(),
            'conversation_trim_char_limit' => $this->getConversationTrimCharLimit(),
            'local_conversation_archive_limit' => $this->getLocalConversationArchiveLimit(),
            'i18n' => [
                'openAiSearch' => __('Open AI Search', 'geweb-ai-search'),
                'askAi' => __('Ask AI', 'geweb-ai-search'),
                'thinking' => __('Thinking...', 'geweb-ai-search'),
                'couldNotStart' => __('Could not start the AI search. Please try again.', 'geweb-ai-search'),
                'connectionError' => __('Connection error. Please try again.', 'geweb-ai-search'),
                'answerError' => __('Error: Unable to get response', 'geweb-ai-search'),
                'responseDetails' => __('Response details', 'geweb-ai-search'),
                'clickAnswerForDetails' => __('Click the answer to show response details.', 'geweb-ai-search'),
                'hideDetails' => __('Hide details', 'geweb-ai-search'),
                'showDetails' => __('Show details', 'geweb-ai-search'),
                'earlierTrimmed' => __('Earlier messages were trimmed to keep the conversation context compact.', 'geweb-ai-search'),
                'noChatsYet' => __('No chats yet.', 'geweb-ai-search'),
                'copyAnswer' => __('Copy answer', 'geweb-ai-search'),
                'copyConversation' => __('Copy conversation', 'geweb-ai-search'),
                'temporaryPrompt' => __('Temporary prompt', 'geweb-ai-search'),
                'temporaryPromptPlaceholder' => __('Optional prompt override for this question only...', 'geweb-ai-search'),
                'toggleTemporaryPrompt' => __('Toggle temporary prompt', 'geweb-ai-search'),
                'copied' => __('Copied', 'geweb-ai-search'),
                'copyFailed' => __('Could not copy', 'geweb-ai-search'),
                'savedChat' => __('Saved chat', 'geweb-ai-search'),
                'untitledConversation' => __('Untitled conversation', 'geweb-ai-search'),
                'noSourcesYet' => __('No source links yet.', 'geweb-ai-search'),
                'renameConversation' => __('Rename conversation', 'geweb-ai-search'),
                'removeConversationConfirm' => __('Remove this conversation from the current search context?', 'geweb-ai-search'),
                'mentionedInAnswer' => __('Mentioned in answer', 'geweb-ai-search'),
                'newChat' => __('New chat', 'geweb-ai-search'),
                'linksToPages' => __('Links to pages and documents used in the answer.', 'geweb-ai-search'),
                'showResults' => __('Show results', 'geweb-ai-search'),
                'hideResults' => __('Hide results', 'geweb-ai-search'),
                'modelLabel' => __('Model', 'geweb-ai-search'),
                'manageConversations' => __('Manage conversations', 'geweb-ai-search'),
                'searchResultsIntro' => __('Use your normal site search above to update these WordPress results without leaving the AI workspace.', 'geweb-ai-search'),
            ],
        ]);
    }

    /**
     * Render the AI search workspace inside normal page content.
     *
     * @param array<string,mixed> $atts
     * @return string
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
     * Remove the logged-in admin-bar search control on the dedicated frontend AI page.
     *
     * @param \WP_Admin_Bar $adminBar
     * @return void
     */
    public function maybeRemoveFrontendAdminBarSearch(\WP_Admin_Bar $adminBar): void {
        if (is_admin() || !$this->isFrontendAiPageRequest()) {
            return;
        }

        $adminBar->remove_node('search');
    }

    /**
     * Render modal windows in footer
     *
     * @return void
     */
    public function renderModals(): void {
        $isFrontendAiPage = $this->isFrontendAiPageRequest();
        $provider = ProviderFactory::make();
        $models = $provider->getModels();
        $selectedModel = $provider->getModel();

        if ($selectedModel !== '' && !in_array($selectedModel, $models, true) && method_exists($provider, 'getDefaultModel')) {
            array_unshift($models, $selectedModel);
            $models = array_values(array_unique($models));
        }

        if ($isFrontendAiPage && $this->frontendAiPageModalRendered) {
            return;
        }

        if ($isFrontendAiPage) {
            $this->frontendAiPageModalRendered = true;
        }

        $tagName = $isFrontendAiPage ? 'section' : 'dialog';
        $pageViewClass = $isFrontendAiPage ? ' geweb-aisearch-modal-window--page' : '';
        $pageViewAttribute = $isFrontendAiPage ? ' data-geweb-page-view="1"' : '';
        $title = isset($this->renderOverrides['title']) && trim((string) $this->renderOverrides['title']) !== ''
            ? (string) $this->renderOverrides['title']
            : (string) apply_filters('geweb_aisearch_ai_modal_title', 'AI Assistant');
        $showManageLink = !isset($this->renderOverrides['show_manage_link']) || !empty($this->renderOverrides['show_manage_link']);
        ?>
        <<?php echo esc_html($tagName); ?> id="geweb-ai-modal" class="geweb-aisearch-modal-window geweb-aisearch-modal-window--<?php echo esc_attr($this->getFrontendAiInterface()); ?><?php echo esc_attr($pageViewClass); ?>" aria-label="<?php echo esc_attr($title); ?>"<?php echo $pageViewAttribute; ?>>
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
                            <div class="geweb-ai-panel-title geweb-ai-panel-title--inline"><?php echo esc_html__('Chat', 'geweb-ai-search'); ?></div>
                            <div class="geweb-ai-panel-heading-actions geweb-ai-panel-heading-actions--conversation">
                                <button type="button" class="button button-small geweb-ai-new-conversation geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">+</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('New', 'geweb-ai-search'); ?></span>
                                </button>
                                <?php if ($showManageLink && current_user_can('manage_options')): ?>
                                    <a class="button button-small geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" href="<?php echo esc_url($this->getTabUrl('conversations')); ?>">
                                        <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                                        <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Manage', 'geweb-ai-search'); ?></span>
                                    </a>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="button button-small geweb-ai-panel-collapse" data-panel-toggle="left" aria-expanded="true" aria-label="<?php echo esc_attr__('Collapse conversations panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Collapse conversations panel', 'geweb-ai-search'); ?>">
                                <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">◀</span>
                            </button>
                        </div>
                    </div>
                    <div class="geweb-ai-current-conversation">
                        <div class="geweb-ai-current-conversation-label"><?php echo esc_html__('Current conversation', 'geweb-ai-search'); ?></div>
                        <div id="geweb-ai-current-conversation-summary" class="geweb-ai-current-conversation-summary"><?php echo esc_html__('Untitled conversation', 'geweb-ai-search'); ?></div>
                        <div class="geweb-ai-current-conversation-actions">
                            <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-first" id="geweb-ai-copy-conversation" aria-label="<?php echo esc_attr__('Copy conversation', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Copy conversation', 'geweb-ai-search'); ?>">
                                <span class="geweb-ai-overview-action-icon" aria-hidden="true">⧉</span>
                                <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Copy', 'geweb-ai-search'); ?></span>
                            </button>
                            <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-first" id="geweb-ai-rename-conversation" aria-label="<?php echo esc_attr__('Rename conversation', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Rename conversation', 'geweb-ai-search'); ?>">
                                <span class="geweb-ai-overview-action-icon" aria-hidden="true">✎</span>
                                <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Rename', 'geweb-ai-search'); ?></span>
                            </button>
                            <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-first" id="geweb-ai-delete-conversation" aria-label="<?php echo esc_attr__('Remove conversation', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Remove conversation', 'geweb-ai-search'); ?>">
                                <span class="geweb-ai-overview-action-icon" aria-hidden="true">−</span>
                                <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Remove', 'geweb-ai-search'); ?></span>
                            </button>
                        </div>
                    </div>
                    <div id="geweb-ai-conversation-overview" class="geweb-ai-conversation-overview"></div>
                </aside>
                <button type="button" class="button button-small geweb-ai-panel-collapse geweb-ai-panel-reopen geweb-ai-panel-reopen--left" data-panel-toggle="left" aria-expanded="true" aria-label="<?php echo esc_attr__('Expand conversations panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Expand conversations panel', 'geweb-ai-search'); ?>">
                    <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">▶</span>
                </button>
                <div class="geweb-ai-pane-resizer geweb-ai-pane-resizer--left" data-resize-target="left" aria-orientation="vertical" aria-label="<?php echo esc_attr__('Resize conversation panel', 'geweb-ai-search'); ?>"></div>
                <div class="geweb-ai-main-panel">
                    <div class="answer-box"></div>
                    <div class="question-box">
                        <?php if (!empty($models)): ?>
                            <div class="geweb-ai-question-toolbar">
                                <label for="geweb-ai-model-selector" class="geweb-ai-model-selector-label"><?php echo esc_html__('Model', 'geweb-ai-search'); ?></label>
                                <select id="geweb-ai-model-selector" class="geweb-ai-model-selector">
                                    <?php foreach ($models as $model): ?>
                                        <option value="<?php echo esc_attr($model); ?>" <?php selected($selectedModel, $model); ?>><?php echo esc_html($model); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="button button-small geweb-ai-secondary-button geweb-ai-secondary-button--icon-first" id="geweb-ai-toggle-temp-prompt" aria-expanded="false" aria-controls="geweb-ai-temporary-prompt" title="<?php echo esc_attr__('Toggle temporary prompt', 'geweb-ai-search'); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">✎</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Prompt', 'geweb-ai-search'); ?></span>
                                </button>
                            </div>
                        <?php endif; ?>
                        <div class="geweb-ai-temporary-prompt-wrap" id="geweb-ai-temporary-prompt-wrap" hidden>
                            <label for="geweb-ai-temporary-prompt" class="geweb-ai-model-selector-label"><?php echo esc_html__('Temporary prompt', 'geweb-ai-search'); ?></label>
                            <textarea id="geweb-ai-temporary-prompt" class="geweb-ai-temporary-prompt" placeholder="<?php echo esc_attr__('Optional prompt override for this question only...', 'geweb-ai-search'); ?>"></textarea>
                        </div>
                        <textarea id="geweb-ai-query-display" placeholder="<?php echo esc_attr(apply_filters('geweb_aisearch_ai_textarea_placeholder', 'Ask AI a question...')); ?>"></textarea>
                        <button id="geweb-ask-ai-submit" class="btn" type="submit" disabled aria-label="<?php echo esc_attr__('Send AI question', 'geweb-ai-search'); ?>"></button>
                    </div>
                </div>
                <div class="geweb-ai-pane-resizer geweb-ai-pane-resizer--right" data-resize-target="right" aria-orientation="vertical" aria-label="<?php echo esc_attr__('Resize sources panel', 'geweb-ai-search'); ?>"></div>
                <aside class="geweb-ai-sources-panel" aria-label="<?php echo esc_attr__('Source references panel', 'geweb-ai-search'); ?>">
                    <div class="geweb-ai-panel-heading">
                        <div class="geweb-ai-panel-title"><?php echo esc_html__('Source references', 'geweb-ai-search'); ?></div>
                        <div class="geweb-ai-panel-heading-actions">
                            <?php if (current_user_can('manage_options')): ?>
                                <a class="button button-small geweb-ai-overview-action-button geweb-ai-overview-action-button--icon-first" href="<?php echo esc_url($this->getTabUrl('documents')); ?>">
                                    <span class="geweb-ai-overview-action-icon" aria-hidden="true">⚙</span>
                                    <span class="geweb-ai-overview-action-label"><?php echo esc_html__('Manage', 'geweb-ai-search'); ?></span>
                                </a>
                            <?php endif; ?>
                            <button type="button" class="button button-small geweb-ai-panel-collapse" data-panel-toggle="right" aria-expanded="true" aria-label="<?php echo esc_attr__('Collapse sources panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Collapse sources panel', 'geweb-ai-search'); ?>">
                                <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">▶</span>
                            </button>
                        </div>
                    </div>
                    <p class="geweb-ai-sources-help"><?php echo esc_html__('Pages, posts, and documents referenced by the current answer or restored conversation.', 'geweb-ai-search'); ?></p>
                    <div id="geweb-ai-sources" class="geweb-ai-sources"></div>
                </aside>
                <button type="button" class="button button-small geweb-ai-panel-collapse geweb-ai-panel-reopen geweb-ai-panel-reopen--right" data-panel-toggle="right" aria-expanded="true" aria-label="<?php echo esc_attr__('Expand sources panel', 'geweb-ai-search'); ?>" title="<?php echo esc_attr__('Expand sources panel', 'geweb-ai-search'); ?>">
                    <span class="geweb-ai-panel-collapse-icon" aria-hidden="true">◀</span>
                </button>
            </div>
        </<?php echo esc_html($tagName); ?>>
        <?php
    }

    /**
     * Render a simple normal-search-results panel above the AI chat workspace.
     *
     * @return void
     */
    private function renderFrontendAiSearchResultsPanel(): void {
        $query = $this->getRequestedFrontendQuery();
        $initialHeight = isset($this->renderOverrides['search_results_initial_height'])
            ? (int) $this->renderOverrides['search_results_initial_height']
            : 70;
        $initialHeight = max(0, min(100, $initialHeight));
        ?>
        <section class="geweb-ai-search-results-panel">
            <div class="geweb-ai-search-results-header">
                <div class="geweb-ai-panel-heading">
                    <div class="geweb-ai-panel-title geweb-ai-panel-title--inline"><?php echo esc_html__('Search Results', 'geweb-ai-search'); ?></div>
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
                        'post__not_in' => array_filter([$this->getFrontendAiPageId()]),
                    ]);
                    ?>
                    <p class="geweb-ai-search-results-intro"><?php echo esc_html__('These are the regular WordPress search results for the current query.', 'geweb-ai-search'); ?></p>
                    <?php if ($searchQuery->have_posts()): ?>
                        <ul class="geweb-ai-search-results-list">
                            <?php while ($searchQuery->have_posts()): $searchQuery->the_post(); ?>
                                <li class="geweb-ai-search-result-item">
                                    <a href="<?php the_permalink(); ?>" class="geweb-ai-search-result-link"><?php the_title(); ?></a>
                                    <div class="geweb-ai-search-result-excerpt"><?php echo esc_html($this->buildFrontendSearchResultExcerpt(get_the_ID())); ?></div>
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

    /**
     * Render the dedicated fullscreen AI page instead of the normal theme search template.
     *
     * @return void
     */
    public function maybeRenderFrontendAiPage(): void {
        if (!$this->isDedicatedFrontendAiPageRequest()) {
            return;
        }

        status_header(200);
        nocache_headers();
        add_filter('body_class', [$this, 'filterFrontendAiBodyClasses']);
        get_header();
        remove_filter('body_class', [$this, 'filterFrontendAiBodyClasses']);
        ?>
            <main id="geweb-ai-page-root" class="geweb-ai-page-root">
                <?php $this->renderModals(); ?>
            </main>
        <?php
        get_footer();
        exit;
    }

    /**
     * @return string
     */
    private function getFrontendAiPageUrl(): string {
        $pageId = $this->getFrontendAiPageId();
        if ($pageId > 0) {
            $baseUrl = get_permalink($pageId);
            if (is_string($baseUrl) && $baseUrl !== '') {
                $args = [];
                $query = $this->getRequestedFrontendQuery();
                if ($query !== '') {
                    $args[self::FRONTEND_AI_QUERY_VAR] = $query;
                }

                return !empty($args) ? add_query_arg($args, $baseUrl) : $baseUrl;
            }
        }

        $args = [
            'geweb_ai_chat' => '1',
        ];

        $query = $this->getRequestedFrontendQuery();
        if ($query !== '') {
            $args[self::FRONTEND_AI_QUERY_VAR] = $query;
        }

        return add_query_arg($args, home_url('/'));
    }

    /**
     * @return string
     */
    private function getCurrentFrontendAiPageUrl(): string {
        $pageId = $this->getFrontendAiPageId();
        if ($pageId > 0) {
            $pageUrl = get_permalink($pageId);
            if (is_string($pageUrl) && $pageUrl !== '') {
                return $pageUrl;
            }
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $requestUri = is_string($requestUri) ? $requestUri : '';

        if ($requestUri === '' || strpos($requestUri, '/wp-admin/') === 0) {
            return $this->getFrontendAiPageUrl();
        }

        $url = home_url($requestUri);
        $url = remove_query_arg(['geweb_ai_chat', 'geweb_ai_conversation', self::FRONTEND_AI_QUERY_VAR, 's'], $url);

        return $url;
    }

    /**
     * @param string $conversationId
     * @return string
     */
    public function getFrontendAiConversationUrl(string $conversationId = ''): string {
        $args = [];

        $query = $this->getRequestedFrontendQuery();
        if ($query !== '') {
            $args[self::FRONTEND_AI_QUERY_VAR] = $query;
        }

        $conversationId = $this->sanitizeConversationId($conversationId);
        if ($conversationId !== '') {
            $args['geweb_ai_conversation'] = $conversationId;
        }

        $pageId = $this->getFrontendAiPageId();
        if ($pageId > 0) {
            $pageUrl = get_permalink($pageId);
            if (is_string($pageUrl) && $pageUrl !== '') {
                return !empty($args) ? add_query_arg($args, $pageUrl) : $pageUrl;
            }
        }

        return add_query_arg($args, home_url('/'));
    }

    /**
     * @return string
     */
    private function getFrontendAiExitUrl(): string {
        $pageId = $this->getFrontendAiPageId();
        if ($pageId > 0) {
            $pageUrl = get_permalink($pageId);
            if (is_string($pageUrl) && $pageUrl !== '') {
                return $pageUrl;
            }
        }

        $query = $this->getRequestedFrontendQuery();
        if ($query !== '') {
            return add_query_arg('s', $query, home_url('/'));
        }

        return home_url('/');
    }

    /**
     * @return bool
     */
    private function isFrontendAiPageRequest(): bool {
        if ($this->shortcodePageViewActive || $this->isConfiguredFrontendAiPageRequest()) {
            return true;
        }

        return $this->isDedicatedFrontendAiPageRequest();
    }

    /**
     * @return bool
     */
    private function isDedicatedFrontendAiPageRequest(): bool {
        if ($this->getFrontendAiInterface() !== 'fullscreen') {
            return false;
        }

        $rewriteValue = get_query_var('geweb_ai_page', '');
        if ((string) $rewriteValue === '1') {
            return true;
        }

        $value = isset($_GET['geweb_ai_chat']) ? sanitize_text_field(wp_unslash($_GET['geweb_ai_chat'])) : '';
        return $value === '1';
    }

    /**
     * @return bool
     */
    private function isConfiguredFrontendAiPageRequest(): bool {
        $pageId = $this->getFrontendAiPageId();
        return $pageId > 0 && is_page($pageId);
    }

    /**
     * @return string
     */
    private function getRequestedFrontendConversationId(): string {
        $rewriteValue = get_query_var('geweb_ai_conversation', '');
        if (is_string($rewriteValue) && $rewriteValue !== '') {
            return $this->sanitizeConversationId($rewriteValue);
        }

        return isset($_GET['geweb_ai_conversation']) ? $this->sanitizeConversationId(wp_unslash($_GET['geweb_ai_conversation'])) : '';
    }

    /**
     * @return string
     */
    private function getRequestedFrontendQuery(): string {
        if (isset($_GET[self::FRONTEND_AI_QUERY_VAR])) {
            return sanitize_text_field(wp_unslash($_GET[self::FRONTEND_AI_QUERY_VAR]));
        }

        return isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function sanitizeConversationId($value): string {
        $value = is_string($value) ? $value : (string) $value;
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);
        return is_string($value) ? $value : '';
    }

    /**
     * @return int
     */
    private function getFrontendAiPageId(): int {
        $pageId = (int) get_option(self::OPTION_FRONTEND_AI_PAGE_ID, 0);
        if ($pageId > 0) {
            $post = get_post($pageId);
            if ($post instanceof \WP_Post && $post->post_type === 'page' && $post->post_status !== 'trash') {
                return $pageId;
            }
        }

        static $attemptedEnsure = false;
        if ($attemptedEnsure) {
            return 0;
        }

        $attemptedEnsure = true;
        $pageId = self::ensureFrontendAiPageExists();
        if ($pageId > 0) {
            return $pageId;
        }

        return 0;
    }

    /**
     * @return string
     */
    private function getFrontendAiInterface(): string {
        return $this->normalizeFrontendAiInterface((string) get_option(self::OPTION_FRONTEND_AI_INTERFACE, self::DEFAULT_FRONTEND_AI_INTERFACE));
    }

    /**
     * @return int
     */
    private function getConversationTrimMessageLimit(): int {
        return $this->sanitizePositiveIntOption(
            get_option(self::OPTION_CONVERSATION_TRIM_MESSAGE_LIMIT, self::DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT),
            self::DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT,
            2,
            200
        );
    }

    /**
     * @return int
     */
    private function getConversationTrimCharLimit(): int {
        return $this->sanitizePositiveIntOption(
            get_option(self::OPTION_CONVERSATION_TRIM_CHAR_LIMIT, self::DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT),
            self::DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT,
            500,
            200000
        );
    }

    /**
     * @return int
     */
    private function getLocalConversationArchiveLimit(): int {
        return $this->sanitizePositiveIntOption(
            get_option(self::OPTION_LOCAL_CONVERSATION_ARCHIVE_LIMIT, self::DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT),
            self::DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT,
            1,
            200
        );
    }

    /**
     * @param array<int,string> $classes
     * @return array<int,string>
     */
    public function filterFrontendAiBodyClasses(array $classes): array {
        $classes[] = 'geweb-ai-page';
        $classes[] = 'geweb-ai-page-open';
        return array_values(array_unique($classes));
    }

    /**
     * @param int $postId
     * @return string
     */
    private function buildFrontendSearchResultExcerpt(int $postId): string {
        $content = get_post_field('post_content', $postId);
        $content = is_string($content) ? wp_strip_all_tags($content) : '';
        $content = trim(preg_replace(self::REGEX_WHITESPACE, ' ', $content) ?? '');

        if ($content === '') {
            $content = wp_strip_all_tags((string) get_the_excerpt($postId));
            $content = trim(preg_replace(self::REGEX_WHITESPACE, ' ', $content) ?? '');
        }

        $content = ltrim($content, ". \t\n\r\0\x0B");
        if ($content === '') {
            return __('No preview text available for this result.', 'geweb-ai-search');
        }

        return wp_trim_words($content, 42);
    }

    /**
     * @param mixed $value
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    private function sanitizePositiveIntOption($value, int $default, int $min, int $max): int {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = intval($value);
        if ($normalized < $min) {
            return $min;
        }

        if ($normalized > $max) {
            return $max;
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalizeFrontendAiInterface($value): string {
        $normalized = sanitize_key((string) $value);
        if ($normalized === 'split') {
            return 'fullscreen';
        }

        return in_array($normalized, ['modal', 'fullscreen'], true) ? $normalized : self::DEFAULT_FRONTEND_AI_INTERFACE;
    }
}
?>
