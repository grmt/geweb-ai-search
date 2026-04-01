<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * HTML to Markdown converter.
 */
class HTML2MD {
    /**
     * Convert WordPress post to Markdown.
     *
     * @param int $postId
     * @return string|null
     */
    public function convert(int $postId): ?string {
        $post = get_post($postId);
        if (!$post) {
            return null;
        }

        $content = apply_filters('the_content', $post->post_content);
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);

        $converter = new HtmlConverter();
        $mdContent = $converter->convert($content);

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
}
