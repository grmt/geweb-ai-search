<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Coordinates Gemini search requests, retry/backoff state, and response shaping.
 */
class GeminiSearchCoordinator {
    private string $apiBase;
    private string $model;
    private GeminiRequestDiagnostics $requestDiagnostics;
    private int $defaultSystemRetries;
    private int $defaultSummaryTimeoutSeconds;
    private string $generateTimeoutBackoffOption;
    private int $generateTimeoutBackoffTtlSeconds;
    private $getStoreDataCallback;
    private $getPromptDescriptorCallback;
    private $buildSearchBodyCallback;
    private $appendExcludedSourcesInstructionCallback;
    private $makeRequestCallback;
    private $makeStreamingRequestCallback;
    private $supportsThoughtSummariesCallback;
    private $isTimeoutExceptionCallback;
    private $logInfoCallback;
    private $logWarningCallback;
    private $formatRuntimeLogContextSuffixCallback;
    private $getRuntimeLogContextCallback;
    private $setRuntimeLogContextCallback;
    private $formatRetryTripletCallback;
    private $clearGenerateTimeoutBackoffForRequestCallback;
    private $recordGenerateTimeoutBackoffCallback;
    private $recordModelStatusCallback;
    private $extractCandidateTextCallback;
    private $buildResponseMetaCallback;
    private $isGemini2ModelCallback;
    private $getHttpTimeoutSecondsCallback;
    private $getSystemRetryCountCallback;
    private $getHumanRetryCountCallback;
    private $getUserScopedOptionCallback;
    private $updateUserScopedOptionCallback;
    private $deleteUserScopedOptionCallback;

