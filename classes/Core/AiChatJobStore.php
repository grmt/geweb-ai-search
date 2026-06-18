<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AiChatJobStore {
    private const TRANSIENT_PREFIX = 'geweb_ai_chat_job_';
    private const TTL = DAY_IN_SECONDS;

    public function read(string $jobId): ?array {
        $job = get_transient($this->getTransientKey($jobId));
        return is_array($job) ? $job : null;
    }

    public function write(array $job): void {
        $jobId = isset($job['id']) ? (string) $job['id'] : '';
        if ($jobId === '') {
            return;
        }

        set_transient($this->getTransientKey($jobId), $job, self::TTL);
    }

    private function getTransientKey(string $jobId): string {
        return self::TRANSIENT_PREFIX . preg_replace('/[^a-zA-Z0-9_-]/', '', $jobId);
    }
}
