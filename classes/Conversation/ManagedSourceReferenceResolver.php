<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class ManagedSourceReferenceResolver {
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

        $postId = $this->extractPostIdFromManagedUrl($normalizedUrl);
        if ($postId > 0) {
            $permalink = get_permalink($postId);
            if (is_string($permalink) && $permalink !== '') {
                $canonicalUrl = $permalink;
                $label = $this->formatManagedSourcePath($permalink);
            }

            $postTitle = get_the_title($postId);
            if (is_string($postTitle) && trim($postTitle) !== '') {
                $title = trim($postTitle);
            }

            $postLabel = $this->buildManagedSourceLabelFromPost($postId, $canonicalUrl, $title);
            if ($postLabel !== '') {
                $label = $postLabel;
            }
        }

        if ($label === '') {
            $label = $title !== '' ? $title : $normalizedUrl;
        }

        return [
            'url' => $canonicalUrl,
            'label' => $label,
            'title' => $title,
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
        if ($label === '') {
            $post = get_post($postId);
            if ($post instanceof \WP_Post) {
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
        if ($path !== '') {
            $formattedPath = trailingslashit($path);
        }

        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        if ($formattedPath === '') {
            if (!empty($query['page_id'])) {
                $formattedPath = 'page ' . (int) $query['page_id'];
            } elseif (!empty($query['p'])) {
                $formattedPath = 'post ' . (int) $query['p'];
            }
        }

        return $formattedPath;
    }
}
