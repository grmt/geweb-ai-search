<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Provides a stable per-user or per-browser scope key for user-bound plugin state.
 */
class UserScope {
    private const COOKIE_NAME = 'geweb_ai_scope';
    private const COOKIE_TTL = YEAR_IN_SECONDS;

    public static function getCurrentScopeKey(): string {
        $userId = get_current_user_id();
        if ($userId > 0) {
            return 'user_' . $userId;
        }

        $cookieValue = self::readGuestScopeCookie();
        if ($cookieValue !== '') {
            return 'guest_' . $cookieValue;
        }

        $generated = wp_generate_password(20, false, false);
        $generated = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $generated);
        $generated = is_string($generated) ? $generated : '';
        if ($generated === '') {
            $generated = md5(uniqid('geweb_ai_scope', true));
        }

        self::persistGuestScopeCookie($generated);

        return 'guest_' . $generated;
    }

    public static function getScopedOptionName(string $baseOptionName): string {
        $suffix = preg_replace('/[^a-zA-Z0-9_]/', '_', self::getCurrentScopeKey());
        $suffix = is_string($suffix) ? trim($suffix, '_') : '';

        return $suffix !== ''
            ? $baseOptionName . '__' . $suffix
            : $baseOptionName;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getScopedOption(string $baseOptionName, $default = false) {
        return get_option(self::getScopedOptionName($baseOptionName), $default);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    public static function updateScopedOption(string $baseOptionName, $value, bool $autoload = false): bool {
        return update_option(self::getScopedOptionName($baseOptionName), $value, $autoload);
    }

    public static function deleteScopedOption(string $baseOptionName): void {
        delete_option(self::getScopedOptionName($baseOptionName));
    }

    public static function getCurrentScopeStorageKey(): string {
        return self::getScopedOptionName('scope');
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
