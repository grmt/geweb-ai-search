<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class FrontendAiAccess {
    public const OPTION_SHARE_GEMINI_CONFIG_WITH_FRONTEND = 'geweb_aisearch_share_gemini_config_with_frontend';

    public static function isSharedGeminiConfigEnabled(): bool {
        return UserScope::getWorkspaceConfigOption(self::OPTION_SHARE_GEMINI_CONFIG_WITH_FRONTEND, '0') === '1';
    }

    public static function currentUserCanUseGeminiFrontend(): bool {
        return current_user_can('manage_options') || self::isSharedGeminiConfigEnabled();
    }

    public static function getFrontendDisabledMessage(): string {
        return __('AI search is not enabled for this account. Ask an administrator to share the Gemini search configuration for frontend users.', 'geweb-ai-search');
    }
}
