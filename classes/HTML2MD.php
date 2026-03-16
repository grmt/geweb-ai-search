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

    /**
     * Constructor - registers WordPress hooks
     */
    public function __construct() {
        add_action('init', [$this, 'registerPostColumns']);
        add_action('admin_init', [$this, 'handleReupload']);
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('before_delete_post', [$this, 'deleteDocumentForPost']);
        add_action('wp_ajax_geweb_generate_library', [$this, 'ajaxGenerateLibrary']);
        add_action('add_meta_boxes', [$this, 'registerMetaBox']);
        add_action('restrict_manage_posts', [$this, 'renderStatusFilter']);
        add_action('pre_get_posts', [$this, 'applyStatusFilter']);
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

        $statusData = $this->getStatusData($postId);
        $status = '<p style="margin:0; color:' . esc_attr($statusData['color']) . ';">' . esc_html($statusData['label']) . '</p>';

        if (!empty($statusData['last_indexed'])) {
            $status .= '<p style="margin:4px 0 0;"><small>Last indexed: ' . esc_html($statusData['last_indexed']) . '</small></p>';
        }

        if (!empty($statusData['error'])) {
            $status .= '<p style="margin:4px 0 0; color:#d63638;"><small>' . esc_html($statusData['error']) . '</small></p>';
        }

        $url = wp_nonce_url(
            add_query_arg(['geweb_reupload' => $postId], admin_url('edit.php?post_type=' . get_post_type($postId))),
            'geweb_reupload_' . $postId
        );

        $actions = '';
        if (!$this->isExcluded($postId)) {
            $actions = ' <a href="' . esc_url($url) . '" class="button button-small">Re-upload</a>';
        }

        echo wp_kses_post($status . $actions);
    }

    /**
     * Handle re-upload request for a single post (GET action with nonce)
     *
     * @return void
     */
    public function handleReupload(): void {
        if (!is_admin() || empty($_GET['geweb_reupload'])) {
            return;
        }

        $postId = intval($_GET['geweb_reupload']);

        check_admin_referer('geweb_reupload_' . $postId);

        if (!current_user_can('edit_post', $postId)) {
            wp_die('Insufficient permissions');
        }

        $post = get_post($postId);
        if ($post) {
            $this->onSavePost($postId, $post);
        }

        wp_safe_redirect(wp_get_referer());
        exit;
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
            $this->deleteDocumentForPost($postId, 'excluded');
            return;
        }

        // Only for published posts
        if ($post->post_status !== 'publish') {
            $this->deleteDocumentForPost($postId, 'not_indexed');
            return;
        }

        // Convert to markdown
        $markdown = $this->convert($postId);
        if (!$markdown) {
            $this->setStatus($postId, 'error', 'Could not convert post content for indexing.');
            return;
        }

        try {
            $this->deleteDocumentForPost($postId);

            // Upload new document
            $gemini = new Gemini();
            $documentName = $gemini->uploadDocument($markdown, $postId);

            // Save document name in post meta
            update_post_meta($postId, self::META_DOCUMENT_NAME, $documentName);
            $this->setStatus($postId, 'indexed');
        } catch (\Exception $e) {
            $this->setStatus($postId, 'error', $e->getMessage());
        }
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
                // Convert to markdown
                $markdown = $this->convert($postId);
                if (!$markdown) {
                    $this->setStatus($postId, 'error', 'Could not convert post content for indexing.');
                    $errors++;
                    continue;
                }

                // Delete old document if exists
                $this->deleteDocumentForPost($postId);

                // Upload new document
                $gemini = new Gemini();
                $documentName = $gemini->uploadDocument($markdown, $postId);

                // Save document name
                update_post_meta($postId, self::META_DOCUMENT_NAME, $documentName);
                $this->setStatus($postId, 'indexed');

                $success++;
            } catch (\Exception $e) {
                $this->setStatus($postId, 'error', $e->getMessage());
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
     * Delete document from Gemini for given post
     *
     * @param int $postId Post ID
     * @return void
     */
    public function deleteDocumentForPost(int $postId, string $statusAfterDelete = 'not_indexed'): void {
        $documentName = get_post_meta($postId, self::META_DOCUMENT_NAME, true);
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
            update_post_meta($postId, self::META_EXCLUDE, '1');
            $this->setStatus($postId, 'excluded', '');
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
     * Get UI status data for a post
     *
     * @param int $postId Post ID
     * @return array<string,string>
     */
    private function getStatusData(int $postId): array {
        $status = (string) get_post_meta($postId, self::META_STATUS, true);
        $documentName = (string) get_post_meta($postId, self::META_DOCUMENT_NAME, true);

        if ($status === '') {
            $status = $documentName !== '' ? 'indexed' : 'not_indexed';
        }

        $map = [
            'indexed' => ['label' => 'Indexed', 'color' => '#46b450'],
            'not_indexed' => ['label' => 'Not indexed', 'color' => '#646970'],
            'error' => ['label' => 'Index error', 'color' => '#d63638'],
            'excluded' => ['label' => 'Excluded', 'color' => '#996800'],
        ];
        $resolved = $map[$status] ?? $map['not_indexed'];

        $lastIndexed = (int) get_post_meta($postId, self::META_LAST_INDEXED, true);
        $resolved['last_indexed'] = $lastIndexed > 0
            ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $lastIndexed)
            : '';
        $resolved['error'] = (string) get_post_meta($postId, self::META_LAST_ERROR, true);

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
}
