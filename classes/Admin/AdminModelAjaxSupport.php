<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminModelAjaxSupport {
    /**
     * @param array<int,string> $models
     * @param array<string,mixed> $modelStatuses
     */
    public static function pickLatestWorkingModelByFamily(array $models, array $modelStatuses, string $family): string {
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

        return self::pickLatestModelByFamily($workingModels, $family);
    }

    /**
     * @param array<int,string> $models
     */
    public static function pickLatestModelByFamily(array $models, string $family, bool $stableOnly = false): string {
        $bestModel = '';
        $bestRank = null;

        foreach ($models as $model) {
            $normalizedModel = strtolower(trim((string) $model));
            if (!self::isModelInFamily($normalizedModel, $family, $stableOnly)) {
                continue;
            }

            $rank = self::rankModelName($normalizedModel);
            if ($bestRank === null || $rank > $bestRank) {
                $bestRank = $rank;
                $bestModel = (string) $model;
            }
        }

        return $bestModel;
    }

    /**
     * @param array<string,mixed> $connectionStatus
     */
    public static function shouldForceModelRefresh(array $connectionStatus, int $cooldownSeconds): bool {
        $timestamp = isset($connectionStatus['timestamp']) ? (int) $connectionStatus['timestamp'] : 0;
        if ($timestamp <= 0) {
            return true;
        }

        return (current_time('timestamp') - $timestamp) >= $cooldownSeconds;
    }

    private static function isModelInFamily(string $model, string $family, bool $stableOnly): bool {
        if ($model === '' || ($stableOnly && str_contains($model, 'preview'))) {
            return false;
        }

        if ($family === 'flash') {
            return str_contains($model, '-flash') && !str_contains($model, 'flash-lite');
        }

        return $family === 'pro' && str_contains($model, '-pro');
    }

    /**
     * @return array<int,int>
     */
    private static function rankModelName(string $model): array {
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
