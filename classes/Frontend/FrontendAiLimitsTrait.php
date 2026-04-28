<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait FrontendAiLimitsTrait {
    public static function getConversationTrimMessageLimit(): int {
        return self::sanitizePositiveIntOption(
            get_option(self::OPTION_CONVERSATION_TRIM_MESSAGE_LIMIT, self::DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT),
            self::DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT,
            2,
            200
        );
    }

    public static function getConversationTrimCharLimit(): int {
        return self::sanitizePositiveIntOption(
            get_option(self::OPTION_CONVERSATION_TRIM_CHAR_LIMIT, self::DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT),
            self::DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT,
            500,
            200000
        );
    }

    public static function getLocalConversationArchiveLimit(): int {
        return self::sanitizePositiveIntOption(
            get_option(self::OPTION_LOCAL_CONVERSATION_ARCHIVE_LIMIT, self::DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT),
            self::DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT,
            1,
            200
        );
    }

    public static function getStoredContextMessageLimit(): int {
        return self::sanitizePositiveIntOption(
            get_option(self::OPTION_STORED_CONTEXT_MESSAGE_LIMIT, self::DEFAULT_STORED_CONTEXT_MESSAGE_LIMIT),
            self::DEFAULT_STORED_CONTEXT_MESSAGE_LIMIT,
            10,
            500
        );
    }

    public static function getStoredContextCharLimit(): int {
        return self::sanitizePositiveIntOption(
            get_option(self::OPTION_STORED_CONTEXT_CHAR_LIMIT, self::DEFAULT_STORED_CONTEXT_CHAR_LIMIT),
            self::DEFAULT_STORED_CONTEXT_CHAR_LIMIT,
            5000,
            500000
        );
    }
}
