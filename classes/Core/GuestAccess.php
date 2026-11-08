<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Whether visitors without a login may use the front end (search, AI chat,
 * conversations, source references). Off by default: an AI request costs
 * money and may read members-only sources. A site that wants it sets the
 * option geweb_aisearch_allow_guests to '1', or returns true from the
 * geweb_ai_search_allow_guests filter.
 */
class GuestAccess {
    public const OPTION_ALLOW_GUESTS = 'geweb_aisearch_allow_guests';

    public static function allowed(): bool {
        $allowed = get_option(self::OPTION_ALLOW_GUESTS, '0') === '1';
        return (bool) apply_filters('geweb_ai_search_allow_guests', $allowed);
    }

    /**
     * Registers a front-end AJAX action: for logged-in users always, for
     * visitors only when allowed() -- otherwise they get a 401.
     */
    public static function registerFrontendAjax(string $action, callable $handler): void {
        add_action('wp_ajax_' . $action, $handler);
        add_action('wp_ajax_nopriv_' . $action, static function () use ($handler): void {
            if (!self::allowed()) {
                wp_send_json_error(['message' => __('Log in to use the search.', 'geweb-ai-search')], 401);
            }
            $handler();
        });
    }
}
