<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * HTML to Markdown converter
 *
 * Converts WordPress posts to Markdown format for AI indexing
 */
class HTML2MD {
    private const OPTION_INCLUDE_REFERENCED_DOCUMENTS = 'geweb_aisearch_include_referenced_documents';
    /**
     * Post meta key for storing document name in Gemini
     */
    private const META_DOCUMENT_NAME = 'geweb_aisearch_document_name';

    /**
     * Post meta keys for indexing controls and status
     */
    private const META_EXCLUDE = 'geweb_aisearch_exclude';
    private const META_STATUS = 'geweb_aisearch_status';
    private const META_LAST_INDEXED = 'geweb_aisearch_last_indexed';
    private const META_LAST_ERROR = 'geweb_aisearch_last_error';
    private const NONCE_ACTION = 'geweb_aisearch_post_settings';
    private const NONCE_NAME = 'geweb_aisearch_post_settings_nonce';
    private const CRON_HOOK_PROCESS = 'geweb_aisearch_process_post';
    /**
     * Constructor - registers WordPress hooks
     */
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

    /**
     * Register post list columns for all enabled post types
     */
    public function registerPostColumns(): void {
        $postTypes = get_option('geweb_aisearch_post_types', []);
        if ( !empty($postTypes) ) {
            foreach ($postTypes as $postType) {
                add_filter("manage_{$postType}_posts_columns", [$this, 'addIndexedColumn']);
                add_action("manage_{$postType}_posts_custom_column", [$this, 'renderIndexedColumn'], 10, 2);
            }
        }
    }

    /**
     * Add "AI Indexed" column header
     */
    public function addIndexedColumn(array $columns): array {
        $columns['geweb_ai_indexed'] = 'AI Indexed';
        return $columns;
    }

    /**
     * Render "AI Indexed" column cell
     */
    public function renderIndexedColumn(string $column, int $postId): void {
        if ($column !== 'geweb_ai_indexed') {
            return;
        }
        echo wp_kses($this->getColumnHtml($postId), $this->getColumnAllowedHtml());
    }

    /**
     * Convert WordPress post to Markdown
     *
     * @param int $postId Post ID
     * @return string|null Markdown content or null on error
     */
    public function convert(int $postId): ?string {
        $post = get_post($postId);
        if (!$post) {
            return null;
        }

        // Get post content and apply filters (shortcodes, embeds, etc)
        $content = apply_filters('the_content', $post->post_content);

        // Remove scripts and styles
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);

        // Convert HTML to Markdown
        $converter = new HtmlConverter();
        $mdContent = $converter->convert($content);

        // Build frontmatter
        $url = get_permalink($postId);
        $title = get_the_title($postId);

        $frontmatter = "---\n";
        $frontmatter .= "url: {$url}\n";
        $frontmatter .= "title: {$title}\n";
        $frontmatter .= "---\n\n";
        $frontmatter .= "# {$title}\n\n";
        $frontmatter .= $mdContent;

