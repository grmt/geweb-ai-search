<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class DateDisplay {
    public const OPTION_DATE_DISPLAY_FORMAT = 'geweb_aisearch_date_display_format';
    public const FORMAT_ISO = 'iso';
    public const FORMAT_SITE = 'site';

    public static function getDateDisplayFormat(): string {
        $userSetting = self::normalizeFormat((string) UserScope::getUserScopedOption(self::OPTION_DATE_DISPLAY_FORMAT, ''));
        if ($userSetting !== '') {
            return $userSetting;
        }

        $groupSetting = self::normalizeFormat((string) UserScope::getGroupScopedOption(self::OPTION_DATE_DISPLAY_FORMAT, self::FORMAT_ISO));
        if ($groupSetting !== '') {
            return $groupSetting;
        }

        return self::FORMAT_ISO;
    }

    public static function formatDate(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }

        $format = self::getDateDisplayFormat();
        if ($format === self::FORMAT_SITE) {
            return wp_date((string) get_option('date_format'), $timestamp);
        }

        return wp_date('Y-m-d', $timestamp);
    }

    public static function formatDateTime(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }

        $format = self::getDateDisplayFormat();
        if ($format === self::FORMAT_SITE) {
            return wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $timestamp);
        }

        return wp_date('Y-m-d H:i:s', $timestamp);
    }

    public static function getAvailableFormats(): array {
        return [
            self::FORMAT_ISO => 'yyyy-mm-dd',
            self::FORMAT_SITE => 'WordPress site format',
        ];
    }

    public static function normalizeFormat(string $format): string {
        return in_array($format, [self::FORMAT_ISO, self::FORMAT_SITE], true) ? $format : '';
    }
}
