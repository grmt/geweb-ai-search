<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Shared frontend AI workspace context and URL helpers.
 */
class FrontendAiContext {
    use FrontendAiLimitsTrait;

    private const OPTION_FRONTEND_AI_INTERFACE = 'geweb_aisearch_frontend_ai_interface';
    private const OPTION_FRONTEND_AI_PAGE_ID = 'geweb_aisearch_frontend_ai_page_id';
    private const FRONTEND_AI_REWRITE_SLUG = 'ai-search-workspace';
    public const OPTION_CONVERSATION_TRIM_MESSAGE_LIMIT = 'geweb_aisearch_conversation_trim_message_limit';
    public const OPTION_CONVERSATION_TRIM_CHAR_LIMIT = 'geweb_aisearch_conversation_trim_char_limit';
    public const OPTION_LOCAL_CONVERSATION_ARCHIVE_LIMIT = 'geweb_aisearch_local_conversation_archive_limit';
    public const OPTION_STORED_CONTEXT_MESSAGE_LIMIT = 'geweb_aisearch_stored_context_message_limit';
    public const OPTION_STORED_CONTEXT_CHAR_LIMIT = 'geweb_aisearch_stored_context_char_limit';
    private const FRONTEND_AI_QUERY_VAR = 'geweb_ai_query';
    private const DEFAULT_FRONTEND_AI_INTERFACE = 'fullscreen';
    public const DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT = 12;
    public const DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT = 12000;
    public const DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT = 12;
    public const DEFAULT_STORED_CONTEXT_MESSAGE_LIMIT = 120;
    public const DEFAULT_STORED_CONTEXT_CHAR_LIMIT = 60000;

    public static function getFrontendAiPageUrl(): string {
        $pageId = self::getFrontendAiPageId();
        if ($pageId > 0) {
            $baseUrl = get_permalink($pageId);
            if (is_string($baseUrl) && $baseUrl !== '') {
                $args = [];
                $query = self::getRequestedFrontendQuery();
                if ($query !== '') {
                    $args[self::FRONTEND_AI_QUERY_VAR] = $query;
                }

                return !empty($args) ? add_query_arg($args, $baseUrl) : $baseUrl;
            }
        }

        $baseUrl = home_url('/' . self::FRONTEND_AI_REWRITE_SLUG . '/');
        $args = [];

        $query = self::getRequestedFrontendQuery();
        if ($query !== '') {
            $args[self::FRONTEND_AI_QUERY_VAR] = $query;
        }

        return !empty($args) ? add_query_arg($args, $baseUrl) : $baseUrl;
    }

    public static function getCurrentFrontendAiPageUrl(): string {
        $pageId = self::getFrontendAiPageId();
        if ($pageId > 0) {
            $pageUrl = get_permalink($pageId);
            if (is_string($pageUrl) && $pageUrl !== '') {
                return $pageUrl;
            }
        }

        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $requestUri = is_string($requestUri) ? $requestUri : '';

        if ($requestUri === '' || strpos($requestUri, '/wp-admin/') === 0) {
            return self::getFrontendAiPageUrl();
        }

        $url = home_url($requestUri);
        return remove_query_arg(['geweb_ai_chat', 'geweb_ai_conversation', self::FRONTEND_AI_QUERY_VAR, 's'], $url);
    }

    public static function getFrontendAiConversationUrl(string $conversationId = ''): string {
        $args = [];
        $query = self::getRequestedFrontendQuery();
        if ($query !== '') {
            $args[self::FRONTEND_AI_QUERY_VAR] = $query;
        }

        $conversationId = self::sanitizeConversationId($conversationId);
        if ($conversationId !== '') {
            $args['geweb_ai_conversation'] = $conversationId;
        }

        $pageId = self::getFrontendAiPageId();
        if ($pageId > 0) {
            $pageUrl = get_permalink($pageId);
            if (is_string($pageUrl) && $pageUrl !== '') {
                return !empty($args) ? add_query_arg($args, $pageUrl) : $pageUrl;
            }
        }

        $baseUrl = home_url('/' . self::FRONTEND_AI_REWRITE_SLUG . '/');
        return !empty($args) ? add_query_arg($args, $baseUrl) : $baseUrl;
    }

