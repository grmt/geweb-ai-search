<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AiChatJobProcessor {
    private ConversationManager $conversationManager;
    private AiChatJobStore $jobStore;
    private AiChatContextRefiner $contextRefiner;
    private AiChatJobDebugMetaBuilder $debugMetaBuilder;

    public function __construct(ConversationManager $conversationManager, AiChatJobStore $jobStore) {
        $this->conversationManager = $conversationManager;
        $this->jobStore = $jobStore;
        $this->contextRefiner = new AiChatContextRefiner($conversationManager);
        $this->debugMetaBuilder = new AiChatJobDebugMetaBuilder();
    }

    public function process(string $jobId): void {
        $job = $this->jobStore->read($jobId);
        if ($job === null) {
            return;
        }

        $this->markJobRunning($job);
        $provider = null;
        $thoughtHistory = [];

        try {
            $provider = ProviderFactory::make();
            $this->runJob($job, $provider, $thoughtHistory);
        } catch (\Exception $e) {
            $this->markJobFailed($job, $e, $provider, $thoughtHistory);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $thoughtHistory
     */
    private function runJob(array &$job, AIProviderInterface $provider, array &$thoughtHistory): void {
        $conversationId = (string) ($job['conversation_id'] ?? '');
        $fullMessages = isset($job['messages']) && is_array($job['messages']) ? $job['messages'] : [];
        $selectedModel = (string) ($job['requested_model'] ?? '');
        $temporaryPrompt = (string) ($job['temporary_prompt'] ?? '');
        $excludedSources = isset($job['excluded_sources']) && is_array($job['excluded_sources']) ? $job['excluded_sources'] : [];

        $this->configureProvider($provider, $job, $conversationId, $thoughtHistory);
        $context = $this->prepareContext($job, $provider, $fullMessages, $selectedModel, $conversationId);
        $this->markJobWaitingForProvider($job, $context);

        $result = $provider->search(
            $context['messages'],
            $selectedModel,
            $temporaryPrompt !== '' ? $temporaryPrompt : null,
            $excludedSources
        );

        $result = $this->debugMetaBuilder->attach($result, $context, $selectedModel, $temporaryPrompt, $excludedSources, $thoughtHistory);
        $this->appendAiResponseToConversation($fullMessages, $result);
        $this->conversationManager->recordConversationUsage($conversationId, $fullMessages, $context['summary'], $result, $provider);
        $this->markJobCompleted($job, $result, $context);
    }

    private function markJobRunning(array &$job): void {
        $job['status'] = 'running';
        $job['updated_at'] = time();
        $this->updateProgress(
            $job,
            'starting',
            __('Preparing request', 'geweb-ai-search'),
            [
                __('Loading the saved conversation state.', 'geweb-ai-search'),
                __('Checking the selected model and request settings.', 'geweb-ai-search'),
            ]
        );
        $this->jobStore->write($job);
    }

    /**
     * @param array<int,array<string,mixed>> $thoughtHistory
     */
    private function configureProvider(AIProviderInterface $provider, array &$job, string $conversationId, array &$thoughtHistory): void {
        $requestId = 'chat-' . wp_generate_password(10, false, false);
        $requestStartedAt = microtime(true);

        if (method_exists($provider, 'setRuntimeLogContext')) {
            $provider->setRuntimeLogContext([
                'request_id' => $requestId,
                'conversation_id' => $conversationId !== '' ? $conversationId : 'pending',
                'ajax_action' => 'geweb_ai_chat_async',
            ]);
        }

        if (method_exists($provider, 'setStreamProgressCallback')) {
            $this->setStreamProgressCallback($provider, $job, $thoughtHistory, $requestStartedAt);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $thoughtHistory
     */
    private function setStreamProgressCallback(AIProviderInterface $provider, array &$job, array &$thoughtHistory, float $requestStartedAt): void {
        $lastProgressHash = '';
        $lastProgressWriteAt = 0.0;

        $provider->setStreamProgressCallback(function (array $progress) use (&$job, &$lastProgressHash, &$lastProgressWriteAt, &$thoughtHistory, $requestStartedAt): void {
            $thoughts = isset($progress['thoughts']) && is_array($progress['thoughts']) ? $progress['thoughts'] : [];
            $label = isset($progress['label']) ? (string) $progress['label'] : '';
            $stage = isset($progress['stage']) ? (string) $progress['stage'] : 'streaming';
            $signature = md5((string) wp_json_encode([
                'stage' => $stage,
                'label' => $label,
                'thoughts' => $thoughts,
            ]));
            $now = microtime(true);
            if ($signature === $lastProgressHash && ($now - $lastProgressWriteAt) < 0.8) {
                return;
            }

            $this->updateProgress($job, $stage, $label, $thoughts);
            $job['updated_at'] = time();
            $this->jobStore->write($job);
            $this->appendThoughtHistory($thoughtHistory, $stage, $label, $thoughts, $requestStartedAt);
            $lastProgressHash = $signature;
            $lastProgressWriteAt = $now;
        });
    }

    /**
     * @param array<int,array<string,mixed>> $thoughtHistory
     * @param array<int,string> $thoughts
     */
    private function appendThoughtHistory(array &$thoughtHistory, string $stage, string $label, array $thoughts, float $requestStartedAt): void {
        if (empty($thoughts)) {
            return;
        }

        $thoughtHistory[] = [
            'stage' => sanitize_key($stage),
            'label' => trim($label),
            'changed_at_ms' => (int) round(microtime(true) * 1000),
            'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
            'thoughts' => array_values($thoughts),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $fullMessages
     * @return array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool}
     */
    private function prepareContext(array &$job, AIProviderInterface $provider, array $fullMessages, string $selectedModel, string $conversationId): array {
        $this->updateProgress(
            $job,
            'context',
            __('Preparing context', 'geweb-ai-search'),
            [
                __('Reviewing the recent conversation for the next request.', 'geweb-ai-search'),
                __('Checking whether earlier messages need to be compacted.', 'geweb-ai-search'),
            ]
        );
        $this->jobStore->write($job);

        $context = $this->conversationManager->compactConversationForRequest($fullMessages);
        $context = $this->contextRefiner->refine($context, $fullMessages, $provider, $selectedModel, $conversationId);
        $this->conversationManager->saveFrontendConversation(
            $conversationId,
            $fullMessages,
            '',
            !empty($context['compacted']),
            (string) ($context['summary'] ?? '')
        );

        return $context;
    }

    /**
     * @param array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool} $context
     */
    private function markJobWaitingForProvider(array &$job, array $context): void {
        $this->updateProgress(
            $job,
            'search',
            __('Waiting for Gemini', 'geweb-ai-search'),
            $this->buildContextThoughts($context)
        );
        $this->jobStore->write($job);
    }

    /**
     * @param array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool} $context
     * @return array<int,string>
     */
    private function buildContextThoughts(array $context): array {
        $thoughts = [
            !empty($context['compacted'])
                ? __('Compacted older messages to keep the request focused.', 'geweb-ai-search')
                : __('Kept the full recent conversation context.', 'geweb-ai-search'),
        ];

        if (trim((string) ($context['summary'] ?? '')) !== '') {
            $thoughts[] = __('Attached the saved conversation summary for continuity.', 'geweb-ai-search');
        }

        $thoughts[] = __('Prepared the message bundle for Gemini search.', 'geweb-ai-search');
        return $thoughts;
    }

    /**
     * @param array<string,mixed> $result
     * @param array{messages:array<int,array<string,mixed>>,summary:string,compacted:bool} $context
     */
    private function markJobCompleted(array &$job, array $result, array $context): void {
        $result['context_compacted'] = !empty($context['compacted']);
        $result['context_summary'] = (string) ($context['summary'] ?? '');

        $job['status'] = 'completed';
        $job['updated_at'] = time();
        $job['result'] = $result;
        $job['error_message'] = '';
        $this->updateProgress(
            $job,
            'completed',
            __('Completed', 'geweb-ai-search'),
            [
                __('Gemini returned an answer and the response is ready to display.', 'geweb-ai-search'),
            ]
        );
        $this->jobStore->write($job);
    }

    /**
     * @param array<int,array<string,mixed>> $thoughtHistory
     */
    private function markJobFailed(array &$job, \Exception $exception, ?AIProviderInterface $provider, array $thoughtHistory = []): void {
        $job['status'] = 'error';
        $job['updated_at'] = time();
        $job['error_message'] = $exception->getMessage();
        $job['result'] = null;
        $job['error_meta'] = $this->buildErrorMeta($job, $exception, $provider, $thoughtHistory);
        $this->updateProgress(
            $job,
            'error',
            __('Request failed', 'geweb-ai-search'),
            [
                __('The request stopped before Gemini returned a complete answer.', 'geweb-ai-search'),
            ]
        );
        $this->jobStore->write($job);
    }

    /**
     * @param array<int,array<string,mixed>> $thoughtHistory
     * @return array<string,mixed>
     */
    private function buildErrorMeta(array $job, \Exception $exception, ?AIProviderInterface $provider, array $thoughtHistory = []): array {
        $meta = [
            'provider' => 'Google Gemini',
            'model' => (string) ($job['requested_model'] ?? ''),
            'request' => [
                'created_at' => (int) ($job['created_at'] ?? time()),
                'finished_at' => time(),
                'model' => (string) ($job['requested_model'] ?? ''),
                'temporary_prompt_active' => trim((string) ($job['temporary_prompt'] ?? '')) !== '',
                'excluded_source_count' => isset($job['excluded_sources']) && is_array($job['excluded_sources']) ? count($job['excluded_sources']) : 0,
            ],
            'error' => [
                'message' => $exception->getMessage(),
            ],
        ];

        $normalizedThoughtHistory = $this->normalizeThoughtHistory($thoughtHistory);
        if (!empty($normalizedThoughtHistory)) {
            $meta['thought_history'] = $normalizedThoughtHistory;
            $meta['request']['thought_history_updates'] = count($normalizedThoughtHistory);
        }

        $thoughts = $this->resolveErrorThoughts($normalizedThoughtHistory, $job);
        if (!empty($thoughts)) {
            $meta['thoughts'] = $thoughts;
        }

        if ($provider !== null && method_exists($provider, 'getLastRequestAttempts')) {
            $attempts = $provider->getLastRequestAttempts();
            if (is_array($attempts) && !empty($attempts)) {
                $meta['request_attempts'] = $attempts;
            }
        }

        return $meta;
    }

    /**
     * @param array<int,array<string,mixed>> $thoughtHistory
     * @return array<int,array<string,mixed>>
     */
    private function normalizeThoughtHistory(array $thoughtHistory): array {
        return array_values(array_filter($thoughtHistory, static function ($entry): bool {
            return is_array($entry) && !empty($entry['thoughts']) && is_array($entry['thoughts']);
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $thoughtHistory
     * @return array<int,string>
     */
    private function resolveErrorThoughts(array $thoughtHistory, array $job): array {
        $lastThoughts = [];
        if (!empty($thoughtHistory)) {
            $lastEntry = $thoughtHistory[count($thoughtHistory) - 1] ?? null;
            if (is_array($lastEntry) && isset($lastEntry['thoughts']) && is_array($lastEntry['thoughts'])) {
                $lastThoughts = $this->normalizeThoughts($lastEntry['thoughts']);
            }
        }

        if (!empty($lastThoughts)) {
            return $lastThoughts;
        }

        $progress = isset($job['progress']) && is_array($job['progress']) ? $job['progress'] : [];
        $progressThoughts = isset($progress['thoughts']) && is_array($progress['thoughts']) ? $progress['thoughts'] : [];
        return $this->normalizeThoughts($progressThoughts);
    }

    /**
     * @param array<int,string> $thoughts
     */
    private function updateProgress(array &$job, string $stage, string $label, array $thoughts = []): void {
        $job['progress'] = [
            'stage' => sanitize_key($stage),
            'label' => trim($label),
            'thoughts' => $this->normalizeThoughts($thoughts),
            'supports_thoughts' => $this->supportsThoughtProgress($job),
            'updated_at' => time(),
        ];
    }

    /**
     * @param array<int,string> $thoughts
     * @return array<int,string>
     */
    private function normalizeThoughts(array $thoughts): array {
        $normalized = [];
        foreach ($thoughts as $thought) {
            $text = trim((string) $thought);
            if ($text !== '') {
                $normalized[] = $text;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function supportsThoughtProgress(array $job): bool {
        $requestedModel = isset($job['requested_model']) ? strtolower(trim((string) $job['requested_model'])) : '';
        return $requestedModel !== ''
            && (strpos($requestedModel, 'gemini-3') === 0 || strpos($requestedModel, 'gemini-2.5') === 0);
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed> $result
     */
    private function appendAiResponseToConversation(array &$messages, array $result): void {
        $answer = isset($result['answer']) ? (string) $result['answer'] : '';
        if (wp_strip_all_tags($answer) === '') {
            return;
        }

        $messages[] = [
            'role' => 'model',
            'content' => $answer,
            'sources' => isset($result['sources']) && is_array($result['sources']) ? $result['sources'] : [],
            'meta' => isset($result['meta']) && is_array($result['meta']) ? $result['meta'] : [],
            'created_at' => time(),
        ];
    }
}
