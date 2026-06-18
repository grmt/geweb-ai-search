<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Handles post indexing workflows and admin UI integration.
 */
class PostIndexManager { // NOSONAR - This is the main orchestration point for admin UI, cron, and indexing flows.
    private const OPTION_INCLUDE_REFERENCED_DOCUMENTS = 'geweb_aisearch_include_referenced_documents';
    private const OPTION_FRONTEND_AI_PAGE_ID = 'geweb_aisearch_frontend_ai_page_id';
    private const MESSAGE_INVALID_POST_ID = 'Invalid post ID';
    private const MESSAGE_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    private const META_COMPARE_NOT_EXISTS = 'NOT EXISTS';
    private const COLOR_WARNING = '#996800';
    private const COLOR_SUCCESS = '#46b450';
    private const COLOR_INFO = '#2271b1';
    private const COLOR_MUTED = '#646970';
    private const COLOR_ERROR = '#d63638';
    private const HTML_SMALL_OPEN = ';\"><small>';
    private const META_DOCUMENT_NAME = 'geweb_aisearch_document_name';
    private const META_EXCLUDE = 'geweb_aisearch_exclude';
    private const META_STATUS = 'geweb_aisearch_status';
    private const META_LAST_INDEXED = 'geweb_aisearch_last_indexed';
    private const META_LAST_ERROR = 'geweb_aisearch_last_error';
    private const META_LAST_ERROR_AT = 'geweb_aisearch_last_error_at';
    private const META_STATUS_TIMESTAMP_BASIS = 'geweb_aisearch_status_timestamp_basis';
    private const META_MARKDOWN_BYTES = 'geweb_aisearch_markdown_bytes';
    private const META_LINKED_DOCUMENT_FAILURES = 'geweb_aisearch_linked_document_failures';
    private const NONCE_ACTION = 'geweb_aisearch_post_settings';
    private const NONCE_NAME = 'geweb_aisearch_post_settings_nonce';
    private const CRON_HOOK_PROCESS = 'geweb_aisearch_process_post';
    private const STATUS_TIMESTAMP_BASIS_UTC = 'utc';

    public function __construct() {
        add_action('init', [$this, 'registerPostColumns']);
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('before_delete_post', [$this, 'deleteDocumentForPost']);
        add_action('pre_get_posts', [$this, 'applyColumnSorting']);
        add_action('wp_ajax_geweb_generate_library', [$this, 'ajaxGenerateLibrary']);
        add_action('wp_ajax_geweb_build_markdown_cache', [$this, 'ajaxBuildMarkdownCache']);
        add_action('wp_ajax_geweb_reupload_post', [$this, 'ajaxReuploadPost']);
        add_action('wp_ajax_geweb_get_post_index_status', [$this, 'ajaxGetPostIndexStatus']);
        add_action('wp_ajax_geweb_toggle_exclude', [$this, 'ajaxToggleExclude']);
        add_action('wp_ajax_geweb_set_attachment_image_processing_mode', [$this, 'ajaxSetAttachmentImageProcessingMode']);
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('restrict_manage_posts', [$this, 'renderStatusFilter']);
        add_action('pre_get_posts', [$this, 'applyStatusFilter']);
        add_filter('hidden_columns', [$this, 'ensureAdminColumnsVisible'], 10, 2);
        add_action(self::CRON_HOOK_PROCESS, [$this, 'processQueuedPost'], 10, 3);
    }

    public function registerPostColumns(): void {
        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (!empty($postTypes)) {
            foreach ($postTypes as $postType) {
                if ($postType === 'attachment') {
                    add_filter('manage_upload_columns', [$this, 'addAdminColumns']);
                    add_filter('manage_upload_sortable_columns', [$this, 'addSortableAdminColumns']);
                    add_action('manage_media_custom_column', [$this, 'renderIndexedColumn'], 10, 2);
                    add_action('manage_media_custom_column', [$this, 'renderDuplicatesColumn'], 10, 2);
                    add_action('manage_media_custom_column', [$this, 'renderMarkdownCacheColumn'], 10, 2);
                    add_action('manage_media_custom_column', [$this, 'renderIdColumn'], 10, 2);
                    continue;
                }
                add_filter("manage_{$postType}_posts_columns", [$this, 'addAdminColumns']);
                add_filter("manage_edit-{$postType}_sortable_columns", [$this, 'addSortableAdminColumns']);
                add_action("manage_{$postType}_posts_custom_column", [$this, 'renderIndexedColumn'], 10, 2);
                add_action("manage_{$postType}_posts_custom_column", [$this, 'renderMarkdownCacheColumn'], 10, 2);
                add_action("manage_{$postType}_posts_custom_column", [$this, 'renderIdColumn'], 10, 2);
            }
        }
    }

