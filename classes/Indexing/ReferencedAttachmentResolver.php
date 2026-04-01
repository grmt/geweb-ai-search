<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves locally referenced attachment links from post content.
 */
class ReferencedAttachmentResolver {
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

        $entries = [];
        self::collectReferencedAttachmentEntriesFromAnchors($content, $entries);
        self::collectFallbackReferencedAttachmentEntries($content, $entries);
        self::applyReferencedAttachmentDisplayNames($entries);

        return array_values($entries);
    }

    /**
     * Resolve a linked local URL to a readable uploads file path and URL.
     *
     * @param string $url
     * @return array<string,string>|null
     */
    public static function resolveReferencedLocalFilePathFromUrl(string $url): ?array {
        $url = trim($url);
        $resolved = null;

        if ($url !== '') {
            $uploads = wp_get_upload_dir();
            $baseUrl = (string) ($uploads['baseurl'] ?? '');
            $baseDir = (string) ($uploads['basedir'] ?? '');
            if ($baseUrl !== '' && $baseDir !== '') {
                $normalizedUrl = self::normalizeReferencedUrl($url);
                $filePath = self::resolveUploadsPathFromUrl($normalizedUrl, $baseUrl, $baseDir);
                if ($filePath !== null && self::isSupportedReferencedFilePath($filePath)) {
                    $resolved = [
                        'file_path' => $filePath,
                        'file_url' => $normalizedUrl,
                    ];
                }
            }
        }

        return $resolved;
    }

    /**
     * @param string $content
     * @param array<string,array<string,string>> $entries
     * @return void
     */
    private static function collectReferencedAttachmentEntriesFromAnchors(string $content, array &$entries): void {
        if (!preg_match_all('/<a\b[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $url = isset($match[1]) ? (string) $match[1] : '';
            $resolved = self::resolveReferencedLocalFilePathFromUrl($url);
            if ($resolved === null) {
                continue;
            }

            $label = self::extractReferencedAttachmentLabel(isset($match[2]) ? (string) $match[2] : '');
            if ($label !== '') {
                $resolved['display_name'] = $label;
            }

            $entries[$resolved['file_path']] = $resolved;
        }
    }

    /**
     * @param string $content
     * @param array<string,array<string,string>> $entries
     * @return void
     */
    private static function collectFallbackReferencedAttachmentEntries(string $content, array &$entries): void {
        if (!empty($entries) || !preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $content, $matches)) {
            return;
        }

        $urls = isset($matches[1]) && is_array($matches[1]) ? $matches[1] : [];
        foreach ($urls as $url) {
            $resolved = self::resolveReferencedLocalFilePathFromUrl((string) $url);
            if ($resolved !== null) {
                $entries[$resolved['file_path']] = $resolved;
            }
        }
    }

    /**
     * @param string $labelHtml
     * @return string
     */
    private static function extractReferencedAttachmentLabel(string $labelHtml): string {
        return trim(wp_strip_all_tags(html_entity_decode($labelHtml, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'))));
    }

    /**
     * @param array<string,array<string,string>> $entries
     * @return void
     */
    private static function applyReferencedAttachmentDisplayNames(array &$entries): void {
        foreach ($entries as $filePath => $entry) {
            if (empty($entry['display_name'])) {
                $entries[$filePath]['display_name'] = basename((string) $filePath);
            }
        }
    }

    private static function normalizeReferencedUrl(string $url): string {
        $normalizedUrl = explode('#', $url, 2)[0];
        return explode('?', $normalizedUrl, 2)[0];
    }

    private static function resolveUploadsPathFromUrl(string $normalizedUrl, string $baseUrl, string $baseDir): ?string {
        if (strpos($normalizedUrl, $baseUrl) !== 0) {
            return null;
        }

        $relativePath = ltrim(substr($normalizedUrl, strlen($baseUrl)), '/');
        $filePath = wp_normalize_path(trailingslashit($baseDir) . $relativePath);
        $normalizedBaseDir = wp_normalize_path($baseDir);

        if (strpos($filePath, $normalizedBaseDir) !== 0 || !is_readable($filePath)) {
            return null;
        }

        return $filePath;
    }

    private static function isSupportedReferencedFilePath(string $filePath): bool {
        $fileType = wp_check_filetype(basename($filePath));
        $extension = isset($fileType['ext']) ? (string) $fileType['ext'] : '';
        $typeGroup = $extension !== '' ? wp_ext2type($extension) : false;

        return !in_array($typeGroup, ['image', 'audio', 'video'], true);
    }
}