        return $frontmatter;
    }

    /**
     * Register post-level AI indexing meta box
     *
     * @return void
     */
    public function registerMetaBox(): void {
        $postTypes = get_option('geweb_aisearch_post_types', []);
        foreach ($postTypes as $postType) {
            add_meta_box(
                'geweb-aisearch-settings',
                'AI Search',
                [$this, 'renderMetaBox'],
                $postType,
                'side',
                'default'
            );
        }
    }

    /**
     * Render post-level AI indexing controls
     *
     * @param \WP_Post $post Post object
     * @return void
     */
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

    /**
     * Hook: Save post - update document in Gemini
     *
     * @param int $postId Post ID
     * @param \WP_Post $post Post object
     * @return void
     */
    public function onSavePost(int $postId, \WP_Post $post): void {
        // Skip autosave, revisions, and auto-drafts
        if (wp_is_post_autosave($postId) || wp_is_post_revision($postId) || $post->post_status === 'auto-draft') {
            return;
        }

        $this->saveExcludeSetting($postId);

        // Check if post type is enabled
        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (!in_array($post->post_type, $postTypes)) {
            return;
        }

        if ($this->isExcluded($postId)) {
            $this->clearScheduledProcessing($postId);
            $this->schedulePostProcessing($postId, 'delete');
            $this->setStatus($postId, 'removing');
            return;
        }

        // Only for published posts
        if ($post->post_status !== 'publish') {
            $this->clearScheduledProcessing($postId);
            $this->schedulePostProcessing($postId, 'delete');
            $this->setStatus($postId, 'removing');
            return;
        }

        $this->clearScheduledProcessing($postId);
        $this->setStatus($postId, 'uploading');
        $this->schedulePostProcessing($postId, 'index');
    }

    /**
     * AJAX: Generate library - process all published posts
     *
     * @return void
     */
    public function ajaxGenerateLibrary(): void {
        check_ajax_referer('geweb_ai_search_generate_library', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (empty($postTypes)) {
            wp_send_json_error(['message' => 'No post types selected']);
        }

        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $perPage = 10;

        // Get total count
        $totalQuery = new \WP_Query([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'meta_query' => $this->getIndexableMetaQuery(),
        ]);
        $total = $totalQuery->found_posts;

        // Get posts for current page
        $posts = get_posts([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'fields' => 'ids',
            'meta_query' => $this->getIndexableMetaQuery(),
        ]);

        $success = 0;
        $errors = 0;

        foreach ($posts as $postId) {
            try {
                $result = $this->indexPost($postId, true);
                if ($result['success']) {
                    $success++;
                    continue;
                }

                $errors++;
            } catch (\Exception $e) {
                $this->excludeAfterFailure($postId, $e->getMessage());
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
            'next_page' => $hasMore ? $page + 1 : null
        ]);
    }

    /**
     * Render "Generate Library" button in admin settings
     *
     * @return void
     */
    public static function renderButton(): void {
    ?>
        <tr>
            <th>Generate AI Library:</th>
            <td>
                <button type="button" id="geweb-generate-library" class="button">Generate Library</button>
                <p class="description">Process all published posts and upload them to Gemini for AI search.</p>
                <div id="geweb-generate-status"></div>
            </td>
        </tr>
    <?php
    }

    /**
     * AJAX: re-upload a single post in the background
     *
     * @return void
     */
    public function ajaxReuploadPost(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if ($postId <= 0) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        if ($this->isExcluded($postId)) {
            delete_post_meta($postId, self::META_EXCLUDE);
        }

        $this->setStatus($postId, 'uploading');
        $result = $this->indexPost($postId, false);
        if (!$result['success']) {
            wp_send_json_error([
                'message' => $result['message'],
                'html' => $this->getColumnHtml($postId),
            ]);
        }

        wp_send_json_success([
            'message' => 'Indexed successfully.',
            'html' => $this->getColumnHtml($postId),
        ]);
    }

    /**
     * AJAX: toggle exclude/include for a single post
     *
     * @return void
     */
    public function ajaxToggleExclude(): void {
        check_ajax_referer('geweb_ai_search_admin_actions', 'nonce');

        $postId = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        $exclude = !empty($_POST['exclude']);

        if ($postId <= 0) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }

        if (!current_user_can('edit_post', $postId)) {
            wp_send_json_error(['message' => 'Insufficient permissions']);
        }

        if ($exclude) {
            $this->setStatus($postId, 'removing');
            update_post_meta($postId, self::META_EXCLUDE, '1');
            $this->excludePost($postId);

            wp_send_json_success([
                'message' => 'Excluded from AI indexing.',
                'html' => $this->getColumnHtml($postId),
            ]);
        }

        delete_post_meta($postId, self::META_EXCLUDE);
        if (in_array(get_post_meta($postId, self::META_STATUS, true), ['excluded', 'error', 'removing'], true)) {
            $this->setStatus($postId, 'not_indexed', '');
        }

        wp_send_json_success([
            'message' => 'Included for AI indexing again.',
            'html' => $this->getColumnHtml($postId),
        ]);
    }

    /**
     * Delete document from Gemini for given post
     *
     * @param int $postId Post ID
     * @return void
     */
    public function deleteDocumentForPost(int $postId, string $statusAfterDelete = 'not_indexed'): void {
        $documentName = get_post_meta($postId, self::META_DOCUMENT_NAME, true);
        $documentStore = new DocumentStore();
        $documentStore->disassociatePost($postId);
        if (empty($documentName)) {
            $this->setStatus($postId, $statusAfterDelete, '');
            return;
        }

        try {
            $gemini = new Gemini();
            $gemini->deleteDocument($documentName);
        } catch (\Exception $e) {}

        // Remove meta even if deletion failed
        delete_post_meta($postId, self::META_DOCUMENT_NAME);
        $this->setStatus($postId, $statusAfterDelete, '');
    }

    /**
     * Build admin column HTML for a post
     *
     * @param int $postId Post ID
     * @return string
     */
    private function getColumnHtml(int $postId): string {
        $statusData = $this->getStatusData($postId);
        $isExcluded = $this->isExcluded($postId);
        $html = '<div class="geweb-ai-index-cell" data-post-id="' . esc_attr((string) $postId) . '">';
        $html .= '<p style="margin:0; color:' . esc_attr($statusData['color']) . ';">' . esc_html($statusData['label']) . '</p>';

        if (!empty($statusData['last_indexed'])) {
            $html .= '<p style="margin:4px 0 0;"><small>Last indexed: ' . esc_html($statusData['last_indexed']) . '</small></p>';
        }

        if (!empty($statusData['error'])) {
            $html .= '<p style="margin:4px 0 0; color:#d63638;"><small>' . esc_html($statusData['error']) . '</small></p>';
        }

        $html .= '<p class="geweb-ai-index-feedback" style="display:none; margin:4px 0 0;"></p>';
        $html .= '<p style="margin:8px 0 0;">';
        $html .= '<button type="button" class="button button-small geweb-ai-reupload">Upload</button> ';
        $html .= '<label style="margin-left:8px;">';
        $html .= '<input type="checkbox" class="geweb-ai-toggle-exclude" ' . checked($isExcluded, true, false) . disabled($isExcluded, true, false) . '> Exclude';
        $html .= '</label>';

        $html .= '</p></div>';

        return $html;
    }

    /**
     * Allowed HTML for the admin index-status column
     *
     * @return array<string,array<string,bool>>
     */
    private function getColumnAllowedHtml(): array {
        return [
            'div' => [
                'class' => true,
                'data-post-id' => true,
                'style' => true,
            ],
            'p' => [
                'class' => true,
                'style' => true,
            ],
            'small' => [
                'style' => true,
            ],
            'button' => [
                'type' => true,
                'class' => true,
            ],
            'label' => [
                'style' => true,
            ],
            'input' => [
                'type' => true,
                'class' => true,
                'checked' => true,
                'disabled' => true,
            ],
        ];
    }

    /**
     * Render status filter on post list pages
     *
     * @return void
     */
    public function renderStatusFilter(): void {
        global $typenow;

        if (!$this->isManagedPostType($typenow)) {
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

    /**
     * Apply status filter to post list queries
     *
     * @param \WP_Query $query Query object
     * @return void
     */
    public function applyStatusFilter(\WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $postType = $query->get('post_type');
        if (is_array($postType)) {
            return;
        }

        if (!$this->isManagedPostType($postType)) {
            return;
        }

        $status = sanitize_key(wp_unslash($_GET['geweb_ai_index_status'] ?? ''));
        if ($status === '') {
            return;
        }

        if ($status === 'excluded') {
            $query->set('meta_query', [
                [
                    'key' => self::META_EXCLUDE,
                    'value' => '1',
                    'compare' => '=',
                ],
            ]);
            return;
        }

        if ($status === 'indexed') {
            $query->set('meta_query', [
                [
                    'key' => self::META_DOCUMENT_NAME,
                    'compare' => 'EXISTS',
                ],
            ]);
            return;
        }

        if ($status === 'not_indexed') {
            $query->set('meta_query', [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    [
                        'key' => self::META_DOCUMENT_NAME,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => self::META_DOCUMENT_NAME,
                        'value' => '',
                        'compare' => '=',
                    ],
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => self::META_STATUS,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => self::META_STATUS,
                        'value' => 'not_indexed',
                        'compare' => '=',
                    ],
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => self::META_EXCLUDE,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => self::META_EXCLUDE,
                        'value' => '1',
                        'compare' => '!=',
                    ],
                ],
            ]);
            return;
        }

        $query->set('meta_query', [
            [
                'key' => self::META_STATUS,
                'value' => $status,
                'compare' => '=',
            ],
        ]);
    }

    /**
     * Save exclude setting from post editor
     *
     * @param int $postId Post ID
     * @return void
     */
    private function saveExcludeSetting(int $postId): void {
        if (
            !isset($_POST[self::NONCE_NAME]) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION) ||
            !current_user_can('edit_post', $postId)
        ) {
            return;
        }

        $exclude = !empty($_POST['geweb_aisearch_exclude']);
        if ($exclude) {
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

    /**
     * Check whether post is excluded from indexing
     *
     * @param int $postId Post ID
     * @return bool
     */
    private function isExcluded(int $postId): bool {
        return get_post_meta($postId, self::META_EXCLUDE, true) === '1';
    }

    /**
     * Update indexing status metadata
     *
     * @param int $postId Post ID
     * @param string $status Status value
     * @param string $errorMessage Error message
     * @return void
     */
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
     * Index a single post
     *
     * @param int $postId Post ID
     * @param bool $excludeOnFailure Whether failures should auto-exclude the post
     * @return array<string,mixed>
     */
    private function indexPost(int $postId, bool $excludeOnFailure): array {
        $post = get_post($postId);
        if (!$post) {
            return ['success' => false, 'message' => 'Post not found.'];
        }

        if ($post->post_status !== 'publish') {
            $this->deleteDocumentForPost($postId, 'not_indexed');
            return ['success' => false, 'message' => 'Only published content can be indexed.'];
        }

        if ($this->isExcluded($postId)) {
            delete_post_meta($postId, self::META_EXCLUDE);
        }

        $markdown = $this->convert($postId);
        if (!$markdown) {
            $message = 'Could not convert post content for indexing.';
            if ($excludeOnFailure) {
                $this->excludeAfterFailure($postId, $message);
            } else {
                $this->setStatus($postId, 'error', $message);
            }

            return ['success' => false, 'message' => $message];
        }

        try {
            $this->deleteDocumentForPost($postId);
            $gemini = new Gemini();
            $documentName = $gemini->uploadDocument($markdown, $postId);
            update_post_meta($postId, self::META_DOCUMENT_NAME, $documentName);
            if ($this->shouldUploadReferencedDocuments()) {
                $this->indexReferencedAttachments($postId);
            } else {
                (new DocumentStore())->disassociatePost($postId);
            }
            $this->setStatus($postId, 'indexed');

            return ['success' => true, 'message' => 'Indexed successfully.'];
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if ($excludeOnFailure) {
                $this->excludeAfterFailure($postId, $message);
            } else {
                $this->setStatus($postId, 'error', $message);
            }

            return ['success' => false, 'message' => $message];
        }
    }

    /**
     * Exclude a post after a bulk indexing failure
     *
     * @param int $postId Post ID
     * @param string $message Error message
     * @return void
     */
    private function excludeAfterFailure(int $postId, string $message): void {
        update_post_meta($postId, self::META_EXCLUDE, '1');
        $this->excludePost($postId);
        update_post_meta($postId, self::META_LAST_ERROR, $message);
    }

    /**
     * Exclude a post and remove it from Gemini only if it is currently indexed
     *
     * @param int $postId Post ID
     * @return void
     */
    private function excludePost(int $postId): void {
        $documentName = (string) get_post_meta($postId, self::META_DOCUMENT_NAME, true);
        if ($documentName === '') {
            (new DocumentStore())->disassociatePost($postId);
            $this->setStatus($postId, 'excluded', '');
            return;
        }

        $this->deleteDocumentForPost($postId, 'excluded');
    }

    /**
     * Upload supported locally hosted files referenced by the post content.
     *
     * @param int $postId
     * @return void
     */
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

    /**
     * Whether referenced local documents should be uploaded with the page.
     *
     * @return bool
     */
    private function shouldUploadReferencedDocuments(): bool {
        return get_option(self::OPTION_INCLUDE_REFERENCED_DOCUMENTS, '0') === '1';
    }

    /**
     * Find supported local attachment paths referenced in post content.
     *
     * @param int $postId
     * @return array<int,string>
     */
    private function getReferencedAttachmentPaths(int $postId): array {
        $filePaths = [];
        foreach (self::getReferencedAttachmentEntriesForPost($postId) as $reference) {
            $filePath = isset($reference['file_path']) ? (string) $reference['file_path'] : '';
            if ($filePath !== '') {
                $filePaths[$filePath] = $filePath;
            }
        }

        return array_values($filePaths);
    }

    /**
     * Find referenced local attachment entries in post content.
     *
     * @param int $postId
     * @return array<int,array<string,string>>
     */
    public static function getReferencedAttachmentEntriesForPost(int $postId): array {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return [];
        }

        $content = (string) apply_filters('the_content', $post->post_content);
        if ($content === '') {
            return [];
        }

        $urls = [];
        if (preg_match_all('/(?:href|src)\s*=\s*["\']([^"\']+)["\']/i', $content, $matches)) {
            $urls = isset($matches[1]) && is_array($matches[1]) ? $matches[1] : [];
        }

        $entries = [];
        foreach ($urls as $url) {
            $resolved = self::resolveReferencedLocalFilePathFromUrl($url);
            if ($resolved === null) {
                continue;
            }

            $entries[$resolved['file_path']] = $resolved;
        }

        return array_values($entries);
    }

    /**
     * Resolve a linked local URL to a readable uploads file path.
     *
     * @param string $url
     * @return string|null
     */
    private function resolveReferencedLocalFilePath(string $url): ?string {
        $resolved = self::resolveReferencedLocalFilePathFromUrl($url);
        return is_array($resolved) ? (string) $resolved['file_path'] : null;
    }

    /**
     * Resolve a linked local URL to a readable uploads file path and URL.
     *
     * @param string $url
     * @return array<string,string>|null
     */
    public static function resolveReferencedLocalFilePathFromUrl(string $url): ?array {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $uploads = wp_get_upload_dir();
        $baseUrl = (string) ($uploads['baseurl'] ?? '');
        $baseDir = (string) ($uploads['basedir'] ?? '');
        if ($baseUrl === '' || $baseDir === '') {
            return null;
        }

        $normalizedUrl = explode('#', $url, 2)[0];
        $normalizedUrl = explode('?', $normalizedUrl, 2)[0];
        if (strpos($normalizedUrl, $baseUrl) !== 0) {
            return null;
        }

        $relativePath = ltrim(substr($normalizedUrl, strlen($baseUrl)), '/');
        $filePath = wp_normalize_path(trailingslashit($baseDir) . $relativePath);
        $normalizedBaseDir = wp_normalize_path($baseDir);
        if (strpos($filePath, $normalizedBaseDir) !== 0 || !is_readable($filePath)) {
            return null;
        }

        return [
            'file_path' => $filePath,
            'file_url' => $normalizedUrl,
        ];
    }

    /**
     * Get UI status data for a post
     *
     * @param int $postId Post ID
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

        $map = [
            'indexed' => ['label' => 'Indexed', 'color' => '#46b450'],
            'pending' => ['label' => 'Queued', 'color' => '#2271b1'],
            'uploading' => ['label' => 'Uploading', 'color' => '#2271b1'],
            'removing' => ['label' => 'Removing', 'color' => '#996800'],
            'not_indexed' => ['label' => 'Not indexed', 'color' => '#646970'],
            'error' => ['label' => 'Index error', 'color' => '#d63638'],
            'excluded' => ['label' => 'Excluded', 'color' => '#996800'],
        ];
        $resolved = $map[$status] ?? $map['not_indexed'];

        if ($excluded) {
            if ($status === 'removing') {
                $resolved = ['label' => 'Removing, excluded', 'color' => '#996800'];
            } elseif ($error !== '') {
                $resolved = ['label' => 'Excluded, index error', 'color' => '#d63638'];
            } else {
                $resolved = ['label' => 'Excluded', 'color' => '#996800'];
            }
        }

        $lastIndexed = (int) get_post_meta($postId, self::META_LAST_INDEXED, true);
        $resolved['last_indexed'] = $lastIndexed > 0
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $lastIndexed)
            : '';
        $resolved['error'] = $error;

        return $resolved;
    }

    /**
     * Get meta query that excludes posts marked as excluded
     *
     * @return array<int,array<string,mixed>>
     */
    private function getIndexableMetaQuery(): array {
        return [
            'relation' => 'OR',
            [
                'key' => self::META_EXCLUDE,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => self::META_EXCLUDE,
                'value' => '1',
                'compare' => '!=',
            ],
        ];
    }

    /**
     * Check whether post type is managed by AI indexing
     *
     * @param string $postType Post type slug
     * @return bool
     */
    private function isManagedPostType(string $postType): bool {
        return $postType !== '' && in_array($postType, get_option('geweb_aisearch_post_types', []), true);
    }

    /**
     * Queue background processing for a post
     *
     * @param int $postId Post ID
     * @param string $operation index|delete
     * @return void
     */
    private function schedulePostProcessing(int $postId, string $operation): void {
        wp_schedule_single_event(time() + 1, self::CRON_HOOK_PROCESS, [$postId, $operation]);
    }

    /**
     * Remove queued processing jobs for a post
     *
     * @param int $postId Post ID
     * @return void
     */
    private function clearScheduledProcessing(int $postId): void {
        foreach (['index', 'delete'] as $operation) {
            $timestamp = wp_next_scheduled(self::CRON_HOOK_PROCESS, [$postId, $operation]);
            while ($timestamp) {
                wp_unschedule_event($timestamp, self::CRON_HOOK_PROCESS, [$postId, $operation]);
                $timestamp = wp_next_scheduled(self::CRON_HOOK_PROCESS, [$postId, $operation]);
            }
        }
    }

    /**
     * Process a queued indexing job
     *
     * @param int $postId Post ID
     * @param string $operation index|delete
     * @return void
     */
    public function processQueuedPost(int $postId, string $operation): void {
        if ($operation === 'delete') {
            $status = $this->isExcluded($postId) ? 'excluded' : 'not_indexed';
            $this->deleteDocumentForPost($postId, $status);
            return;
        }

        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return;
        }

        if ($this->isExcluded($postId)) {
            $this->deleteDocumentForPost($postId, 'excluded');
            return;
        }

        if ($post->post_status !== 'publish') {
            $this->deleteDocumentForPost($postId, 'not_indexed');
            return;
        }

        $this->setStatus($postId, 'uploading');
        $this->indexPost($postId, false);
    }
}
