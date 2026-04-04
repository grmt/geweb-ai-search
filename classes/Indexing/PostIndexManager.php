<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Handles post indexing workflows and admin UI integration.
 */
class PostIndexManager {
    private const OPTION_INCLUDE_REFERENCED_DOCUMENTS = 'geweb_aisearch_include_referenced_documents';
    private const OPTION_FRONTEND_AI_PAGE_ID = 'geweb_aisearch_frontend_ai_page_id';
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private const META_COMPARE_NOT_EXISTS = 'NOT EXISTS';
    private const COLOR_WARNING = '#996800';
    private const COLOR_SUCCESS = '#46b450';
    private const COLOR_INFO = '#2271b1';
    private const COLOR_MUTED = '#646970';
    private const COLOR_ERROR = '#d63638';
    private const META_DOCUMENT_NAME = 'geweb_aisearch_document_name';
    private const META_EXCLUDE = 'geweb_aisearch_exclude';
    private const META_STATUS = 'geweb_aisearch_status';
    private const META_LAST_INDEXED = 'geweb_aisearch_last_indexed';
    private const META_LAST_ERROR = 'geweb_aisearch_last_error';
    private const NONCE_ACTION = 'geweb_aisearch_post_settings';
    private const NONCE_NAME = 'geweb_aisearch_post_settings_nonce';
    private const CRON_HOOK_PROCESS = 'geweb_aisearch_process_post';

    public function __construct() {
        add_action('init', [$this, 'registerPostColumns']);
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('before_delete_post', [$this, 'deleteDocumentForPost']);
        add_action('wp_ajax_geweb_generate_library', [$this, 'ajaxGenerateLibrary']);
        add_action('wp_ajax_geweb_reupload_post', [$this, 'ajaxReuploadPost']);
        add_action('wp_ajax_geweb_toggle_exclude', [$this, 'ajaxToggleExclude']);
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('restrict_manage_posts', [$this, 'renderStatusFilter']);
        add_action('pre_get_posts', [$this, 'applyStatusFilter']);
        add_action(self::CRON_HOOK_PROCESS, [$this, 'processQueuedPost'], 10, 2);
    }

    public function registerPostColumns(): void {
        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (!empty($postTypes)) {
            foreach ($postTypes as $postType) {
                if ($postType === 'attachment') {
                    add_filter('manage_upload_columns', [$this, 'addAdminColumns']);
                    add_action('manage_media_custom_column', [$this, 'renderIndexedColumn'], 10, 2);
                    add_action('manage_media_custom_column', [$this, 'renderIdColumn'], 10, 2);
                    continue;
                }
                add_filter("manage_{$postType}_posts_columns", [$this, 'addAdminColumns']);
                add_action("manage_{$postType}_posts_custom_column", [$this, 'renderIndexedColumn'], 10, 2);
                add_action("manage_{$postType}_posts_custom_column", [$this, 'renderIdColumn'], 10, 2);
            }
        }
    }

    public function addAdminColumns(array $columns): array {
        $columns['geweb_post_id'] = 'ID';
        $columns['geweb_ai_indexed'] = 'AI Indexed';
        return $columns;
    }

    public function renderIdColumn(string $column, int $postId): void {
        if ($column === 'geweb_post_id') {
            echo esc_html((string) $postId);
        }
    }

    public function renderIndexedColumn(string $column, int $postId): void {
        if ($column === 'geweb_ai_indexed') {
            echo wp_kses($this->getColumnHtml($postId), $this->getColumnAllowedHtml());
        }
    }

    public function registerMetaBox(): void {
        $postTypes = get_option('geweb_aisearch_post_types', []);
        foreach ($postTypes as $postType) {
            add_meta_box('geweb-aisearch-settings', 'AI Search', [$this, 'renderMetaBox'], $postType, 'side', 'default');
        }
    }

    public function renderMetaBox(\WP_Post $post): void {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $statusData = $this->getStatusData($post->ID);
        ?>
        <p>
            <label>
                <input type="checkbox" name="geweb_aisearch_exclude" value="1" <?php checked($this->isExcluded($post->ID)); ?>>
                Exclude this content from AI indexing
            </label>
        </p>
        <p><strong>Status:</strong> <?php echo esc_html($statusData['label']); ?></p>
        <?php if (!empty($statusData['last_indexed'])): ?>
            <p><strong>Last indexed:</strong> <?php echo esc_html($statusData['last_indexed']); ?></p>
        <?php endif; ?>
        <?php if (!empty($statusData['error'])): ?>
            <p><strong>Last error:</strong> <?php echo esc_html($statusData['error']); ?></p>
        <?php endif; ?>
        <?php
    }

