<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Small scoped refresh locks to reduce duplicate cache rebuilds across
 * concurrent admin requests.
 */
class SharedRefreshLock {
    private const OPTION_PREFIX = 'geweb_aisearch_refresh_lock_';

    public static function acquireGroup(string $key, int $ttlSeconds = 30): ?string {
        $normalizedKey = self::normalizeKey($key);
        if ($normalizedKey === '') {
            return null;
        }

        $token = wp_generate_uuid4();
        $expiresAt = time() + max(5, $ttlSeconds);
        $optionName = self::OPTION_PREFIX . $normalizedKey;
        $existing = UserScope::getGroupScopedOption($optionName, null);

        if (is_array($existing) && ((int) ($existing['expires_at'] ?? 0)) > time()) {
            return null;
        }

        UserScope::updateGroupScopedOption($optionName, [
            'token' => $token,
            'expires_at' => $expiresAt,
        ], false);

        $confirmed = UserScope::getGroupScopedOption($optionName, null);
        if (!is_array($confirmed)) {
            return null;
        }

        return hash_equals((string) ($confirmed['token'] ?? ''), $token) ? $token : null;
    }

    public static function releaseGroup(string $key, string $token): void {
        $normalizedKey = self::normalizeKey($key);
        if ($normalizedKey === '' || trim($token) === '') {
            return;
        }

        $optionName = self::OPTION_PREFIX . $normalizedKey;
        $current = UserScope::getGroupScopedOption($optionName, null);
        if (!is_array($current)) {
            return;
        }

        if (!hash_equals((string) ($current['token'] ?? ''), $token)) {
            return;
        }

        UserScope::deleteGroupScopedOption($optionName);
    }

    /**
     * @param callable():mixed $resolver
     * @return mixed|null
     */
    public static function waitFor(callable $resolver, int $timeoutMs = 8000, int $pollIntervalMs = 250) {
        $deadline = microtime(true) + (max(0, $timeoutMs) / 1000);
        $intervalUs = max(50, $pollIntervalMs) * 1000;

        do {
            $result = $resolver();
            if ($result !== null) {
                return $result;
            }

            usleep($intervalUs);
        } while (microtime(true) < $deadline);

        return $resolver();
    }

    private static function normalizeKey(string $key): string {
        $normalized = strtolower(trim($key));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized);
        return is_string($normalized) ? trim($normalized, '_') : '';
    }
}
