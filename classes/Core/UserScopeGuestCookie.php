<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves and persists the anonymous guest scope cookie.
 */
class UserScopeGuestCookie {
    public static function resolve(string $cookieName, int $ttlSeconds): string {
        $cookieValue = self::read($cookieName);
        if ($cookieValue !== '') {
            return $cookieValue;
        }

        $generated = wp_generate_password(20, false, false);
        $generated = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $generated);
        $generated = is_string($generated) ? $generated : '';
        if ($generated === '') {
            $generated = md5(uniqid('geweb_ai_scope', true));
        }

        self::persist($cookieName, $generated, $ttlSeconds);

        return $generated;
    }

    private static function read(string $cookieName): string {
        $rawValue = isset($_COOKIE[$cookieName]) ? wp_unslash($_COOKIE[$cookieName]) : '';
        $value = is_string($rawValue) ? trim($rawValue) : '';
        if ($value === '' || !preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $value)) {
            return '';
        }

        return $value;
    }

    private static function persist(string $cookieName, string $value, int $ttlSeconds): void {
        if ($value === '') {
            return;
        }

        $_COOKIE[$cookieName] = $value;

        if (headers_sent()) {
            return;
        }

        $expires = time() + $ttlSeconds;
        $path = defined('COOKIEPATH') && is_string(COOKIEPATH) && COOKIEPATH !== '' ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') ? (string) COOKIE_DOMAIN : '';
        $secure = is_ssl();

        if (PHP_VERSION_ID >= 70300) {
            setcookie($cookieName, $value, [
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
            return;
        }

        setcookie($cookieName, $value, $expires, $path . '; samesite=Lax', $domain, $secure, false);
    }
}
