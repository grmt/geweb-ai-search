<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class ManagedSourceReferenceResolver {
    private const META_MARKDOWN_BYTES = 'geweb_aisearch_markdown_bytes';

    /**
     * @param string $url
     * @return array<string,string>
     */
    public function resolve(string $url): array {
        $normalizedUrl = $this->normalizeManagedSourceUrl($url);
        if ($normalizedUrl === '') {
            return [];
        }

        $canonicalUrl = $normalizedUrl;
        $label = $this->formatManagedSourcePath($normalizedUrl);
        $title = '';
        $sizeBytes = 0;

        $postId = $this->extractPostIdFromManagedUrl($normalizedUrl);
        if ($postId > 0) {
            $permalink = get_permalink($postId);
            if (is_string($permalink) && $permalink !== '') {
                $canonicalUrl = $permalink;
            }

            $postTitle = get_the_title($postId);
            if (is_string($postTitle) && trim($postTitle) !== '') {
                $title = trim($postTitle);
            }

            // Always try to get a better label from post data
            // This handles cases where URL only has query params like "page 272"
            $postLabel = $this->buildManagedSourceLabelFromPost($postId, $canonicalUrl, $title);
            if ($postLabel !== '') {
                $label = $postLabel;
            }

            $sizeBytes = $this->resolveManagedSourceSizeBytes($postId);
        }

        if ($label === '') {
            $label = $title !== '' ? $title : $normalizedUrl;
        }

        return [
            'url' => $canonicalUrl,
            'label' => $label,
            'title' => $title,
            'post_id' => $postId > 0 ? (string) $postId : '',
            'size_bytes' => $sizeBytes > 0 ? (string) $sizeBytes : '',
            'size_label' => $sizeBytes > 0 ? size_format($sizeBytes, 1) : '',
        ];
    }

    public function extractPostIdFromUrl(string $url): int {
        $normalizedUrl = $this->normalizeManagedSourceUrl($url);
        if ($normalizedUrl === '') {
            return 0;
        }

        return $this->extractPostIdFromManagedUrl($normalizedUrl);
    }

    private function buildManagedSourceLabelFromPost(int $postId, string $url, string $title): string {
        $label = $this->formatManagedSourcePath($url);

        // If we got "page X" or "post X" format, try to get something better from the permalink
        if (preg_match('/^(page|post)\s+\d+$/', $label)) {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
                // Try to extract path from the post's permalink
                $permalink = get_permalink($post);
                if (is_string($permalink) && $permalink !== '') {
                    $pathLabel = $this->formatManagedSourcePath($permalink);
                    if ($pathLabel !== '' && !preg_match('/^(page|post)\s+\d+$/', $pathLabel)) {
                        return $pathLabel;
                    }
                }
                // Fall back to post_name if permalink doesn't have a better path
                $postName = trim((string) $post->post_name);
                if ($postName !== '') {
                    return trailingslashit($postName);
                }
            }
        }

        if ($label === '') {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
                // Try to extract from permalink first
                $permalink = get_permalink($post);
                if (is_string($permalink) && $permalink !== '') {
                    $pathLabel = $this->formatManagedSourcePath($permalink);
                    if ($pathLabel !== '') {
                        return $pathLabel;
                    }
                }
                // Fall back to post_name
                $label = trim((string) $post->post_name);
            }
        }

        if ($label === '' && $title !== '') {
            $label = $title;
        }

        return $label;
    }

    private function normalizeManagedSourceUrl(string $url): string {
        $url = trim($url);
        $normalizedUrl = '';
        if ($url === '') {
            return $normalizedUrl;
        }

        $siteUrl = home_url('/');
        $siteParts = wp_parse_url($siteUrl);
        $candidate = wp_http_validate_url($url);

        if ($candidate === false && strpos($url, '/') === 0) {
            $candidate = home_url($url);
        }

        if ($candidate === false && !preg_match('/^https?:\/\//i', $url)) {
            $candidate = home_url('/' . $url);
        }

        if ($candidate !== false) {
            $candidateParts = wp_parse_url($candidate);
            if (
                !empty($siteParts['host']) &&
                !empty($candidateParts['host']) &&
                $this->normalizeHost((string) $siteParts['host']) === $this->normalizeHost((string) $candidateParts['host'])
            ) {
                $normalizedUrl = $candidate;
            }
        }

        return $normalizedUrl;
    }

    private function normalizeHost(string $host): string {
        return preg_replace('/^www\./i', '', trim(strtolower($host))) ?: '';
    }

    private function extractPostIdFromManagedUrl(string $url): int {
        $parts = wp_parse_url($url);
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        foreach (['page_id', 'p'] as $key) {
            if (!empty($query[$key])) {
                return (int) $query[$key];
            }
        }

        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($path !== '') {
            if (preg_match('/^(\d+)\.md$/i', $path, $matches)) {
                return (int) $matches[1];
            }
        }

        $postId = url_to_postid($url);
        return $postId > 0 ? $postId : 0;
    }

    private function formatManagedSourcePath(string $url): string {
        $parts = wp_parse_url($url);
        $formattedPath = '';
        if (!is_array($parts)) {
            return $formattedPath;
        }

        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        if ($path !== '' && $path !== 'index.php' && $path !== '/') {
            $formattedPath = trailingslashit($path);
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        // Prefer path if available, only fall back to query params if no path exists
        if ($formattedPath === '') {
            if (!empty($query['page_id'])) {
                $formattedPath = 'page ' . (int) $query['page_id'];
            } elseif (!empty($query['p'])) {
                $formattedPath = 'post ' . (int) $query['p'];
            }
        }

        return $formattedPath;
    }

    private function resolveManagedSourceSizeBytes(int $postId): int {
        $post = get_post($postId);
        if (!$post instanceof \WP_Post) {
            return 0;
        }

        if ($post->post_type === 'attachment') {
            $attachedFile = get_attached_file($postId);
            if (is_string($attachedFile) && $attachedFile !== '' && file_exists($attachedFile)) {
                $attachedSize = (int) (@filesize($attachedFile) ?: 0);
                if ($attachedSize > 0) {
                    return $attachedSize;
                }
            }
        }

        $markdownBytes = (int) get_post_meta($postId, self::META_MARKDOWN_BYTES, true);
        if ($markdownBytes > 0) {
            return $markdownBytes;
        }

        $content = (string) $post->post_content;
        return $content !== '' ? strlen($content) : 0;
    }
}