    public function addAdminColumns(array $columns): array {
        global $typenow;

        $updated = [];
        $isAttachmentScreen = (string) $typenow === 'attachment';

        foreach ($columns as $key => $label) {
            $updated[$key] = $label;

            if ($key === 'title') {
                $updated['geweb_post_id'] = 'ID';
                if ($isAttachmentScreen) {
                    $updated['geweb_ai_indexed'] = 'AI Indexed';
                    $updated['geweb_ai_duplicates'] = 'Duplicates';
                    $updated['geweb_ai_markdown_cache'] = 'MD Cache';
                }
                continue;
            }

            if ($key === 'date') {
                $updated['geweb_last_modified'] = 'Last Modified';
                if (!$isAttachmentScreen) {
                    $updated['geweb_ai_indexed'] = 'AI Indexed';
                    $updated['geweb_ai_markdown_cache'] = 'MD Cache';
                }
            }
        }

        if (!isset($updated['geweb_post_id'])) {
            $updated['geweb_post_id'] = 'ID';
        }
        if (!isset($updated['geweb_ai_indexed'])) {
            $updated['geweb_ai_indexed'] = 'AI Indexed';
        }
        if ($isAttachmentScreen && !isset($updated['geweb_ai_duplicates'])) {
            $updated['geweb_ai_duplicates'] = 'Duplicates';
        }
        if (!isset($updated['geweb_ai_markdown_cache'])) {
            $updated['geweb_ai_markdown_cache'] = 'MD Cache';
        }
        if (isset($columns['date']) && !isset($updated['geweb_last_modified'])) {
            $updated['geweb_last_modified'] = 'Last Modified';
        }

        return $updated;
    }

    public function renderIdColumn(string $column, int $postId): void {
        if ($column === 'geweb_post_id') {
            echo esc_html((string) $postId);
            return;
        }

        if ($column === 'geweb_last_modified') {
            $modified = get_post_modified_time(get_option('date_format') . ' ' . get_option('time_format'), false, $postId, true);
            echo $modified !== '' ? esc_html($modified) : '—';
        }
    }

    public function renderIndexedColumn(string $column, int $postId): void {
        if ($column === 'geweb_ai_indexed') {
            echo wp_kses($this->getColumnHtml($postId), $this->getColumnAllowedHtml());
        }
    }

    public function renderDuplicatesColumn(string $column, int $postId): void {
        if ($column !== 'geweb_ai_duplicates') {
            return;
        }

        if (get_post_type($postId) !== 'attachment') {
            echo '—';
            return;
        }

        echo wp_kses((new FileDuplicateHashIndex())->getAttachmentDuplicateColumnHtml($postId), [
            'strong' => ['style' => true],
            'div' => [],
            'small' => ['style' => true],
            'br' => [],
            'a' => ['href' => true],
            'span' => ['style' => true],
        ]);
    }

    public function addSortableAdminColumns(array $columns): array {
        global $typenow;

        $columns['geweb_post_id'] = 'ID';
        $columns['geweb_ai_indexed'] = 'geweb_ai_indexed';
        if ((string) $typenow === 'attachment') {
            $columns['geweb_ai_duplicates'] = 'geweb_ai_duplicates';
        }
        $columns['geweb_ai_markdown_cache'] = 'geweb_ai_markdown_cache';
        $columns['geweb_last_modified'] = 'modified';
        return $columns;
    }

    public function ensureAdminColumnsVisible(array $hidden, $screen): array {
        $postType = $this->getAdminColumnsPostType($screen);
        if (!$this->isManagedPostType($postType)) {
            return $hidden;
        }

        $requiredColumns = [
            'author',
            'date',
            'geweb_post_id',
            'geweb_ai_indexed',
            'geweb_ai_markdown_cache',
            'geweb_last_modified',
        ];

        if ($postType === 'attachment') {
            $requiredColumns = [
                'geweb_post_id',
                'geweb_ai_indexed',
                'geweb_ai_duplicates',
                'geweb_ai_markdown_cache',
            ];
        }

        return array_values(array_diff($hidden, $requiredColumns));
    }

    private function getAdminColumnsPostType($screen): string {
        if (is_object($screen) && isset($screen->post_type)) {
            return (string) $screen->post_type;
        }

        return isset($_GET['post_type']) ? sanitize_key((string) wp_unslash($_GET['post_type'])) : 'post';
    }

    public function renderMarkdownCacheColumn(string $column, int $postId): void {
        if ($column !== 'geweb_ai_markdown_cache') {
            return;
        }

        echo wp_kses($this->getMarkdownCacheColumnHtml($postId), [
            'a' => [
                'href' => true,
                'class' => true,
                'data-post-id' => true,
                'title' => true,
                'style' => true,
            ],
            'span' => ['style' => true],
        ]);
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
        $imageUsage = $this->getImageUsageData($post->ID);
        $editUrl = get_edit_post_link($post->ID, 'raw');
        $previewUrl = get_permalink($post->ID);
        ?>
        <p>
            <label>
                <input type="checkbox" name="geweb_aisearch_exclude" value="1" <?php checked($this->isExcluded($post->ID)); ?>>
                Exclude this content from AI indexing
            </label>
        </p>
        <p><strong>Status:</strong> <?php echo esc_html($statusData['label']); ?></p>
        <?php $markdownBytes = $this->getMarkdownCacheBytes($post->ID); ?>
        <p><strong>MD cache:</strong> <?php echo esc_html($markdownBytes > 0 ? size_format($markdownBytes, 1) : 'Missing'); ?></p>
        <?php if (($imageUsage['embedded_bitmap_count'] ?? 0) > 0): ?>
            <p style="color: <?php echo esc_attr(self::COLOR_WARNING); ?>;">
                <strong>Content warning:</strong>
                This page contains <?php echo esc_html((string) $imageUsage['embedded_bitmap_count']); ?> embedded bitmap image(s) in the page content.
                <?php if (is_string($editUrl) && $editUrl !== ''): ?>
                    <a href="<?php echo esc_url($editUrl); ?>" target="_blank" rel="noopener noreferrer">Open editor</a>
                <?php endif; ?>
                <?php if (is_string($previewUrl) && $previewUrl !== ''): ?>
                    | <a href="<?php echo esc_url($previewUrl); ?>" target="_blank" rel="noopener noreferrer">Open page</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (($imageUsage['uploads_image_count'] ?? 0) > 0): ?>
            <p style="color: <?php echo esc_attr(self::COLOR_MUTED); ?>;">
                <strong>Info:</strong>
                This page references <?php echo esc_html((string) $imageUsage['uploads_image_count']); ?> image(s) from the WordPress uploads library.
            </p>
        <?php endif; ?>
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
                <button type="button" id="geweb-build-markdown-cache" class="button" style="margin-left:8px;">Build MD Cache</button>
                <p class="description">Process all published posts and upload them to Gemini for AI search.</p>
                <p class="description">Build MD cache for already indexed published posts/pages without reuploading documents.</p>
                <div id="geweb-generate-status"></div>
                <div id="geweb-build-markdown-cache-status"></div>
            </td>
        </tr>
        <?php
    }

