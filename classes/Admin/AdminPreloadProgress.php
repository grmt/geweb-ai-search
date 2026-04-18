<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminPreloadProgress {
    private const TRANSIENT_PREFIX = 'geweb_admin_preload_';
    private const TTL_SECONDS = 15 * MINUTE_IN_SECONDS;

    /**
     * @param array<string,string> $steps
     * @return string
     */
    public static function start(array $steps): string {
        $jobId = wp_generate_uuid4();
        $normalizedSteps = [];
        foreach ($steps as $key => $label) {
            $stepKey = sanitize_key((string) $key);
            if ($stepKey === '') {
                continue;
            }

            $normalizedSteps[$stepKey] = [
                'label' => trim((string) $label),
                'status' => 'pending',
                'message' => '',
                'started_at' => 0,
                'completed_at' => 0,
            ];
        }

        self::writeJob($jobId, [
            'user_id' => get_current_user_id(),
            'created_at' => time(),
            'updated_at' => time(),
            'steps' => $normalizedSteps,
        ]);

        return $jobId;
    }

    public static function markRunning(string $jobId, string $step, string $message = ''): void {
        self::updateStep($jobId, $step, 'running', $message);
    }

    public static function markCompleted(string $jobId, string $step, string $message = ''): void {
        self::updateStep($jobId, $step, 'completed', $message);
    }

    public static function markFailed(string $jobId, string $step, string $message = ''): void {
        self::updateStep($jobId, $step, 'failed', $message);
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function get(string $jobId): ?array {
        if ($jobId === '') {
            return null;
        }

        $job = get_transient(self::TRANSIENT_PREFIX . $jobId);
        return is_array($job) ? $job : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function getSummary(string $jobId): ?array {
        $job = self::get($jobId);
        if (!is_array($job)) {
            return null;
        }

        $steps = is_array($job['steps'] ?? null) ? $job['steps'] : [];
        $totalSteps = count($steps);
        $completedSteps = 0;
        $failedSteps = 0;
        $currentStepKey = '';
        $currentStepLabel = '';
        $currentStepMessage = '';

        foreach ($steps as $stepKey => $step) {
            if (!is_array($step)) {
                continue;
            }

            $status = (string) ($step['status'] ?? 'pending');
            if ($status === 'completed') {
                $completedSteps++;
                continue;
            }

            if ($status === 'failed') {
                $failedSteps++;
                if ($currentStepKey === '') {
                    $currentStepKey = (string) $stepKey;
                    $currentStepLabel = (string) ($step['label'] ?? $stepKey);
                    $currentStepMessage = (string) ($step['message'] ?? '');
                }
                continue;
            }

            if ($currentStepKey === '') {
                $currentStepKey = (string) $stepKey;
                $currentStepLabel = (string) ($step['label'] ?? $stepKey);
                $currentStepMessage = (string) ($step['message'] ?? '');
            }
        }

        $percent = $totalSteps > 0 ? (int) floor((($completedSteps + $failedSteps) / $totalSteps) * 100) : 100;

        return [
            'job_id' => $jobId,
            'total_steps' => $totalSteps,
            'completed_steps' => $completedSteps,
            'failed_steps' => $failedSteps,
            'current_step' => $currentStepKey,
            'current_label' => $currentStepLabel,
            'current_message' => $currentStepMessage,
            'percent' => max(0, min(100, $percent)),
            'finished' => $totalSteps > 0 && ($completedSteps + $failedSteps) >= $totalSteps,
            'steps' => $steps,
            'updated_at' => (int) ($job['updated_at'] ?? 0),
        ];
    }

    private static function updateStep(string $jobId, string $step, string $status, string $message): void {
        $job = self::get($jobId);
        if (!is_array($job)) {
            return;
        }

        $stepKey = sanitize_key($step);
        if ($stepKey === '' || !isset($job['steps'][$stepKey]) || !is_array($job['steps'][$stepKey])) {
            return;
        }

        $job['steps'][$stepKey]['status'] = $status;
        $job['steps'][$stepKey]['message'] = $message;
        if ($status === 'running' && empty($job['steps'][$stepKey]['started_at'])) {
            $job['steps'][$stepKey]['started_at'] = time();
        }
        if ($status === 'completed' || $status === 'failed') {
            $job['steps'][$stepKey]['completed_at'] = time();
        }
        $job['updated_at'] = time();

        self::writeJob($jobId, $job);
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function writeJob(string $jobId, array $job): void {
        set_transient(self::TRANSIENT_PREFIX . $jobId, $job, self::TTL_SECONDS);
    }
}
