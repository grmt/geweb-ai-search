<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Creates and maintains the dedicated frontend AI page.
 */
class FrontendAiPageManager {
    private const OPTION_FRONTEND_AI_PAGE_ID = 'geweb_aisearch_frontend_ai_page_id';
    private const FRONTEND_AI_SLUG = 'ai-search';

    public static function ensureFrontendAiPageExists(): int {
        $pageId = 0;
        $storedId = (int) get_option(self::OPTION_FRONTEND_AI_PAGE_ID, 0);
        if ($storedId > 0) {
            $post = get_post($storedId);
            if ($post instanceof \WP_Post && $post->post_status !== 'trash') {
                self::markFrontendAiPageExcluded($storedId);
                $pageId = $storedId;
            }
        }

        if ($pageId <= 0) {
            $existing = get_page_by_path(self::FRONTEND_AI_SLUG, OBJECT, 'page');
            if ($existing instanceof \WP_Post) {
                $pageId = (int) $existing->ID;
            }
        }

        if ($pageId <= 0) {
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
                    $pageId = (int) $page->ID;
                    break;
                }
            }
        }

        if ($pageId <= 0) {
            $insertedPageId = wp_insert_post([
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => 'AI Search',
                'post_name' => self::FRONTEND_AI_SLUG,
                'post_content' => '[geweb_ai_search]',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ], true);

            if (!is_wp_error($insertedPageId) && (int) $insertedPageId > 0) {
                $pageId = (int) $insertedPageId;
            }
        }

        if ($pageId > 0) {
            update_option(self::OPTION_FRONTEND_AI_PAGE_ID, $pageId, false);
            self::markFrontendAiPageExcluded($pageId);
        }

        return $pageId;
    }

    private static function markFrontendAiPageExcluded(int $pageId): void {
        if ($pageId <= 0) {
            return;
        }

        update_post_meta($pageId, 'geweb_aisearch_exclude', '1');
        update_post_meta($pageId, 'geweb_aisearch_status', 'excluded');
    }
}
