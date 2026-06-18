<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves cache-busting versions for plugin assets based on file mtimes.
 */
class AssetVersion {
    /**
     * @param string $relativePath
     * @return string
     */
    public static function forRelativePath(string $relativePath): string {
        $normalizedPath = ltrim($relativePath, '/');
        $absolutePath = GEWEB_AI_SEARCH_PATH . $normalizedPath;

        if (!file_exists($absolutePath)) {
            return defined('GEWEB_AI_SEARCH_VERSION') ? (string) GEWEB_AI_SEARCH_VERSION : '1';
        }

        $mtime = filemtime($absolutePath);
        if ($mtime === false) {
            return defined('GEWEB_AI_SEARCH_VERSION') ? (string) GEWEB_AI_SEARCH_VERSION : '1';
        }

        return (string) $mtime;
    }
}
