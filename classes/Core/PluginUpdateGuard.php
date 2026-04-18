<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class PluginUpdateGuard {
    private const OPTION_VERSION = 'geweb_aisearch_runtime_version';
    private const OPTION_GUARD_UNTIL = 'geweb_aisearch_update_guard_until';
    private const DEFAULT_GUARD_SECONDS = 90;

    public static function boot(): void {
        $currentVersion = defined('GEWEB_AI_SEARCH_VERSION') ? (string) GEWEB_AI_SEARCH_VERSION : '';
        if ($currentVersion === '') {
            return;
        }

        $storedVersion = (string) get_option(self::OPTION_VERSION, '');
        if ($storedVersion === $currentVersion) {
            return;
        }

        update_option(self::OPTION_VERSION, $currentVersion, false);
        update_option(self::OPTION_GUARD_UNTIL, time() + self::getGuardSeconds(), false);
    }

    public static function isActive(): bool {
        return self::getGuardUntil() > time();
    }

    public static function getGuardUntil(): int {
        return (int) get_option(self::OPTION_GUARD_UNTIL, 0);
    }

    public static function getRetryAfterSeconds(): int {
        return max(1, self::getGuardUntil() - time());
    }

    public static function getNoticeMessage(): string {
        return 'Workspace AI Search is updating. Expensive syncs and write actions are temporarily paused while the new version settles.';
    }

    /**
     * @return array<string,mixed>
     */
    public static function buildJsonErrorPayload(?string $message = null): array {
        return [
            'message' => $message !== null && trim($message) !== '' ? trim($message) : self::getNoticeMessage(),
            'plugin_updating' => true,
            'retry_after' => self::getRetryAfterSeconds(),
        ];
    }

    private static function getGuardSeconds(): int {
        $seconds = (int) apply_filters('geweb_aisearch_update_guard_seconds', self::DEFAULT_GUARD_SECONDS);
        return max(15, $seconds);
    }
}