    public function ajaxBuildMarkdownCache(): void {
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
        $queryArgs = [
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => self::META_DOCUMENT_NAME,
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => self::META_DOCUMENT_NAME,
                    'value' => '',
                    'compare' => '!=',
                ],
                [
                    'relation' => 'OR',
                    ['key' => self::META_EXCLUDE, 'compare' => self::META_COMPARE_NOT_EXISTS],
                    ['key' => self::META_EXCLUDE, 'value' => '1', 'compare' => '!='],
                ],
            ],
            'post__not_in' => $this->getNonIndexablePostIds(),
        ];

        $totalQuery = new \WP_Query(array_merge($queryArgs, [
            'posts_per_page' => -1,
            'paged' => 1,
            'no_found_rows' => false,
        ]));
        $total = (int) $totalQuery->found_posts;
        $posts = get_posts($queryArgs);

        $success = 0;
        $errors = 0;

        foreach ($posts as $postId) {
            $postId = (int) $postId;
            if ($postId <= 0) {
                continue;
            }

            try {
                $markdown = (new HTML2MD())->convert($postId);
                if (!$markdown) {
                    $errors++;
                    continue;
                }

                (new MarkdownCacheStore())->saveMarkdown($postId, $markdown);
                update_post_meta($postId, self::META_MARKDOWN_BYTES, (string) strlen($markdown));
                $success++;
            } catch (\Exception $e) {
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

    public function ajaxReuploadPost(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($postId <= 0) {
            wp_send_json_error(['message' => self::MESSAGE_INVALID_POST_ID]);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS]);
        }

        if ($this->isExcluded($postId)) {
            delete_post_meta($postId, self::META_EXCLUDE);
        }

        $this->clearScheduledProcessing($postId);
        $this->setStatus($postId, 'uploading');
        $this->schedulePostProcessing($postId, 'index');
        wp_send_json_success([
            'message' => 'Upload queued.',
            'html' => $this->getColumnHtml($postId),
            'markdown_cache_html' => $this->getMarkdownCacheColumnHtml($postId),
        ]);
    }

    public function ajaxGetPostIndexStatus(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($postId <= 0) {
            wp_send_json_error(['message' => self::MESSAGE_INVALID_POST_ID]);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS]);
        }

        $status = (string) get_post_meta($postId, self::META_STATUS, true);
        wp_send_json_success([
            'status' => $status,
            'done' => !in_array($status, ['uploading', 'removing', 'pending'], true),
            'html' => $this->getColumnHtml($postId),
            'markdown_cache_html' => $this->getMarkdownCacheColumnHtml($postId),
        ]);
    }

    public function ajaxToggleExclude(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $exclude = !empty($_POST['exclude']);

        if ($postId <= 0) {
            wp_send_json_error(['message' => self::MESSAGE_INVALID_POST_ID]);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS]);
        }

        if ($exclude) {
            $this->setStatus($postId, 'removing');
            update_post_meta($postId, self::META_EXCLUDE, '1');
            $this->excludePost($postId);
            wp_send_json_success([
                'message' => 'Excluded from AI indexing.',
                'html' => $this->getColumnHtml($postId),
                'markdown_cache_html' => $this->getMarkdownCacheColumnHtml($postId),
            ]);
        }

        delete_post_meta($postId, self::META_EXCLUDE);
        if (in_array(get_post_meta($postId, self::META_STATUS, true), ['excluded', 'error', 'removing'], true)) {
            $this->setStatus($postId, 'not_indexed', '');
        }

        wp_send_json_success([
            'message' => 'Included for AI indexing again.',
            'html' => $this->getColumnHtml($postId),
            'markdown_cache_html' => $this->getMarkdownCacheColumnHtml($postId),
        ]);
    }

    public function ajaxSetAttachmentImageProcessingMode(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $mode = isset($_POST['mode']) ? sanitize_key((string) wp_unslash($_POST['mode'])) : ImageOcrService::MODE_NONE;

        if ($postId <= 0) {
            wp_send_json_error(['message' => self::MESSAGE_INVALID_POST_ID]);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => self::MESSAGE_INSUFFICIENT_PERMISSIONS]);
        }

        $imageOcrService = new ImageOcrService();
        if (!$imageOcrService->isOcrEligibleAttachment($postId)) {
            wp_send_json_error(['message' => 'This attachment is not an image.']);
        }

        $imageOcrService->setAttachmentImageProcessingMode($postId, $mode);
        $cacheStore = new MarkdownCacheStore();
        $cacheWarning = '';

        if ($mode === ImageOcrService::MODE_NONE) {
            $cacheStore->deleteMarkdown($postId);
            delete_post_meta($postId, self::META_MARKDOWN_BYTES);
        } else {
            try {
                $markdown = $imageOcrService->buildAttachmentMarkdown($postId);
                if (is_string($markdown) && trim($markdown) !== '') {
                    $cacheStore->saveMarkdown($postId, $markdown);
                    update_post_meta($postId, self::META_MARKDOWN_BYTES, (string) strlen($markdown));
                } else {
                    $cacheStore->deleteMarkdown($postId);
                    delete_post_meta($postId, self::META_MARKDOWN_BYTES);
                    $cacheWarning = 'Markdown cache could not be generated for this image yet.';
                }
            } catch (\Exception $e) {
                $cacheStore->deleteMarkdown($postId);
                delete_post_meta($postId, self::META_MARKDOWN_BYTES);
                $cacheWarning = sanitize_text_field($e->getMessage());
            }
        }

        $messages = [
            ImageOcrService::MODE_NONE => 'Image processing disabled for this image.',
            ImageOcrService::MODE_OCR => 'OCR enabled for this image.',
            ImageOcrService::MODE_DESCRIBE => 'Description mode enabled for this image.',
        ];
        $message = $messages[$mode] ?? $messages[ImageOcrService::MODE_NONE];
        if ($cacheWarning !== '') {
            $message .= ' ' . $cacheWarning;
        }

        wp_send_json_success([
            'message' => $message,
            'html' => $this->getColumnHtml($postId),
            'markdown_cache_html' => $this->getMarkdownCacheColumnHtml($postId),
        ]);
    }

    public function deleteDocumentForPost(int $postId, string $statusAfterDelete = 'not_indexed', bool $deleteMarkdownCache = true): void {
        $documentName = get_post_meta($postId, self::META_DOCUMENT_NAME, true);
        (new DocumentStore())->disassociatePost($postId);
        if ($deleteMarkdownCache) {
            (new MarkdownCacheStore())->deleteMarkdown($postId);
            delete_post_meta($postId, self::META_MARKDOWN_BYTES);
        }
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
        delete_post_meta_by_key(self::META_LAST_ERROR_AT);
        delete_post_meta_by_key(self::META_MARKDOWN_BYTES);
        MarkdownCacheStore::deleteAllMarkdown();
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
            delete_post_meta($postId, self::META_LAST_ERROR_AT);
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
        $selectedDuplicates = sanitize_key(wp_unslash($_GET['geweb_ai_duplicates_filter'] ?? ''));
        ?>
        <select name="geweb_ai_index_status">
            <option value="">All AI statuses</option>
            <option value="indexed" <?php selected($selected, 'indexed'); ?>>Indexed</option>
            <option value="out_of_sync" <?php selected($selected, 'out_of_sync'); ?>>Out of sync</option>
            <option value="pending" <?php selected($selected, 'pending'); ?>>Queued</option>
            <option value="not_indexed" <?php selected($selected, 'not_indexed'); ?>>Not indexed</option>
            <option value="error" <?php selected($selected, 'error'); ?>>Index error</option>
            <option value="excluded" <?php selected($selected, 'excluded'); ?>>Excluded</option>
        </select>
        <?php if ((string) $typenow === 'attachment') : ?>
            <select name="geweb_ai_duplicates_filter">
                <option value="">All duplicate states</option>
                <option value="duplicates" <?php selected($selectedDuplicates, 'duplicates'); ?>>Duplicates only</option>
                <option value="unique" <?php selected($selectedDuplicates, 'unique'); ?>>Unique only</option>
            </select>
        <?php endif; ?>
        <?php
    }

    public function applyStatusFilter(\WP_Query $query): void {
        $postType = $query->get('post_type');
        $canFilter = is_admin() && $query->is_main_query() && !is_array($postType) && $this->isManagedPostType((string) $postType);
        $status = sanitize_key(wp_unslash($_GET['geweb_ai_index_status'] ?? ''));
        $duplicateFilter = sanitize_key(wp_unslash($_GET['geweb_ai_duplicates_filter'] ?? ''));

        if ($canFilter && $status !== '') {
            if ($status === 'out_of_sync') {
                $query->set('post__in', $this->getOutOfSyncPostIds((string) $postType));
            } else {
                $query->set('meta_query', $this->buildStatusFilterMetaQuery($status));
            }
        }

        if ($canFilter && (string) $postType === 'attachment' && $duplicateFilter !== '') {
            $duplicateIds = array_keys((new FileDuplicateHashIndex())->getAttachmentDuplicateCounts());
            if ($duplicateFilter === 'duplicates') {
                $query->set('post__in', empty($duplicateIds) ? [0] : array_map('intval', $duplicateIds));
            } elseif ($duplicateFilter === 'unique') {
                $query->set('post__not_in', array_map('intval', $duplicateIds));
            }
        }
    }

    public function processQueuedPost(int $postId, string $operation, string $scopeKey = ''): void {
        UserScope::withGroupScopeOverride($scopeKey, function () use ($postId, $operation): void {
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
        });
    }

    private function getColumnHtml(int $postId): string {
        $isExcluded = $this->isExcluded($postId);
        $hideIndexControls = $this->shouldHideIndexControls($postId, $isExcluded);
        $statusData = $hideIndexControls ? ['label' => 'Excluded by plugin', 'color' => self::COLOR_MUTED, 'last_indexed' => '', 'error' => ''] : $this->getStatusData($postId);
        $imageUsage = $this->getImageUsageData($postId);
        $imageOcrService = new ImageOcrService();
        $isOcrEligibleAttachment = $imageOcrService->isOcrEligibleAttachment($postId);
        $imageProcessingMode = $isOcrEligibleAttachment ? $imageOcrService->getAttachmentImageProcessingMode($postId) : ImageOcrService::MODE_NONE;
        $ocrForcedGlobally = $isOcrEligibleAttachment && $imageOcrService->shouldOcrAllUploadsImages() && $imageProcessingMode === ImageOcrService::MODE_NONE;
        $excludeToggleId = 'geweb-ai-toggle-exclude-' . $postId;
        $imageModeSelectId = 'geweb-ai-image-mode-' . $postId;
        $html = '<div class="geweb-ai-index-cell" data-post-id="' . esc_attr((string) $postId) . '">';
        $html .= '<p style="margin:0; color:' . esc_attr($statusData['color']) . ';">' . esc_html($statusData['label']) . '</p>';
        $html .= $this->buildColumnStatusMetaHtml($postId, $imageUsage, $statusData);

        $html .= '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>';
        if (!$hideIndexControls) {
            $html .= $this->buildColumnControlHtml(
                $statusData,
                $isExcluded,
                $excludeToggleId,
                $isOcrEligibleAttachment,
                $imageModeSelectId,
                $imageProcessingMode,
                $ocrForcedGlobally
            );
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * @param int $postId
     * @return array<string,int>
     */
    private function getImageUsageData(int $postId): array {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return $this->getEmptyImageUsageData();
        }

        $content = (string) $post->post_content;
        if ($content === '') {
            return $this->getEmptyImageUsageData();
        }

        $embeddedBitmapCount = preg_match_all('/data:image\//i', $content) ?: 0;
        $uploadsBaseUrl = (string) (wp_get_upload_dir()['baseurl'] ?? '');
        $uploadsImageCount = 0;

        if ($uploadsBaseUrl !== '') {
            $uploadsPattern = '/' . preg_quote($uploadsBaseUrl, '/') . '[^"\'\s<>)]+/i';
            $uploadsImageCount = preg_match_all($uploadsPattern, $content) ?: 0;
        }

        return [
            'embedded_bitmap_count' => (int) $embeddedBitmapCount,
            'uploads_image_count' => (int) $uploadsImageCount,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function getEmptyImageUsageData(): array {
        return [
            'embedded_bitmap_count' => 0,
            'uploads_image_count' => 0,
        ];
    }

    private function getIndexedStatusErrorHint(string $errorMessage): string {
        $normalizedMessage = strtolower(trim($errorMessage));
        $hint = '';

        if ($normalizedMessage !== '') {
            if (strpos($normalizedMessage, 'invalid mime type for upload') !== false) {
                $hint = 'The file type metadata sent for this upload was invalid.';
            } elseif (strpos($normalizedMessage, 'mime type must be in a valid type/subtype format') !== false) {
                $hint = 'Gemini rejected the file MIME type metadata for this upload.';
            } elseif (strpos($normalizedMessage, 'configuration error') !== false) {
                $hint = 'The background job could not access the configured Gemini upload settings.';
            }
        }

        return $hint;
    }

    /**
     * @param int $postId
     * @param array<string,int> $imageUsage
     * @param array<string,string> $statusData
     * @return string
     */
    private function buildColumnStatusMetaHtml(int $postId, array $imageUsage, array $statusData): string {
        $html = '';
        $postType = get_post_type($postId);

        if (($imageUsage['embedded_bitmap_count'] ?? 0) > 0) {
            $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_WARNING) . self::HTML_SMALL_OPEN . 'Contains embedded bitmap images</small></p>';
        } elseif ($postType !== 'attachment' && ($imageUsage['uploads_image_count'] ?? 0) > 0) {
            $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_MUTED) . self::HTML_SMALL_OPEN . 'Uses uploads library images</small></p>';
        }

        $linkedDocumentFailureNames = $this->getLinkedDocumentFailureNames($postId);
        if (!empty($linkedDocumentFailureNames)) {
            $firstFailure = (string) $linkedDocumentFailureNames[0];
            $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_WARNING) . self::HTML_SMALL_OPEN . 'Linked document upload failed: ' . esc_html($firstFailure);

            if (count($linkedDocumentFailureNames) > 1) {
                $remainingFailures = array_slice($linkedDocumentFailureNames, 1);
                $tooltip = implode("\n", $remainingFailures);
                $html .= ' <span title="' . esc_attr($tooltip) . '" style="text-decoration:underline dotted; cursor:help;">+' . esc_html((string) count($remainingFailures)) . '</span>';
            }

            $html .= '</small></p>';
        }

        if (!empty($statusData['last_indexed'])) {
            $html .= '<p style="margin:4px 0 0;"><small>' . esc_html($statusData['last_indexed']) . '</small></p>';
        }

        if (($statusData['is_out_of_sync'] ?? '') === '1') {
            $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_WARNING) . self::HTML_SMALL_OPEN . 'Modified after upload</small></p>';
        }

        if (!empty($statusData['last_error_at'])) {
            $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_WARNING) . self::HTML_SMALL_OPEN . esc_html($statusData['last_error_at']) . '</small></p>';
        }

        if (!empty($statusData['error'])) {
            $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_ERROR) . self::HTML_SMALL_OPEN . esc_html($statusData['error']) . '</small></p>';
            $errorHint = $this->getIndexedStatusErrorHint($statusData['error']);
            if ($errorHint !== '') {
                $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_MUTED) . self::HTML_SMALL_OPEN . esc_html($errorHint) . '</small></p>';
            }
        }

        if ($postType === 'attachment') {
            $duplicateNotice = (new FileDuplicateHashIndex())->getAttachmentDuplicateNotice($postId);
            if ($duplicateNotice !== '') {
                $html .= '<p style="margin:4px 0 0; color:' . esc_attr(self::COLOR_WARNING) . self::HTML_SMALL_OPEN . esc_html($duplicateNotice) . '</small></p>';
            }
        }

        return $html;
    }

    private function buildColumnControlHtml(
        array $statusData,
        bool $isExcluded,
        string $excludeToggleId,
        bool $isOcrEligibleAttachment,
        string $imageModeSelectId,
        string $imageProcessingMode,
        bool $ocrForcedGlobally
    ): string {
        $isBusy = in_array((string) ($statusData['label'] ?? ''), ['Queued', 'Uploading', 'Removing', 'Removing, excluded'], true);
        $html = '<p style="margin:8px 0 0;">';
        $html .= '<button type="button" class="button button-small geweb-ai-reupload"' . disabled($isBusy, true, false) . '>Upload</button> ';
        $html .= '</p>';
        $html .= '<p style="margin:6px 0 0;">';
        $html .= '<label for="' . esc_attr($excludeToggleId) . '" style="display:inline-flex;align-items:center;gap:4px;white-space:nowrap;">';
        $html .= '<input type="checkbox" id="' . esc_attr($excludeToggleId) . '" name="' . esc_attr($excludeToggleId) . '" class="geweb-ai-toggle-exclude" style="margin:0;" ' . checked($isExcluded, true, false) . disabled($isExcluded, true, false) . '> <span>Exclude</span>';
        $html .= '</label>';
        $html .= '</p>';

        if ($isOcrEligibleAttachment) {
            $html .= '<p style="margin:6px 0 0;">';
            $html .= '<label for="' . esc_attr($imageModeSelectId) . '" style="display:inline-flex;align-items:center;gap:6px;white-space:nowrap;' . ($ocrForcedGlobally ? 'opacity:0.7;' : '') . '">';
            $html .= '<span>Image</span>';
            $html .= '<select id="' . esc_attr($imageModeSelectId) . '" name="' . esc_attr($imageModeSelectId) . '" class="geweb-ai-attachment-image-mode" style="min-width:96px;"' . disabled($ocrForcedGlobally, true, false) . '>';
            $html .= '<option value="' . esc_attr(ImageOcrService::MODE_NONE) . '"' . selected($imageProcessingMode, ImageOcrService::MODE_NONE, false) . '>None</option>';
            $html .= '<option value="' . esc_attr(ImageOcrService::MODE_OCR) . '"' . selected($imageProcessingMode, ImageOcrService::MODE_OCR, false) . '>OCR</option>';
            $html .= '<option value="' . esc_attr(ImageOcrService::MODE_DESCRIBE) . '"' . selected($imageProcessingMode, ImageOcrService::MODE_DESCRIBE, false) . '>Describe</option>';
            $html .= '</select>';
            $html .= '</label>';
            if ($ocrForcedGlobally) {
                $html .= ' <small style="color:' . esc_attr(self::COLOR_MUTED) . ';">OCR enabled globally</small>';
            }
            $html .= '</p>';
        }

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
            'button' => ['type' => true, 'class' => true, 'disabled' => true],
            'a' => ['href' => true, 'class' => true, 'data-post-id' => true, 'title' => true, 'style' => true],
            'label' => ['style' => true],
            'input' => ['type' => true, 'class' => true, 'checked' => true, 'disabled' => true, 'style' => true],
            'select' => ['id' => true, 'name' => true, 'class' => true, 'style' => true, 'disabled' => true],
            'option' => ['value' => true, 'selected' => true],
            'span' => ['style' => true, 'title' => true],
        ];
    }

    public function applyColumnSorting(\WP_Query $query): void {
        $postType = $query->get('post_type');
        $canSort = is_admin() && $query->is_main_query() && !is_array($postType) && $this->isManagedPostType((string) $postType);
        if (!$canSort) {
            return;
        }

        $orderby = (string) $query->get('orderby');
        if ($this->applyMetaColumnSorting($query, $orderby)) {
            return;
        }

        if ($orderby === 'geweb_ai_duplicates' && (string) $postType === 'attachment') {
            $this->applyAttachmentDuplicateSorting($query);
            return;
        }

        if (in_array($orderby, ['ID', 'modified'], true)) {
            $query->set('orderby', $orderby);
        }
    }

    private function applyMetaColumnSorting(\WP_Query $query, string $orderby): bool {
        if ($orderby === 'geweb_ai_markdown_cache') {
            $query->set('meta_key', self::META_MARKDOWN_BYTES);
            $query->set('orderby', 'meta_value_num');
            return true;
        }

        if ($orderby === 'geweb_ai_indexed') {
            $query->set('meta_key', self::META_STATUS);
            $query->set('orderby', 'meta_value');
            return true;
        }

        return false;
    }

    private function applyAttachmentDuplicateSorting(\WP_Query $query): void {
        $counts = (new FileDuplicateHashIndex())->getAttachmentDuplicateCounts();
        $order = strtolower((string) $query->get('order')) === 'asc' ? 'asc' : 'desc';
        $attachmentIds = get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'numberposts' => -1,
            'fields' => 'ids',
        ]);

        if (!is_array($attachmentIds)) {
            return;
        }

        usort($attachmentIds, function ($left, $right) use ($counts, $order): int {
            return $this->compareAttachmentDuplicateSortValues($left, $right, $counts, $order);
        });

        $query->set('post__in', array_map('intval', $attachmentIds));
        $query->set('orderby', 'post__in');
    }

    /**
     * @param array<int,int> $counts
     */
    private function compareAttachmentDuplicateSortValues($left, $right, array $counts, string $order): int {
        $leftId = (int) $left;
        $rightId = (int) $right;
        $leftCount = (int) ($counts[$leftId] ?? 0);
        $rightCount = (int) ($counts[$rightId] ?? 0);
        $direction = $order === 'asc' ? 1 : -1;

        if ($leftCount === $rightCount) {
            return $direction * ($leftId <=> $rightId);
        }

        return $direction * ($leftCount <=> $rightCount);
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
            update_post_meta($postId, self::META_LAST_INDEXED, (string) time());
            update_post_meta($postId, self::META_STATUS_TIMESTAMP_BASIS, self::STATUS_TIMESTAMP_BASIS_UTC);
            delete_post_meta($postId, self::META_LAST_ERROR);
            delete_post_meta($postId, self::META_LAST_ERROR_AT);
            return;
        }

        if ($errorMessage !== '') {
            update_post_meta($postId, self::META_LAST_ERROR, $errorMessage);
            update_post_meta($postId, self::META_LAST_ERROR_AT, (string) time());
            update_post_meta($postId, self::META_STATUS_TIMESTAMP_BASIS, self::STATUS_TIMESTAMP_BASIS_UTC);
        } elseif ($status !== 'error') {
            delete_post_meta($postId, self::META_LAST_ERROR);
            delete_post_meta($postId, self::META_LAST_ERROR_AT);
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
                (new MarkdownCacheStore())->saveMarkdown($postId, $markdown);
                update_post_meta($postId, self::META_MARKDOWN_BYTES, (string) strlen($markdown));
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
        $existingDocumentName = trim((string) get_post_meta($postId, self::META_DOCUMENT_NAME, true));
        if ($existingDocumentName !== '') {
            try {
                ProviderFactory::make()->deleteDocument($existingDocumentName);
            } catch (\Exception $e) {
                // Ignore remote delete failures and continue with the replacement upload.
            }
            delete_post_meta($postId, self::META_DOCUMENT_NAME);
        }

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
        $failedFiles = [];

        foreach ($filePaths as $filePath) {
            $documentId = $documentStore->getOrCreateDocument($filePath, $postId);
            if ($documentId !== null) {
                $documentIds[] = $documentId;
            } else {
                $failedFiles[] = basename($filePath);
            }
        }

        $documentStore->updatePostAssociations($postId, $documentIds);
        $this->setLinkedDocumentFailureNames($postId, $failedFiles);
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
     * @return array<int,string>
     */
    private function getLinkedDocumentFailureNames(int $postId): array {
        $stored = get_post_meta($postId, self::META_LINKED_DOCUMENT_FAILURES, true);
        if (!is_array($stored)) {
            return [];
        }

        $names = array_values(array_filter(array_map('strval', $stored), static function (string $item): bool {
            return trim($item) !== '';
        }));
        return array_values(array_unique($names));
    }

    /**
     * @param int $postId
     * @param array<int,string> $fileNames
     * @return void
     */
    private function setLinkedDocumentFailureNames(int $postId, array $fileNames): void {
        $normalized = array_values(array_unique(array_filter(array_map('strval', $fileNames), static function (string $item): bool {
            return trim($item) !== '';
        })));

        if (empty($normalized)) {
            delete_post_meta($postId, self::META_LINKED_DOCUMENT_FAILURES);
            return;
        }

        update_post_meta($postId, self::META_LINKED_DOCUMENT_FAILURES, $normalized);
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

        $isOutOfSync = $this->isPostOutOfSync($postId, $status, $documentName !== '');
        $resolved = $this->resolvePostStatusPresentation($status, $excluded, $error, $documentName !== '', $isOutOfSync);
        $lastIndexed = (int) get_post_meta($postId, self::META_LAST_INDEXED, true);
        $lastErrorAt = (int) get_post_meta($postId, self::META_LAST_ERROR_AT, true);
        $resolved['last_indexed'] = $lastIndexed > 0 ? DateDisplay::formatDateTime($this->normalizeStatusTimestampForDisplay($postId, $lastIndexed)) : '';
        $resolved['last_error_at'] = $lastErrorAt > 0 ? DateDisplay::formatDateTime($this->normalizeStatusTimestampForDisplay($postId, $lastErrorAt)) : '';
        $resolved['error'] = $error;
        $resolved['is_out_of_sync'] = $isOutOfSync ? '1' : '';

        return $resolved;
    }

    private function normalizeStatusTimestampForDisplay(int $postId, int $timestamp): int {
        if ($timestamp <= 0) {
            return 0;
        }

        $basis = (string) get_post_meta($postId, self::META_STATUS_TIMESTAMP_BASIS, true);
        if ($basis === self::STATUS_TIMESTAMP_BASIS_UTC) {
            return $timestamp;
        }

        $timezone = wp_timezone();
        $date = new \DateTimeImmutable('@' . $timestamp);

        return $timestamp - $timezone->getOffset($date);
    }

    /**
     * @param string $status
     * @param bool $excluded
     * @param string $error
     * @return array<string,string>
     */
    private function resolvePostStatusPresentation(string $status, bool $excluded, string $error, bool $hasDocument, bool $isOutOfSync = false): array {
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
            if ($status === 'removing' || $hasDocument) {
                $resolved = ['label' => 'Removing, excluded', 'color' => self::COLOR_WARNING];
            } elseif ($error !== '') {
                $resolved = ['label' => 'Excluded, index error', 'color' => self::COLOR_ERROR];
            } else {
                $resolved = ['label' => 'Excluded', 'color' => self::COLOR_WARNING];
            }
        } elseif ($isOutOfSync) {
            $resolved = ['label' => 'Out of sync', 'color' => self::COLOR_ERROR];
        }

        return $resolved;
    }

    private function isPostOutOfSync(int $postId, string $status = '', bool $hasDocument = false): bool {
        $normalizedStatus = trim($status);
        if ($normalizedStatus === '') {
            $normalizedStatus = (string) get_post_meta($postId, self::META_STATUS, true);
        }

        if ($hasDocument === false) {
            $hasDocument = (string) get_post_meta($postId, self::META_DOCUMENT_NAME, true) !== '';
        }

        $lastIndexed = (int) get_post_meta($postId, self::META_LAST_INDEXED, true);
        $modifiedAt = (int) get_post_modified_time('U', true, $postId);

        return ($normalizedStatus === 'indexed' || $hasDocument)
            && !$this->isExcluded($postId)
            && $lastIndexed > 0
            && $modifiedAt > 0
            && $modifiedAt > $lastIndexed;
    }

    /**
     * @return array<int,int>
     */
    private function getOutOfSyncPostIds(string $postType): array {
        $candidateIds = get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_query' => [[
                'key' => self::META_LAST_INDEXED,
                'compare' => 'EXISTS',
            ]],
        ]);

        if (!is_array($candidateIds) || empty($candidateIds)) {
            return [0];
        }

        $matches = [];
        foreach ($candidateIds as $postId) {
            $postId = (int) $postId;
            if ($postId > 0 && $this->isPostOutOfSync($postId)) {
                $matches[] = $postId;
            }
        }

        return !empty($matches) ? array_values(array_unique($matches)) : [0];
    }

    private function getMarkdownCacheBytes(int $postId, ?MarkdownCacheStore $cacheStore = null): int {
        $storedBytes = (int) get_post_meta($postId, self::META_MARKDOWN_BYTES, true);
        if ($storedBytes > 0) {
            return $storedBytes;
        }

        $cacheStore = $cacheStore ?? new MarkdownCacheStore();
        $bytes = $cacheStore->getMarkdownBytes($postId);
        if ($bytes <= 0) {
            return 0;
        }

        update_post_meta($postId, self::META_MARKDOWN_BYTES, (string) $bytes);
        return $bytes;
    }

    private function getMarkdownCacheColumnHtml(int $postId): string {
        $cacheStore = new MarkdownCacheStore();
        $markdownBytes = $this->getMarkdownCacheBytes($postId, $cacheStore);
        $hasMarkdown = $markdownBytes > 0;
        $status = (string) get_post_meta($postId, self::META_STATUS, true);
        $isExcluded = $this->isExcluded($postId);
        $hasError = $status === 'error' || ((string) get_post_meta($postId, self::META_LAST_ERROR, true) !== '');
        $isBusy = in_array($status, ['pending', 'uploading', 'removing'], true);
        $label = 'Missing';
        if ($isExcluded) {
            $label = 'N/A';
        }
        if ($hasMarkdown) {
            $label = size_format($markdownBytes, 1);
        }
        $color = self::COLOR_WARNING;
        $title = 'View cached Markdown';

        if ($hasMarkdown) {
            if ($isBusy) {
                $color = self::COLOR_MUTED;
                $title = 'View cached Markdown while the upload is in progress';
            } else {
                $color = self::COLOR_SUCCESS;
                $title = 'View cached Markdown';
                if ($hasError) {
                    $color = self::COLOR_ERROR;
                    $title = 'View cached Markdown from the failed upload attempt';
                }
            }
        }

        if ($hasMarkdown) {
            return '<a href="#" class="geweb-ai-markdown-cache-view" data-post-id="' . esc_attr((string) $postId) . '" title="' . esc_attr($title) . '" style="display:inline-block;font-weight:600;color:' . esc_attr($color) . ';">' . esc_html($label) . '</a>';
        }

        return '<span style="display:inline-block;font-weight:600;color:' . esc_attr($color) . ';">' . esc_html($label) . '</span>';
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
        wp_schedule_single_event(time() + 1, self::CRON_HOOK_PROCESS, [$postId, $operation, UserScope::getCurrentGroupScopeKey()]);

        // The admin upload action now queues work for WP-Cron. Proactively spawn
        // cron so the background job starts even on installs where cron traffic
        // is sparse or delayed.
        if (function_exists('spawn_cron') && !defined('DOING_CRON')) {
            spawn_cron(time());
        }
    }

    private function clearScheduledProcessing(int $postId): void {
        foreach (['index', 'delete'] as $operation) {
            $timestamp = wp_next_scheduled(self::CRON_HOOK_PROCESS, [$postId, $operation]);
            while ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK_PROCESS, [$postId, $operation]);
                $timestamp = wp_next_scheduled(self::CRON_HOOK_PROCESS, [$postId, $operation]);
            }

            $scopeKey = UserScope::getCurrentGroupScopeKey();
            $timestamp = wp_next_scheduled(self::CRON_HOOK_PROCESS, [$postId, $operation, $scopeKey]);
            while ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK_PROCESS, [$postId, $operation, $scopeKey]);
                $timestamp = wp_next_scheduled(self::CRON_HOOK_PROCESS, [$postId, $operation, $scopeKey]);
            }
        }
    }
}
