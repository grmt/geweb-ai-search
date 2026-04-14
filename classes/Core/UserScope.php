<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves durable user and group scope identifiers for plugin-owned data.
 */
class UserScope {
    private const COOKIE_NAME = 'geweb_ai_scope';
    private const COOKIE_TTL = YEAR_IN_SECONDS;
    private static ?string $groupScopeOverride = null;

    public static function getCurrentUserScopeKey(): string {
        $userId = get_current_user_id();
        if ($userId > 0) {
            return 'user_' . $userId;
        }

        return 'guest_' . self::getGuestScopeId();
    }

    public static function getCurrentGroupScopeKey(): string {
        if (is_string(self::$groupScopeOverride) && self::$groupScopeOverride !== '') {
            return self::$groupScopeOverride;
        }

        if (!is_user_logged_in()) {
            return 'group_guests';
        }

        if (current_user_can('manage_options')) {
            return 'group_administrators';
        }

        if (current_user_can('edit_others_posts') || current_user_can('publish_posts') || current_user_can('edit_posts')) {
            return 'group_content_creators';
        }

        if (current_user_can('read')) {
            return 'group_readers';
        }

        return 'group_authenticated';
    }

    public static function getCurrentScopeKey(): string {
        return self::getCurrentGroupScopeKey();
    }

    public static function getOptionNameForScope(string $baseOptionName, string $scopeKey): string {
        return self::buildScopedOptionName($baseOptionName, $scopeKey);
    }

    public static function getUserScopedOptionName(string $baseOptionName): string {
        return self::buildScopedOptionName($baseOptionName, self::getCurrentUserScopeKey());
    }

    public static function getGroupScopedOptionName(string $baseOptionName): string {
        return self::buildScopedOptionName($baseOptionName, self::getCurrentGroupScopeKey());
    }

    public static function getScopedOptionName(string $baseOptionName): string {
        return self::getGroupScopedOptionName($baseOptionName);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getUserScopedOption(string $baseOptionName, $default = false) {
        return get_option(self::getUserScopedOptionName($baseOptionName), $default);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getGroupScopedOption(string $baseOptionName, $default = false) {
        return get_option(self::getGroupScopedOptionName($baseOptionName), $default);
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getScopedOption(string $baseOptionName, $default = false) {
        return self::getGroupScopedOption($baseOptionName, $default);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function updateUserScopedOption(string $baseOptionName, $value, bool $autoload = false): bool {
        return update_option(self::getUserScopedOptionName($baseOptionName), $value, $autoload);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function updateGroupScopedOption(string $baseOptionName, $value, bool $autoload = false): bool {
        return update_option(self::getGroupScopedOptionName($baseOptionName), $value, $autoload);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function updateScopedOption(string $baseOptionName, $value, bool $autoload = false): bool {
        return self::updateGroupScopedOption($baseOptionName, $value, $autoload);
    }

    public static function deleteUserScopedOption(string $baseOptionName): void {
        delete_option(self::getUserScopedOptionName($baseOptionName));
    }

    public static function deleteGroupScopedOption(string $baseOptionName): void {
        delete_option(self::getGroupScopedOptionName($baseOptionName));
    }

    public static function deleteScopedOption(string $baseOptionName): void {
        self::deleteGroupScopedOption($baseOptionName);
    }

    public static function getCurrentUserScopeStorageKey(): string {
        return self::getUserScopedOptionName('scope');
    }

    public static function getCurrentScopeStorageKey(): string {
        return self::getCurrentUserScopeStorageKey();
    }

    /**
     * @param callable $callback
     * @return mixed
     */
    public static function withGroupScopeOverride(string $scopeKey, callable $callback) {
        $normalizedScopeKey = trim($scopeKey);
        if ($normalizedScopeKey === '') {
            return $callback();
        }

        $previousScopeKey = self::$groupScopeOverride;
        self::$groupScopeOverride = $normalizedScopeKey;

        try {
            return $callback();
        } finally {
            self::$groupScopeOverride = $previousScopeKey;
        }
    }

    private static function buildScopedOptionName(string $baseOptionName, string $scopeKey): string {
        $suffix = preg_replace('/[^a-zA-Z0-9_]/', '_', $scopeKey);
        $suffix = is_string($suffix) ? trim($suffix, '_') : '';

        return $suffix !== ''
            ? $baseOptionName . '__' . $suffix
            : $baseOptionName;
    }

    private static function getGuestScopeId(): string {
        $cookieValue = self::readGuestScopeCookie();
        if ($cookieValue !== '') {
            return $cookieValue;
        }

        $generated = wp_generate_password(20, false, false);
        $generated = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $generated);
        $generated = is_string($generated) ? $generated : '';
        if ($generated === '') {
            $generated = md5(uniqid('geweb_ai_scope', true));
        }

        self::persistGuestScopeCookie($generated);

        return $generated;
    }

    private static function readGuestScopeCookie(): string {
        $rawValue = isset($_COOKIE[self::COOKIE_NAME]) ? wp_unslash($_COOKIE[self::COOKIE_NAME]) : '';
        $value = is_string($rawValue) ? trim($rawValue) : '';
        if ($value === '' || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $value)) {
            return '';
        }

        return $value;
    }

    private static function persistGuestScopeCookie(string $value): void {
        if ($value === '') {
            return;
        }

        $_COOKIE[self::COOKIE_NAME] = $value;

        if (headers_sent()) {
            return;
        }

        $expires = time() + self::COOKIE_TTL;
        $path = defined('COOKIEPATH') && is_string(COOKIEPATH) && COOKIEPATH !== '' ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '';
        $secure = is_ssl();

        if (PHP_VERSION_ID >= 70300) {
            setcookie(self::COOKIE_NAME, $value, [
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            return;
        }

        setcookie(self::COOKIE_NAME, $value, $expires, $path . '; samesite=Lax', $domain, $secure, false);
    }
}
