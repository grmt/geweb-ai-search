<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Builds Gemini model hint metadata for the admin page.
 */
class AdminPageModelHintsBuilder {
    private const OFFICIAL_GEMINI_FLASH_LATEST = 'gemini-3.5-flash';
    private const OFFICIAL_GEMINI_PRO_LATEST = 'gemini-3.1-pro-preview';

    /**
     * @return array<string,string>
     */
    public function buildOfficialLatestAliases(): array {
        return [
            'flash_latest' => self::OFFICIAL_GEMINI_FLASH_LATEST,
            'pro_latest' => self::OFFICIAL_GEMINI_PRO_LATEST,
        ];
    }

    /**
     * @param array<int,string> $models
     * @return array<string,string>
     */
    public function buildLatestModelHints(array $models): array {
        return [
            'flash' => $this->pickLatestModelByFamily($models, 'flash'),
            'pro' => $this->pickLatestModelByFamily($models, 'pro'),
            'stable_flash' => $this->pickLatestModelByFamily($models, 'flash', true),
            'stable_pro' => $this->pickLatestModelByFamily($models, 'pro', true),
        ];
    }

    /**
     * @param array<int,string> $models
     * @param array<string,mixed> $modelStatuses
     * @return array<string,string>
     */
    public function buildWorkingModelHints(array $models, array $modelStatuses): array {
        return [
            'flash' => $this->pickLatestWorkingModelByFamily($models, $modelStatuses, 'flash'),
            'pro' => $this->pickLatestWorkingModelByFamily($models, $modelStatuses, 'pro'),
        ];
    }

    /**
     * @param array<int,string> $models
     * @param array<string,mixed> $modelStatuses
     */
    private function pickLatestWorkingModelByFamily(array $models, array $modelStatuses, string $family): string {
        $workingModels = [];
        foreach ($models as $model) {
            $status = $modelStatuses[(string) $model] ?? null;
            if (!is_array($status) || (($status['status'] ?? '') !== 'ok')) {
                continue;
            }

            $resolvedModel = trim((string) ($status['resolved_model'] ?? ''));
            $workingModels[] = $resolvedModel !== '' ? $resolvedModel : (string) $model;
        }

        $workingModels = array_values(array_unique(array_filter($workingModels, static function ($model): bool {
            return is_string($model) && trim($model) !== '';
        })));

        return $this->pickLatestModelByFamily($workingModels, $family);
    }

    /**
     * @param array<int,string> $models
     */
    private function pickLatestModelByFamily(array $models, string $family, bool $stableOnly = false): string {
        $bestModel = '';
        $bestRank = null;

        foreach ($models as $model) {
            $normalizedModel = strtolower(trim((string) $model));
            if (!$this->modelMatchesFamily($normalizedModel, $family, $stableOnly)) {
                continue;
            }

            $rank = $this->rankModelName($normalizedModel);
            if ($bestRank === null || $rank > $bestRank) {
                $bestRank = $rank;
                $bestModel = (string) $model;
            }
        }

        return $bestModel;
    }

    private function modelMatchesFamily(string $normalizedModel, string $family, bool $stableOnly): bool {
        if ($normalizedModel === '' || ($stableOnly && str_contains($normalizedModel, 'preview'))) {
            return false;
        }

        if ($family === 'flash') {
            return str_contains($normalizedModel, '-flash') && !str_contains($normalizedModel, 'flash-lite');
        }

        return $family === 'pro' && str_contains($normalizedModel, '-pro');
    }

    /**
     * @return array<int,int>
     */
    private function rankModelName(string $model): array {
        $major = 0;
        $minor = 0;
        if (preg_match('/gemini-(\d+)(?:\.(\d+))?/i', $model, $matches)) {
            $major = isset($matches[1]) ? (int) $matches[1] : 0;
            $minor = isset($matches[2]) ? (int) $matches[2] : 0;
        }

        return [
            $major,
            $minor,
            str_contains($model, 'preview') ? 0 : 1,
            str_contains($model, 'lite') ? 0 : 1,
        ];
    }
}
