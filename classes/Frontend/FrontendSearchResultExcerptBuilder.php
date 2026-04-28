<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class FrontendSearchResultExcerptBuilder {
    private const REGEX_WHITESPACE = '/\s+/';

    public static function build(int $postId): string {
        $content = self::getPostContentExcerpt($postId);
        if ($content === '') {
            $content = self::normalizeText((string) get_the_excerpt($postId));
        }

        $content = ltrim($content, ". \t\n\r\0\x0B");
        return $content !== ''
            ? wp_trim_words($content, 42)
            : __('No preview text available for this result.', 'geweb-ai-search');
    }

    private static function getPostContentExcerpt(int $postId): string {
        $content = get_post_field('post_content', $postId);
        return is_string($content) ? self::normalizeText($content) : '';
    }

    private static function normalizeText(string $content): string {
        $content = wp_strip_all_tags($content);
        return trim(preg_replace(self::REGEX_WHITESPACE, ' ', $content) ?? '');
    }
}
