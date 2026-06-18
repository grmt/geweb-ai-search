<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class GroupDataRevision {
    private const OPTION_GROUP_DATA_REVISION = 'geweb_aisearch_group_data_revision';

    public static function getCurrentRevision(): string {
        $revision = UserScope::getGroupScopedOption(self::OPTION_GROUP_DATA_REVISION, '');
        return is_string($revision) ? trim($revision) : '';
    }

    public static function ensureCurrentRevision(): string {
        $revision = self::getCurrentRevision();
        if ($revision !== '') {
            return $revision;
        }

        return self::touch();
    }

    public static function touch(): string {
        $revision = wp_generate_uuid4();
        UserScope::updateGroupScopedOption(self::OPTION_GROUP_DATA_REVISION, $revision, false);
        return $revision;
    }

    public static function extractExpectedRevisionFromRequest(string $fieldName = 'group_revision'): string {
        $value = isset($_POST[$fieldName]) ? wp_unslash($_POST[$fieldName]) : '';
        return is_string($value) ? trim(sanitize_text_field($value)) : '';
    }

    public static function assertExpectedRevision(
        string $expectedRevision,
        string $message = 'Someone else in your group changed shared AI settings. Reload the page and try again.'
    ): void {
        $currentRevision = self::ensureCurrentRevision();
        if ($expectedRevision !== '' && hash_equals($currentRevision, $expectedRevision)) {
            return;
        }

        throw new OptimisticLockException($message, $currentRevision);
    }
}
