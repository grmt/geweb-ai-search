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
     * Constructor - registers WordPress hooks
     */
    public function __construct() {
        add_action('init', [$this, 'registerPostColumns']);
        add_action('admin_init', [$this, 'handleReupload']);
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('before_delete_post', [$this, 'deleteDocumentForPost']);
        add_action('wp_ajax_geweb_generate_library', [$this, 'ajaxGenerateLibrary']);
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

        $status = empty(get_post_meta($postId, self::META_DOCUMENT_NAME, true))
            ? '<p style="color:#dc3232;">&#10007; Not indexed</p>'
            : '<p style="color:#46b450;">&#10003; Indexed</p>';

        $url = wp_nonce_url(
            add_query_arg(['geweb_reupload' => $postId], admin_url('edit.php?post_type=' . get_post_type($postId))),
            'geweb_reupload_' . $postId
        );

        echo wp_kses_post(
            $status . ' <a href="' . esc_url($url) . '" class="button button-small">Re-upload</a>'
        );
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

        // Check if post type is enabled
        $postTypes = get_option('geweb_aisearch_post_types', []);
        if (!in_array($post->post_type, $postTypes)) {
            return;
        }

        // Only for published posts
        if ($post->post_status !== 'publish') {
            $this->deleteDocumentForPost($postId);
            return;
        }

        // Convert to markdown
        $markdown = $this->convert($postId);
        if (!$markdown) {
            return;
        }

        try {
            $this->deleteDocumentForPost($postId);

            // Upload new document
            $gemini = new Gemini();
            $documentName = $gemini->uploadDocument($markdown, $postId);

            // Save document name in post meta
            update_post_meta($postId, self::META_DOCUMENT_NAME, $documentName);
        } catch (\Exception $e) {}
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
            'no_found_rows' => false
        ]);
        $total = $totalQuery->found_posts;

        // Get posts for current page
        $posts = get_posts([
            'post_type' => $postTypes,
            'post_status' => 'publish',
            'posts_per_page' => $perPage,
            'paged' => $page,
            'fields' => 'ids'
        ]);

        $success = 0;
        $errors = 0;

        foreach ($posts as $postId) {
            try {
                // Convert to markdown
                $markdown = $this->convert($postId);
                if (!$markdown) {
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
    private function deleteDocumentForPost(int $postId): void {
        $documentName = get_post_meta($postId, self::META_DOCUMENT_NAME, true);
        if (empty($documentName)) {
            return;
        }

        try {
            $gemini = new Gemini();
            $gemini->deleteDocument($documentName);
        } catch (\Exception $e) {}

        // Remove meta even if deletion failed
        delete_post_meta($postId, self::META_DOCUMENT_NAME);
    }
}