    public static function getFrontendAiExitUrl(): string {
        $pageId = self::getFrontendAiPageId();
        if ($pageId > 0) {
            $pageUrl = get_permalink($pageId);
            if (is_string($pageUrl) && $pageUrl !== '') {
                return $pageUrl;
            }
        }

        $query = self::getRequestedFrontendQuery();
        return $query !== '' ? add_query_arg('s', $query, home_url('/')) : home_url('/');
    }

    public static function isFrontendAiPageRequest(bool $shortcodePageViewActive = false): bool {
        if ($shortcodePageViewActive || self::isConfiguredFrontendAiPageRequest()) {
            return true;
        }

        return self::isDedicatedFrontendAiPageRequest();
    }

    public static function isDedicatedFrontendAiPageRequest(): bool {
        if (self::getFrontendAiInterface() !== 'fullscreen') {
            return false;
        }

        $rewriteValue = get_query_var('geweb_ai_page', '');
        if ((string) $rewriteValue === '1') {
            return true;
        }

        $value = isset($_GET['geweb_ai_chat']) ? sanitize_text_field(wp_unslash($_GET['geweb_ai_chat'])) : '';
        return $value === '1';
    }

    public static function isConfiguredFrontendAiPageRequest(): bool {
        $pageId = self::getFrontendAiPageId();
        return $pageId > 0 && is_page($pageId);
    }

    public static function getRequestedFrontendConversationId(): string {
        $rewriteValue = get_query_var('geweb_ai_conversation', '');
        if (is_string($rewriteValue) && $rewriteValue !== '') {
            return self::sanitizeConversationId($rewriteValue);
        }

        return isset($_GET['geweb_ai_conversation']) ? self::sanitizeConversationId(wp_unslash($_GET['geweb_ai_conversation'])) : '';
    }

    public static function getRequestedFrontendQuery(): string {
        if (isset($_GET[self::FRONTEND_AI_QUERY_VAR])) {
            return sanitize_text_field(wp_unslash($_GET[self::FRONTEND_AI_QUERY_VAR]));
        }

        return isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    }

    /**
     * @param mixed $value
     */
    public static function sanitizeConversationId($value): string {
        $value = is_string($value) ? $value : (string) $value;
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9_-]/', '', $value);
        return is_string($value) ? $value : '';
    }

    public static function getFrontendAiPageId(): int {
        $pageId = (int) get_option(self::OPTION_FRONTEND_AI_PAGE_ID, 0);
        if ($pageId > 0) {
            $post = get_post($pageId);
            if ($post instanceof \WP_Post && $post->post_type === 'page' && $post->post_status !== 'trash') {
                return $pageId;
            }
        }

        static $attemptedEnsure = false;
        if (!$attemptedEnsure) {
            $attemptedEnsure = true;
            $pageId = FrontendAiPageManager::ensureFrontendAiPageExists();
            if ($pageId > 0) {
                return $pageId;
            }
        }

        return 0;
    }

    public static function getFrontendAiInterface(): string {
        return self::normalizeFrontendAiInterface((string) get_option(self::OPTION_FRONTEND_AI_INTERFACE, self::DEFAULT_FRONTEND_AI_INTERFACE));
    }

    /**
     * @param array<int,string> $classes
     * @return array<int,string>
     */
    public static function filterFrontendAiBodyClasses(array $classes): array {
        $classes[] = 'geweb-ai-page';
        $classes[] = 'geweb-ai-page-open';
        return array_values(array_unique($classes));
    }

    public static function buildFrontendSearchResultExcerpt(int $postId): string {
        return FrontendSearchResultExcerptBuilder::build($postId);
    }

    /**
     * @param mixed $value
     */
    public static function sanitizePositiveIntOption($value, int $default, int $min, int $max): int {
        $normalized = $default;
        if ($value === null || $value === '') {
            return $normalized;
        }

        $normalized = intval($value);
        $normalized = max($min, $normalized);
        $normalized = min($max, $normalized);

        return $normalized;
    }

    /**
     * @param mixed $value
     */
    public static function normalizeFrontendAiInterface($value): string {
        $normalized = sanitize_key((string) $value);
        if ($normalized === 'split') {
            return 'fullscreen';
        }

        return in_array($normalized, ['modal', 'fullscreen'], true) ? $normalized : self::DEFAULT_FRONTEND_AI_INTERFACE;
    }
}
