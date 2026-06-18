<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Resolves the currently configured AI provider.
 */
class ProviderFactory {
    public const OPTION_PROVIDER = 'geweb_aisearch_provider';
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_CHATGPT = 'chatgpt';

    /**
     * @return AIProviderInterface
     */
    public static function make(): AIProviderInterface {
        return new Gemini();
    }

    /**
     * @return string
     */
    public static function getConfiguredProviderKey(): string {
        $provider = (string) get_option(self::OPTION_PROVIDER, self::PROVIDER_GEMINI);

        if (!in_array($provider, [self::PROVIDER_GEMINI], true)) {
            return self::PROVIDER_GEMINI;
        }

        return $provider;
    }

    /**
     * @return array<string,string>
     */
    public static function getAvailableProviders(): array {
        return [
            self::PROVIDER_GEMINI => 'Google Gemini',
        ];
    }
}