    public function onSavePost(int $postId, \WP_Post $post): void {
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId) || $post->post_status === 'auto-draft') {
            return;
        }

        if ($this->isFrontendAiPage($postId)) {
            update_post_meta($postId, self::META_EXCLUDE, '1');
            $this->clearScheduledProcessing($postId);
            $this->deleteDocumentForPost($postId, 'excluded');
            return;
        }

        $this->saveExcludeSetting($postId);
        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (in_array($post->post_type, $postTypes, true)) {
            if ($this->isExcluded($postId) || $post->post_status !== 'publish') {
                $this->clearScheduledProcessing($postId);
                $this->schedulePostProcessing($postId, 'delete');
                $this->setStatus($postId, 'removing');
                return;
            }

            $this->clearScheduledProcessing($postId);
            $this->setStatus($postId, 'uploading');
            $this->schedulePostProcessing($postId, 'index');
        }
    }

    public function ajaxGenerateLibrary(): void {
        check_ajax_referer('geweb_ai_search_generate_library', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS]);
        }

        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (empty($postTypes)) {
            wp_send_json_error(['message' => 'No post types selected']);
        }

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $perPage = 10;

        $totalQuery = new \WP_Query([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'meta_query' => $this->getIndexableMetaQuery(),
            'post__not_in' => $this->getNonIndexablePostIds(),
        ]);
        $total = $totalQuery->found_posts;

        $posts = get_posts([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'fields' => 'ids',
            'meta_query' => $this->getIndexableMetaQuery(),
            'post__not_in' => $this->getNonIndexablePostIds(),
        ]);

        $success = 0;
        $errors = 0;

        foreach ($posts as $postId) {
            try {
                $result = $this->indexPost((int) $postId, true);
                if ($result['success']) {
                    $success++;
                } else {
                    $errors++;
                }
            } catch (\Exception $e) {
                $this->excludeAfterFailure((int) $postId, $e->getMessage());
                $errors++;
            }
        }

        $processed = ($page - 1) * $perPage + count($posts);
        $hasMore = $processed < $total;

        wp_send_json_success([
            'processed' => $processed,
            'total' => $total,
            'success' => $success,
            'errors' => $errors,
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
        ]);
    }

    public static function renderButton(): void {
        ?>
        <tr>
            <th scope="row">Generate AI Library:</th>
            <td>
                <button type="button" id="geweb-generate-library" class="button">Generate Library</button>
                <p class="description">Process all published posts and upload them to Gemini for AI search.</p>
                <div id="geweb-generate-status"></div>
            </td>
        </tr>
        <?php
    }

    public function ajaxReuploadPost(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($postId <= 0) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS]);
        }

        if ($this->isExcluded($postId)) {
            delete_post_meta($postId, self::META_EXCLUDE);
        }

        $this->setStatus($postId, 'uploading');
        $result = $this->indexPost($postId, false);
        if (!$result['success']) {
            wp_send_json_error(['message' => $result['message'], 'html' => $this->getColumnHtml($postId)]);
        }

        wp_send_json_success(['message' => 'Indexed successfully.', 'html' => $this->getColumnHtml($postId)]);
    }

    public function ajaxToggleExclude(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $exclude = !empty($_POST['exclude']);

        if ($postId <= 0) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS]);
        }

        if ($exclude) {
            $this->setStatus($postId, 'removing');
            update_post_meta($postId, self::META_EXCLUDE, '1');
            $this->excludePost($postId);
            wp_send_json_success(['message' => 'Excluded from AI indexing.', 'html' => $this->getColumnHtml($postId)]);
        }

        delete_post_meta($postId, self::META_EXCLUDE);
        if (in_array(get_post_meta($postId, self::META_STATUS, true), ['excluded', 'error', 'removing'], true)) {
            $this->setStatus($postId, 'not_indexed', '');
        }

        wp_send_json_success(['message' => 'Included for AI indexing again.', 'html' => $this->getColumnHtml($postId)]);
    }

    public function deleteDocumentForPost(int $postId, string $statusAfterDelete = 'not_indexed'): void {
        $documentName = get_post_meta($postId, self::META_DOCUMENT_NAME, true);
        (new DocumentStore())->disassociatePost($postId);
        if (empty($documentName)) {
            $this->setStatus($postId, $statusAfterDelete, '');
            return;
        }

        try {
            ProviderFactory::make()->deleteDocument($documentName);
        } catch (\Exception $e) {
            // Ignore remote delete failures and continue clearing local metadata.
        }

        delete_post_meta($postId, self::META_DOCUMENT_NAME);
        $this->setStatus($postId, $statusAfterDelete, '');
    }

    public static function clearAllIndexedState(): void {
        delete_post_meta_by_key(self::META_DOCUMENT_NAME);
        delete_post_meta_by_key(self::META_STATUS);
        delete_post_meta_by_key(self::META_LAST_INDEXED);
        delete_post_meta_by_key(self::META_LAST_ERROR);
    }

    /**
     * @param array<int,string> $remoteDocumentNames
     * @return int
     */
    public static function reconcileIndexedPostsWithRemoteDocuments(array $remoteDocumentNames): int {
        $remoteLookup = [];
        foreach ($remoteDocumentNames as $name) {
            if (is_string($name) && trim($name) !== '') {
                $remoteLookup[trim($name)] = true;
            }
        }

        $postIds = get_posts([
            'post_type' => 'any',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => self::META_DOCUMENT_NAME,
                'compare' => 'EXISTS',
            ]],
        ]);

        if (!is_array($postIds) || empty($postIds)) {
            return 0;
        }

        $corrected = 0;
        foreach ($postIds as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0) {
                continue;
            }

            $documentName = trim((string) get_post_meta($postId, self::META_DOCUMENT_NAME, true));
            if ($documentName !== '' && isset($remoteLookup[$documentName])) {
                continue;
            }

            delete_post_meta($postId, self::META_DOCUMENT_NAME);
            update_post_meta($postId, self::META_STATUS, 'not_indexed');
            delete_post_meta($postId, self::META_LAST_ERROR);
            $corrected++;
        }

        return $corrected;
    }

    public function renderStatusFilter(): void {
        global $typenow;

        if (!$this->isManagedPostType((string) $typenow)) {
            return;
        }

        $selected = sanitize_key(wp_unslash($_GET['geweb_ai_index_status'] ?? ''));
        ?>
        <select name="geweb_ai_index_status">
            <option value="">All AI statuses</option>
            <option value="indexed" <?php selected($selected, 'indexed'); ?>>Indexed</option>
            <option value="pending" <?php selected($selected, 'pending'); ?>>Queued</option>
            <option value="not_indexed" <?php selected($selected, 'not_indexed'); ?>>Not indexed</option>
            <option value="error" <?php selected($selected, 'error'); ?>>Index error</option>
            <option value="excluded" <?php selected($selected, 'excluded'); ?>>Excluded</option>
        </select>
        <?php
    }

    public function applyStatusFilter(\WP_Query $query): void {
        $postType = $query->get('post_type');
        $canFilter = is_admin() && $query->is_main_query() && !is_array($postType) && $this->isManagedPostType((string) $postType);
        $status = sanitize_key(wp_unslash($_GET['geweb_ai_index_status'] ?? ''));

        if ($canFilter && $status !== '') {
            $query->set('meta_query', $this->buildStatusFilterMetaQuery($status));
        }
    }

    public function processQueuedPost(int $postId, string $operation): void {
        if ($operation === 'delete') {
            $status = $this->isExcluded($postId) ? 'excluded' : 'not_indexed';
            $this->deleteDocumentForPost($postId, $status);
            return;
        }

        $post = get_post($postId);
        if ($post instanceof \WP_Post) {
            if ($this->isExcluded($postId)) {
                $this->deleteDocumentForPost($postId, 'excluded');
            } elseif ($post->post_status !== 'publish') {
                $this->deleteDocumentForPost($postId, 'not_indexed');
            } else {
                $this->setStatus($postId, 'uploading');
                $this->indexPost($postId, false);
            }
        }
    }

    private function getColumnHtml(int $postId): string {
        $isExcluded = $this->isExcluded($postId);
        $hideIndexControls = $this->shouldHideIndexControls($postId, $isExcluded);
        $statusData = $hideIndexControls ? ['label' => 'Excluded by plugin', 'color' => self::COLOR_MUTED, 'last_indexed' => '', 'error' => ''] : $this->getStatusData($postId);
        $excludeToggleId = 'geweb-ai-toggle-exclude-' . $postId;
        $html = '<div class="geweb-ai-index-cell" data-post-id="' . esc_attr((string) $postId) . '">';
        $html .= '<p style="margin:0; color:' . esc_attr($statusData['color']) . ';">' . esc_html($statusData['label']) . '</p>';

        if (!empty($statusData['last_indexed'])) {
            $html .= '<p style="margin:4px 0 0;"><small>Last indexed: ' . esc_html($statusData['last_indexed']) . '</small></p>';
        }

        if (!empty($statusData['error'])) {
            $html .= '<p style="margin:4px 0 0; color:' . self::COLOR_ERROR . ';"><small>' . esc_html($statusData['error']) . '</small></p>';
        }

        $html .= '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>';
        if (!$hideIndexControls) {
            $html .= '<p style="margin:8px 0 0;">';
            $html .= '<button type="button" class="button button-small geweb-ai-reupload">Upload</button> ';
            $html .= '<label for="' . esc_attr($excludeToggleId) . '" style="margin-left:8px;">';
            $html .= '<input type="checkbox" id="' . esc_attr($excludeToggleId) . '" name="' . esc_attr($excludeToggleId) . '" class="geweb-ai-toggle-exclude" ' . checked($isExcluded, true, false) . disabled($isExcluded, true, false) . '> Exclude';
            $html .= '</label>';
            $html .= '</p>';
        }

        $html .= '</div>';
        return $html;
    }

    private function shouldHideIndexControls(int $postId, bool $isExcluded): bool {
        $hideControls = false;

        if ($isExcluded) {
            if ($postId === (int) get_option(self::OPTION_FRONTEND_AI_PAGE_ID, 0)) {
                $hideControls = true;
            } else {
                $post = get_post($postId);
                if ($post instanceof \WP_Post && $post->post_type === 'page') {
                    $content = (string) $post->post_content;
                    $hideControls = $content !== '' && (has_shortcode($content, 'geweb_ai_search') || (bool) preg_match('/\[[^\]]+\]/', $content));
                }
            }
        }

        return $hideControls;
    }

    private function getColumnAllowedHtml(): array {
        return [
            'div' => ['class' => true, 'data-post-id' => true, 'style' => true],
            'p' => ['class' => true, 'style' => true],
            'small' => ['style' => true],
            'button' => ['type' => true, 'class' => true],
            'label' => ['style' => true],
            'input' => ['type' => true, 'class' => true, 'checked' => true, 'disabled' => true],
        ];
    }

    /**
     * @param string $status
     * @return array<int|string,mixed>
     */
    private function buildStatusFilterMetaQuery(string $status): array {
        $metaQuery = [[
            'key' => self::META_STATUS,
            'value' => $status,
            'compare' => '=',
        ]];

        if ($status === 'excluded') {
            $metaQuery = [[
                'key' => self::META_EXCLUDE,
                'value' => '1',
                'compare' => '=',
            ]];
        } elseif ($status === 'indexed') {
            $metaQuery = [[
                'key' => self::META_DOCUMENT_NAME,
                'compare' => 'EXISTS',
            ]];
        } elseif ($status === 'not_indexed') {
            $metaQuery = [
                'relation' => 'AND',
                ['relation' => 'OR', ['key' => self::META_DOCUMENT_NAME, 'compare' => self::META_COMPARE_NOT_EXISTS], ['key' => self::META_DOCUMENT_NAME, 'value' => '', 'compare' => '=']],
                ['relation' => 'OR', ['key' => self::META_STATUS, 'compare' => self::META_COMPARE_NOT_EXISTS], ['key' => self::META_STATUS, 'value' => 'not_indexed', 'compare' => '=']],
                ['relation' => 'OR', ['key' => self::META_EXCLUDE, 'compare' => self::META_COMPARE_NOT_EXISTS], ['key' => self::META_EXCLUDE, 'value' => '1', 'compare' => '!=']],
            ];
        }

        return $metaQuery;
    }

    private function saveExcludeSetting(int $postId): void {
        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION) ||
            !current_user_can('edit_post', $postId)
        ) {
            return;
        }

        if (!empty($_POST['geweb_aisearch_exclude'])) {
            $this->setStatus($postId, 'removing');
            update_post_meta($postId, self::META_EXCLUDE, '1');
            $this->excludePost($postId);
            return;
        }

        delete_post_meta($postId, self::META_EXCLUDE);
        if (get_post_meta($postId, self::META_STATUS, true) === 'excluded') {
            $this->setStatus($postId, 'not_indexed', '');
        }
    }

    private function isExcluded(int $postId): bool {
        return get_post_meta($postId, self::META_EXCLUDE, true) === '1';
    }

    private function setStatus(int $postId, string $status, string $errorMessage = ''): void {
        update_post_meta($postId, self::META_STATUS, $status);

        if ($status === 'indexed') {
            update_post_meta($postId, self::META_LAST_INDEXED, (string) current_time('timestamp'));
            delete_post_meta($postId, self::META_LAST_ERROR);
            return;
        }

        if ($errorMessage !== '') {
            update_post_meta($postId, self::META_LAST_ERROR, $errorMessage);
        } elseif ($status !== 'error') {
            delete_post_meta($postId, self::META_LAST_ERROR);
        }
    }

    /**
     * @param int $postId
     * @param bool $excludeOnFailure
     * @return array<string,mixed>
     */
    private function indexPost(int $postId, bool $excludeOnFailure): array {
        $post = get_post($postId);
        $result = $this->validateIndexablePost($postId, $post);

        if ($result === null) {
            if ($this->isExcluded($postId)) {
                delete_post_meta($postId, self::META_EXCLUDE);
            }

            $markdown = (new HTML2MD())->convert($postId);
            if (!$markdown) {
                $result = $this->buildIndexFailureResult($postId, 'Could not convert post content for indexing.', $excludeOnFailure);
            } else {
                try {
                    $this->uploadPostMarkdown($postId, $markdown);
                    $result = ['success' => true, 'message' => 'Indexed successfully.'];
                } catch (\Exception $e) {
                    $result = $this->buildIndexFailureResult($postId, $e->getMessage(), $excludeOnFailure);
                }
            }
        }

        return $result;
    }

    /**
     * @param int $postId
     * @param mixed $post
     * @return array<string,mixed>|null
     */
    private function validateIndexablePost(int $postId, $post): ?array {
        $result = null;

        if (!$post instanceof \WP_Post) {
            $result = ['success' => false, 'message' => 'Post not found.'];
        } elseif ($this->isFrontendAiPage($postId)) {
            update_post_meta($postId, self::META_EXCLUDE, '1');
            $this->deleteDocumentForPost($postId, 'excluded');
            $result = ['success' => false, 'message' => 'The AI search page is excluded from AI indexing.'];
        } elseif ($post->post_status !== 'publish') {
            $this->deleteDocumentForPost($postId, 'not_indexed');
            $result = ['success' => false, 'message' => 'Only published content can be indexed.'];
        }

        return $result;
    }

    private function uploadPostMarkdown(int $postId, string $markdown): void {
        $this->deleteDocumentForPost($postId);
        $documentName = ProviderFactory::make()->uploadDocument($markdown, $postId);
        update_post_meta($postId, self::META_DOCUMENT_NAME, $documentName);
        if ($this->shouldUploadReferencedDocuments()) {
            $this->indexReferencedAttachments($postId);
        } else {
            (new DocumentStore())->disassociatePost($postId);
        }
        $this->setStatus($postId, 'indexed');
    }

    /**
     * @param int $postId
     * @param string $message
     * @param bool $excludeOnFailure
     * @return array<string,mixed>
     */
    private function buildIndexFailureResult(int $postId, string $message, bool $excludeOnFailure): array {
        if ($excludeOnFailure) {
            $this->excludeAfterFailure($postId, $message);
        } else {
            $this->setStatus($postId, 'error', $message);
        }

        return ['success' => false, 'message' => $message];
    }

    private function excludeAfterFailure(int $postId, string $message): void {
        update_post_meta($postId, self::META_EXCLUDE, '1');
        $this->excludePost($postId);
        update_post_meta($postId, self::META_LAST_ERROR, $message);
    }

    private function excludePost(int $postId): void {
        $documentName = (string) get_post_meta($postId, self::META_DOCUMENT_NAME, true);
        if ($documentName === '') {
            (new DocumentStore())->disassociatePost($postId);
            $this->setStatus($postId, 'excluded', '');
            return;
        }

        $this->deleteDocumentForPost($postId, 'excluded');
    }

    private function indexReferencedAttachments(int $postId): void {
        $filePaths = $this->getReferencedAttachmentPaths($postId);
        $documentStore = new DocumentStore();
        $documentIds = [];

        foreach ($filePaths as $filePath) {
            $documentId = $documentStore->getOrCreateDocument($filePath, $postId);
            if ($documentId !== null) {
                $documentIds[] = $documentId;
            }
        }

        $documentStore->updatePostAssociations($postId, $documentIds);
    }

    private function shouldUploadReferencedDocuments(): bool {
        return get_option(self::OPTION_INCLUDE_REFERENCED_DOCUMENTS, '0') === '1';
    }

    /**
     * @param int $postId
     * @return array<int,string>
     */
    private function getReferencedAttachmentPaths(int $postId): array {
        $filePaths = [];
        foreach (ReferencedAttachmentResolver::getReferencedAttachmentEntriesForPost($postId) as $reference) {
            $filePath = isset($reference['file_path']) ? (string) $reference['file_path'] : '';
            if ($filePath !== '') {
                $filePaths[$filePath] = $filePath;
            }
        }

        return array_values($filePaths);
    }

    /**
     * @param int $postId
     * @return array<string,string>
     */
    private function getStatusData(int $postId): array {
        $status = (string) get_post_meta($postId, self::META_STATUS, true);
        $documentName = (string) get_post_meta($postId, self::META_DOCUMENT_NAME, true);
        $excluded = $this->isExcluded($postId);
        $error = (string) get_post_meta($postId, self::META_LAST_ERROR, true);

        if ($status === '') {
            $status = $documentName !== '' ? 'indexed' : 'not_indexed';
        }

        $resolved = $this->resolvePostStatusPresentation($status, $excluded, $error);
        $lastIndexed = (int) get_post_meta($postId, self::META_LAST_INDEXED, true);
        $resolved['last_indexed'] = $lastIndexed > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $lastIndexed) : '';
        $resolved['error'] = $error;

        return $resolved;
    }

    /**
     * @param string $status
     * @param bool $excluded
     * @param string $error
     * @return array<string,string>
     */
    private function resolvePostStatusPresentation(string $status, bool $excluded, string $error): array {
        $map = [
            'indexed' => ['label' => 'Indexed', 'color' => self::COLOR_SUCCESS],
            'pending' => ['label' => 'Queued', 'color' => self::COLOR_INFO],
            'uploading' => ['label' => 'Uploading', 'color' => self::COLOR_INFO],
            'removing' => ['label' => 'Removing', 'color' => self::COLOR_WARNING],
            'not_indexed' => ['label' => 'Not indexed', 'color' => self::COLOR_MUTED],
            'error' => ['label' => 'Index error', 'color' => self::COLOR_ERROR],
            'excluded' => ['label' => 'Excluded', 'color' => self::COLOR_WARNING],
        ];
        $resolved = $map[$status] ?? $map['not_indexed'];

        if ($excluded) {
            if ($status === 'removing') {
                $resolved = ['label' => 'Removing, excluded', 'color' => self::COLOR_WARNING];
            } elseif ($error !== '') {
                $resolved = ['label' => 'Excluded, index error', 'color' => self::COLOR_ERROR];
            } else {
                $resolved = ['label' => 'Excluded', 'color' => self::COLOR_WARNING];
            }
        }

        return $resolved;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getIndexableMetaQuery(): array {
        return [
            'relation' => 'OR',
            ['key' => self::META_EXCLUDE, 'compare' => self::META_COMPARE_NOT_EXISTS],
            ['key' => self::META_EXCLUDE, 'value' => '1', 'compare' => '!='],
        ];
    }

    /**
     * @return array<int,int>
     */
    private function getNonIndexablePostIds(): array {
        $pageId = (int) get_option(self::OPTION_FRONTEND_AI_PAGE_ID, 0);
        return $pageId > 0 ? [$pageId] : [];
    }

    private function isFrontendAiPage(int $postId): bool {
        return $postId > 0 && $postId === (int) get_option(self::OPTION_FRONTEND_AI_PAGE_ID, 0);
    }

    private function isManagedPostType(string $postType): bool {
        return $postType !== '' && in_array($postType, get_option('geweb_aisearch_post_types', []), true);
    }

    private function schedulePostProcessing(int $postId, string $operation): void {
        wp_schedule_single_event(time() + 1, self::CRON_HOOK_PROCESS, [$postId, $operation]);
    }

    private function clearScheduledProcessing(int $postId): void {
        foreach (['index', 'delete'] as $operation) {
            $timestamp = wp_next_scheduled(self::CRON_HOOK_PROCESS, [$postId, $operation]);
            while ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK_PROCESS, [$postId, $operation]);
                $timestamp = wp_next_scheduled(self::CRON_HOOK_PROCESS, [$postId, $operation]);
            }
        }
    }
}
