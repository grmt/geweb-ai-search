<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Tracks per-view revisions so a browser session can detect when its rendered
 * admin data is stale compared with the latest server-side state.
 */
class AdminViewRevision {
    private const OPTION_PROMPTS_REVISION = 'geweb_aisearch_admin_prompts_revision';
    private const OPTION_FILES_REVISION = 'geweb_aisearch_admin_files_revision';
    private const OPTION_CHATS_REVISION = 'geweb_aisearch_admin_chats_revision';

    /**
     * @return array{prompts:string,files:string,chats:string}
     */
    public static function ensureCurrentState(): array {
        return [
            'prompts' => self::ensureGroupRevision(self::OPTION_PROMPTS_REVISION),
            'files' => self::ensureGroupRevision(self::OPTION_FILES_REVISION),
            'chats' => self::ensureUserRevision(self::OPTION_CHATS_REVISION),
        ];
    }

    public static function touchPrompts(): string {
        return self::touchGroupRevision(self::OPTION_PROMPTS_REVISION);
    }

    public static function touchFiles(): string {
        return self::touchGroupRevision(self::OPTION_FILES_REVISION);
    }

    public static function touchChats(): string {
        return self::touchUserRevision(self::OPTION_CHATS_REVISION);
    }

    private static function ensureGroupRevision(string $optionName): string {
        $current = (string) UserScope::getGroupScopedOption($optionName, '');
        if ($current !== '') {
            return $current;
        }

        $current = wp_generate_uuid4();
        UserScope::updateGroupScopedOption($optionName, $current, false);

        return $current;
    }

    private static function ensureUserRevision(string $optionName): string {
        $current = (string) UserScope::getUserScopedOption($optionName, '');
        if ($current !== '') {
            return $current;
        }

        $current = wp_generate_uuid4();
        UserScope::updateUserScopedOption($optionName, $current, false);

        return $current;
    }

    private static function touchGroupRevision(string $optionName): string {
        $revision = wp_generate_uuid4();
        UserScope::updateGroupScopedOption($optionName, $revision, false);

        return $revision;
    }

    private static function touchUserRevision(string $optionName): string {
        $revision = wp_generate_uuid4();
        UserScope::updateUserScopedOption($optionName, $revision, false);

        return $revision;
    }
}