    /**
     * @param array<string,mixed> $options
     * @param array<string,callable|null> $callbacks
     */
    public function __construct(string $apiBase, string $model, GeminiRequestDiagnostics $requestDiagnostics, array $options, array $callbacks) {
        $this->apiBase = $apiBase;
        $this->model = $model;
        $this->requestDiagnostics = $requestDiagnostics;
        $this->defaultSystemRetries = (int) ($options['default_system_retries'] ?? 2);
        $this->defaultSummaryTimeoutSeconds = (int) ($options['default_summary_timeout_seconds'] ?? 12);
        $this->generateTimeoutBackoffOption = (string) ($options['generate_timeout_backoff_option'] ?? '');
        $this->generateTimeoutBackoffTtlSeconds = (int) ($options['generate_timeout_backoff_ttl_seconds'] ?? 3600);
        $this->getStoreDataCallback = $this->callableOrNull($callbacks, 'get_store_data');
        $this->getPromptDescriptorCallback = $this->callableOrNull($callbacks, 'get_prompt_descriptor');
        $this->buildSearchBodyCallback = $this->callableOrNull($callbacks, 'build_search_body');
        $this->appendExcludedSourcesInstructionCallback = $this->callableOrNull($callbacks, 'append_excluded_sources_instruction');
        $this->makeRequestCallback = $this->callableOrNull($callbacks, 'make_request');
        $this->makeStreamingRequestCallback = $this->callableOrNull($callbacks, 'make_streaming_request');
        $this->supportsThoughtSummariesCallback = $this->callableOrNull($callbacks, 'supports_thought_summaries');
        $this->isTimeoutExceptionCallback = $this->callableOrNull($callbacks, 'is_timeout_exception');
        $this->logInfoCallback = $this->callableOrNull($callbacks, 'log_info');
        $this->logWarningCallback = $this->callableOrNull($callbacks, 'log_warning');
        $this->formatRuntimeLogContextSuffixCallback = $this->callableOrNull($callbacks, 'format_runtime_log_context_suffix');
        $this->getRuntimeLogContextCallback = $this->callableOrNull($callbacks, 'get_runtime_log_context');
        $this->setRuntimeLogContextCallback = $this->callableOrNull($callbacks, 'set_runtime_log_context');
        $this->formatRetryTripletCallback = $this->callableOrNull($callbacks, 'format_retry_triplet');
        $this->clearGenerateTimeoutBackoffForRequestCallback = $this->callableOrNull($callbacks, 'clear_generate_timeout_backoff_for_request');
        $this->recordGenerateTimeoutBackoffCallback = $this->callableOrNull($callbacks, 'record_generate_timeout_backoff');
        $this->recordModelStatusCallback = $this->callableOrNull($callbacks, 'record_model_status');
        $this->extractCandidateTextCallback = $this->callableOrNull($callbacks, 'extract_candidate_text');
        $this->buildResponseMetaCallback = $this->callableOrNull($callbacks, 'build_response_meta');
        $this->isGemini2ModelCallback = $this->callableOrNull($callbacks, 'is_gemini2_model');
        $this->getHttpTimeoutSecondsCallback = $this->callableOrNull($callbacks, 'get_http_timeout_seconds');
        $this->getSystemRetryCountCallback = $this->callableOrNull($callbacks, 'get_system_retry_count');
        $this->getHumanRetryCountCallback = $this->callableOrNull($callbacks, 'get_human_retry_count');
        $this->getUserScopedOptionCallback = $this->callableOrNull($callbacks, 'get_user_scoped_option');
        $this->updateUserScopedOptionCallback = $this->callableOrNull($callbacks, 'update_user_scoped_option');
        $this->deleteUserScopedOptionCallback = $this->callableOrNull($callbacks, 'delete_user_scoped_option');
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @param array<int,array<string,string>> $excludedSources
     * @return array<string,mixed>
     */
    public function search(array $messages, ?string $model = null, ?string $promptOverride = null, array $excludedSources = []): array {
        $storeName = $this->getStoreData();
        if ($storeName === '') {
            throw new ConfigurationException('Configuration error');
        }

        if (empty($messages)) {
            throw new \Exception('Messages array is empty');
        }

        $requestModel = is_string($model) && $model !== '' ? $model : $this->model;
        $latestQuestion = $this->extractLatestUserQuestion($messages);
        $questionHash = $latestQuestion !== '' ? $this->buildQuestionHash($latestQuestion) : '';
        $runtimeLogContext = $this->getRuntimeLogContext();
        $requestId = isset($runtimeLogContext['request_id']) ? trim((string) $runtimeLogContext['request_id']) : '';
        if ($requestId === '') {
            $requestId = 'gem-' . wp_generate_password(10, false, false);
        }

        $promptDescriptor = $this->getPromptDescriptor($requestModel, $promptOverride);
        $effectivePrompt = trim((string) ($promptDescriptor['instruction'] ?? ''));
        $retryPlan = $this->buildGenerateRetryPlan($latestQuestion, $requestModel, $effectivePrompt);
        $this->requestDiagnostics->reset();

        $runtimeLogContext['request_id'] = $requestId;
        $runtimeLogContext['model'] = $requestModel;
        if ($questionHash !== '') {
            $runtimeLogContext['question_hash'] = $questionHash;
        }
        if ($retryPlan['prompt_hash'] !== '') {
            $runtimeLogContext['prompt_hash'] = $retryPlan['prompt_hash'];
        }
        if ($retryPlan['request_fingerprint'] !== '') {
            $runtimeLogContext['request_fingerprint'] = $retryPlan['request_fingerprint'];
        }
        $this->setRuntimeLogContext($runtimeLogContext);

        $this->logInfo(sprintf(
            'Gemini search dispatch request_id=%s question_hash=%s conversation_id=%s message_count=%d excluded_sources=%d model="%s" system_retries=%d human_retries=%d overall_attempt_start=%d overall_attempt_max=%d retry_triplet_start=%s',
            $requestId,
            $questionHash !== '' ? $questionHash : 'none',
            isset($runtimeLogContext['conversation_id']) ? (string) $runtimeLogContext['conversation_id'] : 'none',
            count($messages),
            count($excludedSources),
            $requestModel,
            $retryPlan['system_retries'],
            $retryPlan['human_retries'],
            $retryPlan['overall_attempt_start'],
            $retryPlan['overall_attempt_max'],
            $this->formatRetryTriplet($retryPlan['overall_attempt_start'], $retryPlan['system_retries'], $retryPlan['overall_attempt_max'])
        ));

        if ($effectivePrompt !== '' && PromptSupport::containsDisallowedUrl($effectivePrompt)) {
            throw new \Exception('Prompt cannot contain URLs. Remove links and try again.');
        }

        $body = $this->buildSearchBody(
            $messages,
            $storeName,
            $requestModel,
            $this->appendExcludedSourcesInstruction((string) ($promptDescriptor['instruction'] ?? ''), $excludedSources)
        );

        try {
            $result = $this->executeSearchRequest(
                $requestModel,
                $body,
                $retryPlan['timeout_seconds'],
                $retryPlan['system_retries'],
                $retryPlan['completed_attempts'],
                $retryPlan['overall_attempt_max']
            );
            $responseText = $this->extractSearchResponseText($result);
            $meta = $this->buildResponseMeta($result, $requestModel, $promptDescriptor);

            $this->clearGenerateTimeoutBackoffForRequest($latestQuestion, $requestModel, $effectivePrompt);
            $this->recordModelStatus($requestModel, 'ok');
            return $this->formatSearchResponse($responseText, $meta, $requestModel);
        } catch (\Exception $e) {
            $isTimeout = $this->isTimeoutException($e);

            if ($isTimeout) {
                $this->recordGenerateTimeoutBackoff(
                    $latestQuestion,
                    $requestModel,
                    $effectivePrompt,
                    $retryPlan['completed_attempts'] + $retryPlan['system_retries']
                );
            } else {
                $this->clearGenerateTimeoutBackoffForRequest($latestQuestion, $requestModel, $effectivePrompt);
            }

            $this->recordModelStatus($requestModel, $isTimeout ? 'timeout' : 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function executeSearchRequest(string $model, array $body, int $timeoutSeconds, int $maxAttempts, int $overallAttemptBase, int $overallAttemptMax): array {
        if ($this->supportsThoughtSummaries($model) && function_exists('curl_init')) {
            $url = $this->apiBase . '/models/' . $model . ':streamGenerateContent?alt=sse';
            try {
                return $this->makeStreamingRequest($url, $body, $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax);
            } catch (\Exception $e) {
                if ($this->isTimeoutException($e)) {
                    throw $e;
                }

                $this->logWarning(sprintf(
                    'Gemini streaming request failed, falling back to generateContent model="%s" message="%s"%s',
                    $model,
                    $e->getMessage(),
                    $this->formatRuntimeLogContextSuffix()
                ));
            }
        }

        $url = $this->apiBase . '/models/' . $model . ':generateContent';
        return $this->makeRequest($url, $body, 'POST', $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax);
    }

    /**
     * @param array<string,mixed> $result
     */
    private function extractSearchResponseText(array $result): string {
        $responseText = $this->extractCandidateText($result);
        if ($responseText === '') {
            throw new \Exception($this->buildEmptyResponseErrorMessage($result));
        }

        return $responseText;
    }

    /**
     * @param array<string,mixed> $result
     */
    private function buildEmptyResponseErrorMessage(array $result): string {
        $candidate = isset($result['candidates'][0]) && is_array($result['candidates'][0])
            ? $result['candidates'][0]
            : [];

        $finishReason = strtoupper(trim((string) ($candidate['finishReason'] ?? '')));
        $finishMessage = trim((string) ($candidate['finishMessage'] ?? ''));
        $toolTokens = (int) ($result['usageMetadata']['toolUsePromptTokenCount'] ?? 0);

        if ($finishReason === 'MAX_TOKENS') {
            return 'AI response was truncated by MAX_TOKENS. No automatic retry was done. Please shorten the question or temporarily exclude one or more sources and try again.';
        }

        if ($finishReason === 'SAFETY') {
            return 'AI response was blocked by safety filters. Please rephrase the request.';
        }

        if ($finishReason === 'TOO_MANY_TOOL_CALLS') {
            return 'AI stopped after spending the request on source lookups and reasoning, before it returned answer text. This can look like a timeout, but Gemini reported TOO_MANY_TOOL_CALLS. No automatic retry was done. Please temporarily exclude one or more sources in the Sources panel, shorten the question, or switch models and try again.';
        }

        if ($toolTokens > 200000) {
            return 'AI returned no answer text because the search context became too large. No automatic retry was done. Please temporarily exclude one or more large sources in the Sources panel and try again.';
        }

        if ($finishMessage !== '') {
            return 'AI returned no answer text. ' . $finishMessage . ' You can temporarily exclude specific sources and retry.';
        }

        if ($finishReason !== '') {
            return 'AI returned no answer text (finish reason: ' . $finishReason . '). You can temporarily exclude specific sources and retry.';
        }

        return 'AI returned no answer text. No automatic retry was done. Please temporarily exclude one or more sources and try again.';
    }

    /**
     * @param string $responseText
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function formatSearchResponse(string $responseText, array $meta, string $model): array {
        $formattedAnswer = apply_filters('the_content', $responseText);
        if ($this->isGemini2Model($model)) {
            return [
                'answer' => $formattedAnswer,
                'meta' => $meta,
            ];
        }

        $normalizedResponseText = trim($responseText);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $normalizedResponseText, $matches)) {
            $normalizedResponseText = trim((string) ($matches[1] ?? ''));
        }

        $decoded = json_decode($normalizedResponseText, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [
                'answer' => $formattedAnswer,
                'meta' => $meta,
            ];
        }

        $decoded['answer'] = apply_filters('the_content', (string) ($decoded['answer'] ?? ''));
        if (trim(wp_strip_all_tags((string) $decoded['answer'])) === '') {
            throw new \Exception($this->buildEmptyStructuredAnswerErrorMessage($decoded));
        }
        $decoded['meta'] = $meta;
        return $decoded;
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function buildEmptyStructuredAnswerErrorMessage(array $decoded): string {
        $message = trim((string) ($decoded['message'] ?? ''));
        if ($message !== '') {
            return 'AI returned structured JSON without answer text. ' . $message;
        }

        $sources = isset($decoded['sources']) && is_array($decoded['sources']) ? $decoded['sources'] : [];
        if (!empty($sources)) {
            return 'AI returned source references but no answer text. Please retry, or temporarily exclude one or more sources and try again.';
        }

        return 'AI returned structured JSON without answer text. Please retry, or temporarily exclude one or more sources and try again.';
    }

    /**
     * @return array{timeout_seconds:int,completed_attempts:int,overall_attempt_start:int,overall_attempt_max:int,system_retries:int,human_retries:int,request_fingerprint:string,prompt_hash:string}
     */
    private function buildGenerateRetryPlan(string $question, string $model, string $promptInstruction): array {
        $baseTimeout = $this->getHttpTimeoutSeconds($model);
        $systemRetries = $this->getSystemRetryCount();
        $humanRetries = $this->getHumanRetryCount();
        $overallAttemptMax = $systemRetries * (1 + $humanRetries);
        $promptHash = $promptInstruction !== '' ? hash('sha256', trim($promptInstruction)) : '';
        $requestFingerprint = $this->buildGenerateRequestFingerprint($question, $model, $promptInstruction);
        $completedAttempts = 0;

        if ($requestFingerprint !== '') {
            $state = $this->getGenerateTimeoutBackoffState();
            if (is_array($state)) {
                $storedFingerprint = isset($state['request_fingerprint']) ? (string) $state['request_fingerprint'] : '';
                $storedCompletedAttempts = isset($state['completed_attempts']) ? (int) $state['completed_attempts'] : 0;
                if ($storedFingerprint === $requestFingerprint) {
                    $completedAttempts = max(0, min($overallAttemptMax, $storedCompletedAttempts));
                }
            }
        }

        if ($question !== '' && $completedAttempts >= $overallAttemptMax) {
            throw new \Exception('This request has already timed out the maximum number of times for the same question, model, and prompt. I do not expect a result without changing the model or the prompt.');
        }

        $overallAttemptStart = $completedAttempts + 1;
        return [
            'timeout_seconds' => $baseTimeout * max(1, $overallAttemptStart),
            'completed_attempts' => $completedAttempts,
            'overall_attempt_start' => $overallAttemptStart,
            'overall_attempt_max' => $overallAttemptMax,
            'system_retries' => $systemRetries,
            'human_retries' => $humanRetries,
            'request_fingerprint' => $requestFingerprint,
            'prompt_hash' => $promptHash,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function getGenerateTimeoutBackoffState(): ?array {
        $state = $this->getUserScopedOption($this->generateTimeoutBackoffOption, null);
        if (!is_array($state)) {
            return null;
        }

        $expiresAt = isset($state['expires_at']) ? (int) $state['expires_at'] : 0;
        if ($expiresAt < time()) {
            $this->deleteUserScopedOption($this->generateTimeoutBackoffOption);
            return null;
        }

        return $state;
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     */
    private function extractLatestUserQuestion(array $messages): string {
        for ($index = count($messages) - 1; $index >= 0; $index--) {
            $message = $messages[$index] ?? null;
            if (!is_array($message)) {
                continue;
            }

            $role = isset($message['role']) ? (string) $message['role'] : '';
            if ($role !== 'user') {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));
            if ($content !== '') {
                return $content;
            }
        }

        return '';
    }

    private function buildQuestionHash(string $question): string {
        $normalizedQuestion = preg_replace('/\s+/', ' ', $question);
        $normalized = strtolower(trim($normalizedQuestion ?? $question));
        return hash('sha256', $normalized);
    }

    private function buildGenerateRequestFingerprint(string $question, string $model, string $promptInstruction): string {
        $normalizedQuestion = trim((string) $question);
        if ($normalizedQuestion === '') {
            return '';
        }

        return hash('sha256', wp_json_encode([
            'question_hash' => $this->buildQuestionHash($normalizedQuestion),
            'model' => trim($model),
            'prompt_hash' => $promptInstruction !== '' ? hash('sha256', trim($promptInstruction)) : '',
        ]));
    }

    private function callableOrNull(array $callbacks, string $key) {
        return isset($callbacks[$key]) && is_callable($callbacks[$key]) ? $callbacks[$key] : null;
    }

    private function getStoreData(): string {
        return $this->getStoreDataCallback !== null ? (string) call_user_func($this->getStoreDataCallback) : '';
    }

    private function getPromptDescriptor(?string $model = null, ?string $promptOverride = null): array {
        return $this->getPromptDescriptorCallback !== null ? (array) call_user_func($this->getPromptDescriptorCallback, $model, $promptOverride) : [];
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @return array<string,mixed>
     */
    private function buildSearchBody(array $messages, string $storeName, string $model, string $systemInstruction): array {
        return $this->buildSearchBodyCallback !== null ? (array) call_user_func($this->buildSearchBodyCallback, $messages, $storeName, $model, $systemInstruction) : [];
    }

    /**
     * @param array<int,array<string,string>> $excludedSources
     */
    private function appendExcludedSourcesInstruction(string $systemInstruction, array $excludedSources): string {
        return $this->appendExcludedSourcesInstructionCallback !== null
            ? (string) call_user_func($this->appendExcludedSourcesInstructionCallback, $systemInstruction, $excludedSources)
            : $systemInstruction;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function makeRequest(string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = 90, int $maxAttempts = 2, int $overallAttemptBase = 0, int $overallAttemptMax = 2): array {
        if ($this->makeRequestCallback === null) {
            throw new \Exception('Gemini search request callback is not configured.');
        }

        return call_user_func($this->makeRequestCallback, $url, $body, $method, $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function makeStreamingRequest(string $url, array $body, int $timeoutSeconds, int $maxAttempts, int $overallAttemptBase, int $overallAttemptMax): array {
        if ($this->makeStreamingRequestCallback === null) {
            throw new \Exception('Gemini search streaming request callback is not configured.');
        }

        return call_user_func($this->makeStreamingRequestCallback, $url, $body, $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax);
    }

    private function supportsThoughtSummaries(string $model): bool {
        return $this->supportsThoughtSummariesCallback !== null ? (bool) call_user_func($this->supportsThoughtSummariesCallback, $model) : false;
    }

    private function isTimeoutException(\Exception $exception): bool {
        return $this->isTimeoutExceptionCallback !== null ? (bool) call_user_func($this->isTimeoutExceptionCallback, $exception) : false;
    }

    private function logWarning(string $message): void {
        if ($this->logWarningCallback !== null) {
            call_user_func($this->logWarningCallback, $message);
        }
    }

    private function logInfo(string $message): void {
        if ($this->logInfoCallback !== null) {
            call_user_func($this->logInfoCallback, $message);
        }
    }

    private function formatRuntimeLogContextSuffix(): string {
        return $this->formatRuntimeLogContextSuffixCallback !== null ? (string) call_user_func($this->formatRuntimeLogContextSuffixCallback) : '';
    }

    /**
     * @return array<string,string|int>
     */
    private function getRuntimeLogContext(): array {
        return $this->getRuntimeLogContextCallback !== null ? (array) call_user_func($this->getRuntimeLogContextCallback) : [];
    }

    /**
     * @param array<string,string|int> $context
     */
    private function setRuntimeLogContext(array $context): void {
        if ($this->setRuntimeLogContextCallback !== null) {
            call_user_func($this->setRuntimeLogContextCallback, $context);
        }
    }

    private function formatRetryTriplet(int $attempt, int $phaseAttemptMax, int $overallAttemptMax): string {
        if ($this->formatRetryTripletCallback !== null) {
            return (string) call_user_func($this->formatRetryTripletCallback, $attempt, $phaseAttemptMax, $overallAttemptMax);
        }

        return sprintf('%d/%d/%d', max(1, $attempt), max(1, $phaseAttemptMax), max(1, $overallAttemptMax));
    }

    private function clearGenerateTimeoutBackoffForRequest(string $question, string $model, string $promptInstruction): void {
        if ($this->clearGenerateTimeoutBackoffForRequestCallback !== null) {
            call_user_func($this->clearGenerateTimeoutBackoffForRequestCallback, $question, $model, $promptInstruction);
        }
    }

    private function recordGenerateTimeoutBackoff(string $question, string $model, string $promptInstruction, int $completedAttempts): void {
        if ($this->recordGenerateTimeoutBackoffCallback !== null) {
            call_user_func($this->recordGenerateTimeoutBackoffCallback, $question, $model, $promptInstruction, $completedAttempts);
            return;
        }

        if ($question === '') {
            return;
        }

        $state = [
            'question_hash' => $this->buildQuestionHash($question),
            'prompt_hash' => $promptInstruction !== '' ? hash('sha256', trim($promptInstruction)) : '',
            'model' => $model,
            'request_fingerprint' => $this->buildGenerateRequestFingerprint($question, $model, $promptInstruction),
            'completed_attempts' => max(0, $completedAttempts),
            'expires_at' => time() + $this->generateTimeoutBackoffTtlSeconds,
        ];
        $this->updateUserScopedOption($this->generateTimeoutBackoffOption, $state);
    }

    private function recordModelStatus(string $model, string $status, string $message = ''): void {
        if ($this->recordModelStatusCallback !== null) {
            call_user_func($this->recordModelStatusCallback, $model, $status, $message);
        }
    }

    /**
     * @param array<string,mixed> $result
     */
    private function extractCandidateText(array $result): string {
        return $this->extractCandidateTextCallback !== null ? (string) call_user_func($this->extractCandidateTextCallback, $result) : '';
    }

    /**
     * @param array<string,mixed> $promptDescriptor
     * @return array<string,mixed>
     */
    private function buildResponseMeta(array $result, string $model, array $promptDescriptor): array {
        return $this->buildResponseMetaCallback !== null ? (array) call_user_func($this->buildResponseMetaCallback, $result, $model, $promptDescriptor) : [];
    }

    private function isGemini2Model(string $model): bool {
        return $this->isGemini2ModelCallback !== null ? (bool) call_user_func($this->isGemini2ModelCallback, $model) : false;
    }

    private function getHttpTimeoutSeconds(string $model = ''): int {
        return $this->getHttpTimeoutSecondsCallback !== null
            ? (int) call_user_func($this->getHttpTimeoutSecondsCallback, $model)
            : $this->defaultSummaryTimeoutSeconds;
    }

    private function getSystemRetryCount(): int {
        return $this->getSystemRetryCountCallback !== null
            ? (int) call_user_func($this->getSystemRetryCountCallback)
            : $this->defaultSystemRetries;
    }

    private function getHumanRetryCount(): int {
        return $this->getHumanRetryCountCallback !== null
            ? (int) call_user_func($this->getHumanRetryCountCallback)
            : 0;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function getUserScopedOption(string $optionName, $default = false) {
        return $this->getUserScopedOptionCallback !== null ? call_user_func($this->getUserScopedOptionCallback, $optionName, $default) : $default;
    }

    /**
     * @param mixed $value
     */
    private function updateUserScopedOption(string $optionName, $value): bool {
        return $this->updateUserScopedOptionCallback !== null ? (bool) call_user_func($this->updateUserScopedOptionCallback, $optionName, $value) : false;
    }

    private function deleteUserScopedOption(string $optionName): void {
        if ($this->deleteUserScopedOptionCallback !== null) {
            call_user_func($this->deleteUserScopedOptionCallback, $optionName);
        }
    }
}
