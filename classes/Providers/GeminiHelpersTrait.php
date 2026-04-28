<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait GeminiHelpersTrait {
    private const API_UPLOAD_BASE = 'https://generativelanguage.googleapis.com/upload/v1beta';
    private const OPTION_MODEL = 'geweb_aisearch_model';
    private const OPTION_MODEL_SELECTION_MODE = 'geweb_aisearch_model_selection_mode';
    private const OPTION_MODEL_STATUS = 'geweb_aisearch_model_status';
    private const OPTION_MODEL_PROMPTS = 'geweb_aisearch_model_prompts';
    private const OPTION_MODEL_PROMPT_NAMES = 'geweb_aisearch_model_prompt_names';
    private const OPTION_MODEL_PROMPT_MODES = 'geweb_aisearch_model_prompt_modes';
    private const OPTION_CONNECTION_STATUS = 'geweb_aisearch_connection_status';
    private const OPTION_STORE = 'geweb_aisearch_gemini_store';
    private const OPTION_STORES_CACHE = 'geweb_aisearch_gemini_stores_cache';
    private const OPTION_STORES_CACHE_TIME = 'geweb_aisearch_gemini_stores_cache_time';
    private const OPTION_STORES_CACHE_ERROR = 'geweb_aisearch_gemini_stores_cache_error';
    private const OPTION_STORE_DOCUMENTS_CACHE = 'geweb_aisearch_gemini_store_documents_cache';
    private const OPTION_STORE_DOCUMENTS_CACHE_TIME = 'geweb_aisearch_gemini_store_documents_cache_time';
    private const STORE_OVERVIEW_CACHE_MAX_AGE = 300;
    private const STORE_DOCUMENTS_CACHE_MAX_AGE = DAY_IN_SECONDS;
    private const OPTION_CUSTOM_PROMPT = 'geweb_aisearch_custom_prompt';
    private const DEFAULT_SYSTEM_INSTRUCTION = "You are a knowledge base search assistant.\n\n" .
        "Your task:\n" .
        "1. Summarize the information from the documents in your own words. Avoid direct long quotes.\n" .
        "2. Provide a clear answer to the user's question\n" .
        "3. Extract the URL from the frontmatter of each document used (line 'url: ...')\n" .
        "4. Return a list of sources with URLs and page titles\n\n" .
        "Rules:\n" .
        "- Answer briefly in your own words based on the provided data\n" .
        "- Use only information from the found documents\n" .
        "- If there's no information — say so\n" .
        "- Add to sources only the pages you actually used for the answer\n" .
        "- Do not use markdown in response, change it to html\n" .
        "- URL is taken from the document's frontmatter (---\\nurl: ...\\n---)\n" .
        "- Title is taken from H1 in the document\n\n";
    private const DEFAULT_SYSTEM_INSTRUCTION_GEMINI2_APPENDIX = "Formatting requirements for Gemini 2.x models:\n" .
        "- Do not add a separate Sources, Bronnen, References, or Links section at the end of the answer\n" .
        "- Keep the answer body clean and readable without repeating the source list\n" .
        "- If source evidence is needed, keep it concise and rely on grounding metadata rather than a manual source appendix\n\n";
    private const DEFAULT_SYSTEM_INSTRUCTION_STRUCTURED_APPENDIX = "Formatting requirements for structured-output models:\n" .
        "- Do not add a separate Sources, Bronnen, References, or Links section at the end of the answer\n" .
        "- Put source URLs and titles only in the structured sources field, not in the answer body\n" .
        "- Keep the answer body focused on the explanation itself\n\n";
    private const DEFAULT_MODEL = 'gemini-3-flash-preview';
    private const OFFICIAL_LATEST_MODEL_ALIASES = [
        'gemini-flash-latest',
        'gemini-pro-latest',
    ];
    private const LEGACY_DEFAULT_MODELS = [
        'gemini-2.5-flash',
    ];
    private const MODEL_SELECTION_MODE_DEFAULT = 'default';
    private const MODEL_SELECTION_MODE_CUSTOM = 'custom';
    private const TRANSIENT_MODELS = 'geweb_aisearch_gemini_models_v2';
    private const MODEL_PRICING_USD_PER_MILLION = [
        'gemini-2.5-flash' => [
            'input' => 0.30,
            'output' => 2.50,
        ],
        'gemini-2.5-pro' => [
            'input' => 1.25,
            'output' => 10.00,
        ],
    ];
    private const DEFAULT_SUMMARY_TIMEOUT_SECONDS = 12;
    private const DEFAULT_UPLOAD_OPERATION_TIMEOUT_SECONDS = 300;
    private const DEFAULT_UPLOAD_OPERATION_POLL_INTERVAL_MS = 5000;
    private const GENERATE_TIMEOUT_BACKOFF_OPTION = 'geweb_aisearch_gemini_generate_timeout_backoff';
    private const GENERATE_TIMEOUT_BACKOFF_TTL_SECONDS = 3600;
    private const MAX_UPLOAD_FILE_BYTES = 104857600;
    private const DEFAULT_OCR_MODEL = 'gemini-2.5-flash';
    private const STALE_FAILED_MODEL_RETENTION_SECONDS = WEEK_IN_SECONDS;
    private const MODEL_TEST_TIMEOUT_SECONDS = 20;

    /**
     * @var array<string,string|int>
     */
    private array $runtimeLogContext = [];

    private ?GeminiStoreClient $storeClient = null;
    private ?GeminiUploadClient $uploadClient = null;
    private ?GeminiVisionClient $visionClient = null;
    private ?GeminiSearchCoordinator $searchCoordinator = null;
    private ?GeminiPromptResolver $promptResolver = null;
    private ?GeminiModelRegistry $modelRegistry = null;

    public function setRuntimeLogContext(array $context): void {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalizedKey = sanitize_key((string) $key);
            if ($normalizedKey === '') {
                continue;
            }

            if (is_int($value)) {
                $normalized[$normalizedKey] = $value;
                continue;
            }

            $normalizedValue = trim((string) $value);
            if ($normalizedValue === '') {
                continue;
            }

            $normalized[$normalizedKey] = $normalizedValue;
        }

        $this->runtimeLogContext = $normalized;
    }

    public function getModels(bool $forceRefresh = false): array {
        return $this->createModelRegistry()->getModels($forceRefresh);
    }

    public function getModel(): string {
        return $this->createModelRegistry()->getModel();
    }

    public function getDefaultModel(?array $models = null): string {
        return $this->createModelRegistry()->getDefaultModel($models);
    }

    public function getDefaultSystemInstruction(): string {
        return $this->createPromptResolver()->getDefaultSystemInstruction();
    }

    public function getDefaultSystemInstructionForModel(?string $model = null): string {
        return $this->createPromptResolver()->getDefaultSystemInstructionForModel($model);
    }

    public function getPromptDescriptor(?string $model = null, ?string $promptOverride = null): array {
        return $this->createPromptResolver()->getPromptDescriptor($model, $promptOverride);
    }

    public function getModelStatuses(): array {
        return $this->createModelRegistry()->getModelStatuses();
    }

    public function getConnectionStatus(): array {
        return $this->createModelRegistry()->getConnectionStatus();
    }

    public function validateConnection(): array {
        return $this->createModelRegistry()->validateConnection();
    }

    public function clearModelsCache(): void {
        $this->createModelRegistry()->clearModelsCache();
    }

    public function testModel(string $model): array {
        return $this->createModelRegistry()->testModel($model);
    }

    public function isDeprecatedModel(string $model): bool {
        return $this->createModelRegistry()->isDeprecatedModel($model);
    }

    private function extractCandidateText(array $result): string {
        $candidates = isset($result['candidates']) && is_array($result['candidates']) ? $result['candidates'] : [];
        if (empty($candidates)) { return ''; }
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) { continue; }
            $textParts = $this->extractTextPartsFromCandidate($candidate);
            if (!empty($textParts)) { return trim(implode('', $textParts)); }
        }
        return '';
    }

    private function extractTextPartsFromCandidate(array $candidate): array {
        $parts = isset($candidate['content']['parts']) && is_array($candidate['content']['parts']) ? $candidate['content']['parts'] : [];
        $textParts = [];
        foreach ($parts as $part) {
            if (!is_array($part) || !isset($part['text']) || !empty($part['thought'])) { continue; }
            $text = (string) $part['text'];
            if ($text !== '') { $textParts[] = $text; }
        }
        return $textParts;
    }

    private function extractResolvedModelName(array $result, string $fallback = ''): string {
        $modelVersion = isset($result['modelVersion']) ? trim((string) $result['modelVersion']) : '';
        if ($modelVersion !== '') { return $modelVersion; }
        $model = isset($result['model']) ? trim((string) $result['model']) : '';
        if ($model !== '') { return preg_replace('#^models/#', '', $model) ?: $model; }
        return trim($fallback);
    }

    private function normalizeMessageForSummary(array $message): ?array {
        $content = trim(wp_strip_all_tags((string) ($message['content'] ?? '')));
        if ($content === '') { return null; }
        if (function_exists('mb_strimwidth')) { $content = mb_strimwidth($content, 0, 520, '...'); } elseif (strlen($content) > 520) { $content = substr($content, 0, 517) . '...'; }
        $role = isset($message['role']) ? (string) $message['role'] : 'user';
        return ['role' => $role === 'model' ? 'assistant' : 'user', 'content' => $content];
    }

    private function makeRequest(string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = self::DEFAULT_HTTP_TIMEOUT_SECONDS, int $maxAttempts = self::DEFAULT_SYSTEM_RETRIES, int $overallAttemptBase = 0, int $overallAttemptMax = self::DEFAULT_SYSTEM_RETRIES): array {
        return $this->createHttpClient()->request($url, $body, $method, $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax);
    }

    private function makeStreamingRequest(string $url, array $body, int $timeoutSeconds, int $maxAttempts = self::DEFAULT_SYSTEM_RETRIES, int $overallAttemptBase = 0, int $overallAttemptMax = self::DEFAULT_SYSTEM_RETRIES): array {
        return $this->createHttpClient()->streamingRequest($url, $body, $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax);
    }

    private function formatRuntimeLogContextSuffix(): string {
        if (empty($this->runtimeLogContext)) { return ''; }
        $parts = [];
        foreach ($this->runtimeLogContext as $key => $value) {
            $normalizedKey = sanitize_key((string) $key);
            if ($normalizedKey === '') { continue; }
            if (is_int($value)) { $parts[] = $normalizedKey . '=' . $value; continue; }
            $normalizedValue = trim((string) $value);
            if ($normalizedValue === '') { continue; }
            $parts[] = $normalizedKey . '="' . str_replace('"', '\"', $normalizedValue) . '"';
        }
        return empty($parts) ? '' : ' ' . implode(' ', $parts);
    }

    private function formatByteSize(int $bytes): string {
        if ($bytes >= 1048576) { return round($bytes / 1048576, 2) . ' MB'; }
        if ($bytes >= 1024) { return round($bytes / 1024, 2) . ' KB'; }
        return $bytes . ' bytes';
    }

    private function handleHttpApiFailure(string $requestModel, int $httpCode, string $responseBody): void {
        if ($requestModel === '' || !$this->shouldMarkModelPermanentlyUnavailable($requestModel, $httpCode, $responseBody)) { return; }
        $this->recordModelStatus($requestModel, 'failed', $responseBody, ['permanent_unavailable' => true]);
        $this->clearModelsCache();
        if ((string) get_option(self::OPTION_MODEL, '') === $requestModel) {
            update_option(self::OPTION_MODEL, self::DEFAULT_MODEL);
            update_option(self::OPTION_MODEL_SELECTION_MODE, self::MODEL_SELECTION_MODE_DEFAULT);
        }
    }

    private function getHttpTimeoutSeconds(string $model = ''): int {
        $isPro = strpos(strtolower($model), 'pro') !== false;
        $default = $isPro ? self::DEFAULT_PRO_HTTP_TIMEOUT_SECONDS : self::DEFAULT_HTTP_TIMEOUT_SECONDS;
        $optionKey = $isPro ? self::OPTION_TIMEOUT_PRO : self::OPTION_TIMEOUT_FLASH;
        $configuredTimeout = get_option($optionKey);
        $baseTimeout = (is_numeric($configuredTimeout) && (int) $configuredTimeout > 0) ? (int) $configuredTimeout : $default;
        $timeout = apply_filters('geweb_aisearch_gemini_http_timeout', $baseTimeout, $model);
        return is_numeric($timeout) && (int) $timeout > 0 ? (int) $timeout : $default;
    }

    private function getSystemRetryCount(): int {
        $configured = get_option(self::OPTION_SYSTEM_RETRIES);
        return (is_numeric($configured) && (int) $configured > 0) ? max(1, min(4, (int) $configured)) : self::DEFAULT_SYSTEM_RETRIES;
    }

    private function getHumanRetryCount(): int {
        $configured = get_option(self::OPTION_HUMAN_RETRIES);
        return (is_numeric($configured) && (int) $configured >= 0) ? max(0, min(4, (int) $configured)) : self::DEFAULT_HUMAN_RETRIES;
    }

    private function recordGenerateTimeoutBackoff(string $question, string $model, string $promptInstruction, int $completedAttempts): void {
        if ($question === '') { return; }
        $state = [
            'question_hash' => $this->buildQuestionHash($question),
            'prompt_hash' => $promptInstruction !== '' ? hash('sha256', trim($promptInstruction)) : '',
            'model' => $model,
            'request_fingerprint' => $this->buildGenerateRequestFingerprint($question, $model, $promptInstruction),
            'completed_attempts' => max(0, $completedAttempts),
            'expires_at' => time() + self::GENERATE_TIMEOUT_BACKOFF_TTL_SECONDS,
        ];
        $this->updateUserScopedOption(self::GENERATE_TIMEOUT_BACKOFF_OPTION, $state);
    }

    private function clearGenerateTimeoutBackoffForRequest(string $question, string $model, string $promptInstruction): void {
        if ($question === '') { return; }
        $state = $this->getGenerateTimeoutBackoffState();
        if (!is_array($state)) { return; }
        $storedFingerprint = isset($state['request_fingerprint']) ? (string) $state['request_fingerprint'] : '';
        if ($storedFingerprint !== $this->buildGenerateRequestFingerprint($question, $model, $promptInstruction)) { return; }
        $this->deleteUserScopedOption(self::GENERATE_TIMEOUT_BACKOFF_OPTION);
    }

    private function getGenerateTimeoutBackoffState(): ?array {
        $state = $this->getUserScopedOption(self::GENERATE_TIMEOUT_BACKOFF_OPTION, null);
        if (!is_array($state)) { return null; }
        $expiresAt = isset($state['expires_at']) ? (int) $state['expires_at'] : 0;
        if ($expiresAt < time()) { $this->deleteUserScopedOption(self::GENERATE_TIMEOUT_BACKOFF_OPTION); return null; }
        return $state;
    }

    private function buildQuestionHash(string $question): string {
        $normalizedQuestion = preg_replace('/\s+/', ' ', $question);
        $normalized = strtolower(trim($normalizedQuestion ?? $question));
        return hash('sha256', $normalized);
    }

    private function buildGenerateRequestFingerprint(string $question, string $model, string $promptInstruction): string {
        $normalizedQuestion = trim((string) $question);
        if ($normalizedQuestion === '') { return ''; }
        return hash('sha256', wp_json_encode([
            'question_hash' => $this->buildQuestionHash($normalizedQuestion),
            'model' => trim($model),
            'prompt_hash' => $promptInstruction !== '' ? hash('sha256', trim($promptInstruction)) : '',
        ]));
    }

    private function formatRetryTriplet(int $attempt, int $phaseAttemptMax, int $overallAttemptMax): string {
        return sprintf('%d/%d/%d', max(1, $attempt), max(1, $phaseAttemptMax), max(1, $overallAttemptMax));
    }

    private function isTimeoutException(\Exception $exception): bool {
        return $this->messageContainsAny($exception->getMessage(), ['timed out', 'timeout', 'operation timed out', 'cURL error 28']);
    }

    private function getSummaryTimeoutSeconds(): int {
        $timeout = apply_filters('geweb_aisearch_gemini_summary_timeout', self::DEFAULT_SUMMARY_TIMEOUT_SECONDS);
        return is_numeric($timeout) ? (int) $timeout : self::DEFAULT_SUMMARY_TIMEOUT_SECONDS;
    }

    private function getUploadOperationTimeoutSeconds(): int {
        $timeout = apply_filters('geweb_aisearch_gemini_upload_operation_timeout', self::DEFAULT_UPLOAD_OPERATION_TIMEOUT_SECONDS);
        return is_numeric($timeout) ? (int) $timeout : self::DEFAULT_UPLOAD_OPERATION_TIMEOUT_SECONDS;
    }

    private function getUploadOperationPollIntervalMs(): int {
        $interval = apply_filters('geweb_aisearch_gemini_upload_operation_poll_interval_ms', self::DEFAULT_UPLOAD_OPERATION_POLL_INTERVAL_MS);
        return is_numeric($interval) ? (int) $interval : self::DEFAULT_UPLOAD_OPERATION_POLL_INTERVAL_MS;
    }

    private function buildSearchBody(array $messages, string $storeName, string $model, string $systemInstruction): array {
        $contents = $this->buildSearchContents($messages);
        $body = ['system_instruction' => ['parts' => [['text' => $systemInstruction]]], 'contents' => $contents, 'tools' => [['file_search' => ['file_search_store_names' => [$storeName]]]]];
        if (!$this->isGemini2Model($model)) { $body['generationConfig'] = $this->getStructuredGenerationConfig(); }
        if ($this->supportsThoughtSummaries($model)) {
            if (!isset($body['generationConfig']) || !is_array($body['generationConfig'])) { $body['generationConfig'] = []; }
            $body['generationConfig']['thinkingConfig'] = ['includeThoughts' => true];
        }
        return $body;
    }

    private function appendExcludedSourcesInstruction(string $systemInstruction, array $excludedSources): string {
        $lines = [];
        foreach ($excludedSources as $source) {
            if (!is_array($source)) { continue; }
            $title = isset($source['title']) ? trim((string) $source['title']) : '';
            $url = isset($source['url']) ? trim((string) $source['url']) : '';
            $label = $url;
            if ($title !== '') { $label = $url !== '' ? $title . ' (' . $url . ')' : $title; }
            if ($label === '') { continue; }
            $lines[] = '- ' . $label;
        }
        if (!$lines) { return $systemInstruction; }
        return rtrim($systemInstruction) . "\n\nTemporary source exclusions for this chat request:\n" . implode("\n", array_values(array_unique($lines))) . "\n\nTreat every source listed above as unavailable for this request.\nDo not use, quote, summarize, cite, or rely on those excluded sources, even if they would otherwise be relevant.\nIf the remaining allowed sources are insufficient, say so briefly instead of using an excluded source.\n";
    }

    private function buildSearchContents(array $messages): array {
        $contents = [];
        foreach ($messages as $message) {
            if (empty($message['content'])) { continue; }
            $contents[] = ['role' => $message['role'], 'parts' => [['text' => $message['content']]]];
        }
        return $contents;
    }

    private function getStructuredGenerationConfig(): array {
        return [
            'temperature' => 0.3,
            'responseMimeType' => 'application/json',
            'responseJsonSchema' => [
                'type' => 'object',
                'properties' => [
                    'answer' => ['type' => 'string', 'description' => 'Answer to the user question in HTML format do not use markdown'],
                    'sources' => [
                        'type' => 'array',
                        'description' => 'List of sources used for the answer',
                        'items' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'description' => 'Page URL'], 'title' => ['type' => 'string', 'description' => 'Page title']], 'required' => ['url', 'title']]
                    ]
                ],
                'required' => ['answer', 'sources']
            ]
        ];
    }

    private function buildResponseMeta(array $result, string $model, array $promptDescriptor = []): array {
        $usage = $this->extractUsageMetadata($result);
        $candidate = isset($result['candidates'][0]) && is_array($result['candidates'][0]) ? $result['candidates'][0] : [];
        $meta = ['provider' => $this->getProviderLabel(), 'model' => $model];
        $responseId = isset($result['responseId']) ? trim((string) $result['responseId']) : '';
        if ($responseId !== '') { $meta['response_id'] = $responseId; }
        $modelVersion = isset($result['modelVersion']) ? trim((string) $result['modelVersion']) : '';
        if ($modelVersion !== '') { $meta['model_version'] = $modelVersion; }
        if (!empty($promptDescriptor)) { $meta['prompt'] = $this->buildPromptMeta($promptDescriptor); }
        if (!empty($usage)) { $meta['usage'] = $usage; }
        $thoughtSummaries = $this->extractThoughtSummaries($result);
        if (!empty($thoughtSummaries)) { $meta['thoughts'] = $thoughtSummaries; }
        $this->appendCandidateMetaIfPresent($candidate, $meta);
        $promptFeedback = $result['promptFeedback'] ?? null;
        if (is_array($promptFeedback) && !empty($promptFeedback)) { $meta['prompt_feedback'] = $promptFeedback; }
        $estimatedCost = $this->estimateTextGenerationCost($usage, $model);
        if ($estimatedCost !== null) { $meta['estimated_cost_usd'] = $estimatedCost; }
        $requestAttempts = $this->requestDiagnostics->getAttempts();
        if (!empty($requestAttempts)) { $meta['request_attempts'] = $requestAttempts; }
        return $meta;
    }

    private function appendCandidateMetaIfPresent(array $candidate, array &$meta): void {
        if (empty($candidate)) { return; }
        $candidateMeta = $this->buildCandidateMeta($candidate);
        if (count($candidateMeta) > 1) { $meta['candidate'] = $candidateMeta; }
    }

    private function extractThoughtSummaries(array $result): array {
        $candidates = isset($result['candidates']) && is_array($result['candidates']) ? $result['candidates'] : [];
        if (empty($candidates)) { return []; }
        $thoughts = [];
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) { $this->mergeThoughtsFromCandidate($candidate, $thoughts); }
        }
        return array_values(array_filter($thoughts, static function ($thought): bool { return trim((string) $thought) !== ''; }));
    }

    private function mergeThoughtsFromCandidate(array $candidate, array &$thoughts): void {
        $parts = isset($candidate['content']['parts']) && is_array($candidate['content']['parts']) ? $candidate['content']['parts'] : [];
        foreach ($parts as $part) {
            if (!is_array($part) || empty($part['thought']) || !isset($part['text'])) { continue; }
            $text = trim((string) $part['text']);
            if ($text !== '') { GeminiStreamingResponseAccumulator::mergeThoughtTextIntoSegments($thoughts, $text); }
        }
    }

    private function buildPromptMeta(array $promptDescriptor): array {
        $promptText = isset($promptDescriptor['instruction']) ? trim((string) $promptDescriptor['instruction']) : '';
        return [
            'name' => isset($promptDescriptor['name']) ? trim((string) $promptDescriptor['name']) : '',
            'scope' => isset($promptDescriptor['scope']) ? trim((string) $promptDescriptor['scope']) : '',
            'mode' => isset($promptDescriptor['mode']) ? trim((string) $promptDescriptor['mode']) : '',
            'base_name' => isset($promptDescriptor['base_name']) ? trim((string) $promptDescriptor['base_name']) : '',
            'is_model_specific' => !empty($promptDescriptor['is_model_specific']),
            'is_custom' => !empty($promptDescriptor['is_custom']),
            'hash' => $promptText !== '' ? md5($promptText) : '',
            'preview' => $promptText !== '' ? $this->buildPromptPreview($promptText) : '',
            'text' => $promptText,
        ];
    }

    private function buildCandidateMeta(array $candidate): array {
        $candidateMeta = ['index' => isset($candidate['index']) ? (int) $candidate['index'] : 0];
        $finishReason = isset($candidate['finishReason']) ? trim((string) $candidate['finishReason']) : '';
        if ($finishReason !== '') { $candidateMeta['finish_reason'] = $finishReason; }
        $finishMessage = isset($candidate['finishMessage']) ? trim((string) $candidate['finishMessage']) : '';
        if ($finishMessage !== '') { $candidateMeta['finish_message'] = $finishMessage; }
        if (isset($candidate['tokenCount'])) { $candidateMeta['token_count'] = (int) $candidate['tokenCount']; }
        if (isset($candidate['avgLogprobs'])) { $candidateMeta['avg_logprobs'] = (float) $candidate['avgLogprobs']; }
        foreach ($this->getCandidateArrayFieldMap() as $sourceKey => $targetKey) {
            if (isset($candidate[$sourceKey]) && is_array($candidate[$sourceKey]) && !empty($candidate[$sourceKey])) {
                $candidateMeta[$targetKey] = $candidate[$sourceKey];
            }
        }
        return $candidateMeta;
    }

    private function getCandidateArrayFieldMap(): array {
        return ['safetyRatings' => 'safety_ratings', 'citationMetadata' => 'citation_metadata', 'groundingAttributions' => 'grounding_attributions', 'groundingMetadata' => 'grounding_metadata', 'urlContextMetadata' => 'url_context_metadata', 'logprobsResult' => 'logprobs_result'];
    }

    private function extractUsageMetadata(array $result): array {
        $usage = isset($result['usageMetadata']) && is_array($result['usageMetadata']) ? $result['usageMetadata'] : [];
        if (empty($usage)) { return []; }
        $mapped = [
            'input_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0),
            'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
            'thought_tokens' => (int) ($usage['thoughtsTokenCount'] ?? 0),
            'tool_tokens' => (int) ($usage['toolUsePromptTokenCount'] ?? 0),
            'cached_tokens' => (int) ($usage['cachedContentTokenCount'] ?? 0),
        ];
        return array_filter($mapped, static function (int $value): bool { return $value > 0; });
    }

    private function estimateTextGenerationCost(array $usage, string $model): ?float {
        $pricing = !empty($usage) ? (self::MODEL_PRICING_USD_PER_MILLION[$model] ?? null) : null;
        if (!is_array($pricing)) { return null; }
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);
        if ($inputTokens <= 0 && $outputTokens <= 0) { return null; }
        return (($inputTokens / 1_000_000) * (float) ($pricing['input'] ?? 0)) + (($outputTokens / 1_000_000) * (float) ($pricing['output'] ?? 0));
    }

    private function recordModelStatus(string $model, string $status, string $message = '', array $details = []): void {
        $this->createModelRegistry()->recordModelStatus($model, $status, $message, $details);
    }

    private function sanitizeConnectionErrorMessage(string $message): string {
        return $this->createModelRegistry()->sanitizeConnectionErrorMessage($message);
    }

    private function buildPromptPreview(string $prompt): string {
        return $this->createPromptResolver()->buildPromptPreview($prompt);
    }

    private function messageContainsAny(string $message, array $fragments): bool {
        return GeminiModelRules::messageContainsAny($message, $fragments);
    }

    private function getScopedOption(string $optionName, $default = false) {
        return UserScope::getGroupScopedOption($optionName, $default);
    }

    private function getUserScopedOption(string $optionName, $default = false) {
        return UserScope::getUserScopedOption($optionName, $default);
    }

    private function updateScopedOption(string $optionName, $value): bool {
        return UserScope::updateGroupScopedOption($optionName, $value, false);
    }

    private function deleteScopedOption(string $optionName): void {
        UserScope::deleteGroupScopedOption($optionName);
    }

    private function updateUserScopedOption(string $optionName, $value): bool {
        return UserScope::updateUserScopedOption($optionName, $value, false);
    }

    private function deleteUserScopedOption(string $optionName): void {
        UserScope::deleteUserScopedOption($optionName);
    }

    private function supportsFileSearch(string $model): bool {
        return GeminiModelRules::supportsFileSearch($model, self::OFFICIAL_LATEST_MODEL_ALIASES, fn(string $normalizedModel): bool => $this->isPermanentlyUnavailableModel($normalizedModel));
    }

    private function isPermanentlyUnavailableModel(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') { return false; }
        $statuses = $this->getModelStatuses();
        $entry = $statuses[$normalizedModel] ?? null;
        return is_array($entry) && !empty($entry['permanent_unavailable']);
    }

    private function extractRequestedModelFromUrl(string $url): string {
        return GeminiModelRules::extractRequestedModelFromUrl($url);
    }

    private function extractHttpCodeFromMessage(string $message): int {
        return GeminiModelRules::extractHttpCodeFromMessage($message);
    }

    private function shouldMarkModelPermanentlyUnavailable(string $model, int $httpCode, string $message): bool {
        return GeminiModelRules::shouldMarkModelPermanentlyUnavailable($model, $httpCode, $message);
    }

    private function prependOfficialLatestAliases(array $models): array {
        return GeminiModelRules::prependOfficialLatestAliases($models, self::OFFICIAL_LATEST_MODEL_ALIASES);
    }

    private function isGemini2Model(string $model): bool {
        return GeminiModelRules::isGemini2Model($model);
    }

    private function supportsThoughtSummaries(string $model): bool {
        return GeminiModelRules::supportsThoughtSummaries($model);
    }

    private function logInfo(string $message): void { error_log($this->buildLogLine('INFO', $message)); }
    private function logWarning(string $message): void { error_log($this->buildLogLine('WARN', $message)); }
    private function logError(string $message): void { error_log($this->buildLogLine('ERROR', $message)); }
    private function buildLogLine(string $level, string $message): string {
        $timestamp = function_exists('wp_date') ? wp_date('Y-m-d H:i:s') : date('Y-m-d H:i:s');
        return sprintf('%s geweb-ai-search [%s] %s', $timestamp, strtoupper(trim($level)), $message);
    }
    private function createHttpClient(): GeminiHttpClient {
        return new GeminiHttpClient($this->apiKey, $this->requestDiagnostics, [
            'log_info' => function (string $message): void { $this->logInfo($message); },
            'log_error' => function (string $message): void { $this->logError($message); },
            'context_suffix' => fn(): string => $this->formatRuntimeLogContextSuffix(),
            'is_timeout' => fn(\Exception $exception): bool => $this->isTimeoutException($exception),
            'api_failure' => function (string $model, int $httpCode, string $responseBody): void { $this->handleHttpApiFailure($model, $httpCode, $responseBody); },
            'stream_progress' => $this->streamProgressCallback,
        ]);
    }
    private function createStoreClient(): GeminiStoreClient {
        if ($this->storeClient instanceof GeminiStoreClient) { return $this->storeClient; }
        $this->storeClient = new GeminiStoreClient(self::API_BASE, $this->apiKey, [
            'option_store' => self::OPTION_STORE, 'option_stores_cache' => self::OPTION_STORES_CACHE, 'option_stores_cache_time' => self::OPTION_STORES_CACHE_TIME, 'option_stores_cache_error' => self::OPTION_STORES_CACHE_ERROR,
            'option_store_documents_cache' => self::OPTION_STORE_DOCUMENTS_CACHE, 'option_store_documents_cache_time' => self::OPTION_STORE_DOCUMENTS_CACHE_TIME, 'store_overview_cache_max_age' => self::STORE_OVERVIEW_CACHE_MAX_AGE, 'store_documents_cache_max_age' => self::STORE_DOCUMENTS_CACHE_MAX_AGE,
        ], [
            'get_scoped_option' => function (string $optionName, $default = false) { return $this->getScopedOption($optionName, $default); },
            'update_scoped_option' => function (string $optionName, $value): bool { return $this->updateScopedOption($optionName, $value); },
            'delete_scoped_option' => function (string $optionName): void { $this->deleteScopedOption($optionName); },
            'make_request' => function (string $url, ?array $body = null, string $method = 'POST'): array { return $this->makeRequest($url, $body, $method); },
            'sanitize_connection_error_message' => function (string $message): string { return $this->sanitizeConnectionErrorMessage($message); },
        ]);
        return $this->storeClient;
    }
    private function createUploadClient(): GeminiUploadClient {
        if ($this->uploadClient instanceof GeminiUploadClient) { return $this->uploadClient; }
        $this->uploadClient = new GeminiUploadClient(self::API_BASE, self::API_UPLOAD_BASE, $this->apiKey, ['max_upload_file_bytes' => self::MAX_UPLOAD_FILE_BYTES], [
            'get_store_data' => function (): string { return $this->getStoreData(); },
            'clear_stores_cache' => function (): void { $this->clearStoresCache(); },
            'format_byte_size' => function (int $bytes): string { return $this->formatByteSize($bytes); },
            'make_request' => function (string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = self::DEFAULT_HTTP_TIMEOUT_SECONDS): array { return $this->makeRequest($url, $body, $method, $timeoutSeconds); },
            'get_upload_operation_timeout_seconds' => function (): int { return $this->getUploadOperationTimeoutSeconds(); },
            'get_upload_operation_poll_interval_ms' => function (): int { return $this->getUploadOperationPollIntervalMs(); },
            'log_info' => function (string $message): void { $this->logInfo($message); },
            'log_error' => function (string $message): void { $this->logError($message); },
        ]);
        return $this->uploadClient;
    }
    private function createVisionClient(): GeminiVisionClient {
        if ($this->visionClient instanceof GeminiVisionClient) { return $this->visionClient; }
        $this->visionClient = new GeminiVisionClient(self::API_BASE, $this->apiKey, self::DEFAULT_OCR_MODEL, [
            'make_request' => function (string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = self::DEFAULT_HTTP_TIMEOUT_SECONDS): array { return $this->makeRequest($url, $body, $method, $timeoutSeconds); },
            'extract_candidate_text' => function (array $result): string { return $this->extractCandidateText($result); },
            'get_summary_timeout_seconds' => function (): int { return $this->getSummaryTimeoutSeconds(); },
        ]);
        return $this->visionClient;
    }
    private function createSearchCoordinator(): GeminiSearchCoordinator {
        if ($this->searchCoordinator instanceof GeminiSearchCoordinator) { return $this->searchCoordinator; }
        $this->searchCoordinator = new GeminiSearchCoordinator(self::API_BASE, $this->model, $this->requestDiagnostics, [
            'default_system_retries' => self::DEFAULT_SYSTEM_RETRIES, 'default_summary_timeout_seconds' => self::DEFAULT_SUMMARY_TIMEOUT_SECONDS, 'generate_timeout_backoff_option' => self::GENERATE_TIMEOUT_BACKOFF_OPTION, 'generate_timeout_backoff_ttl_seconds' => self::GENERATE_TIMEOUT_BACKOFF_TTL_SECONDS,
        ], [
            'get_store_data' => function (): string { return $this->getStoreData(); }, 'get_prompt_descriptor' => function (?string $model = null, ?string $promptOverride = null): array { return $this->getPromptDescriptor($model, $promptOverride); },
            'build_search_body' => function (array $messages, string $storeName, string $model, string $systemInstruction): array { return $this->buildSearchBody($messages, $storeName, $model, $systemInstruction); },
            'append_excluded_sources_instruction' => function (string $systemInstruction, array $excludedSources): string { return $this->appendExcludedSourcesInstruction($systemInstruction, $excludedSources); },
            'make_request' => function (string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = self::DEFAULT_HTTP_TIMEOUT_SECONDS, int $maxAttempts = self::DEFAULT_SYSTEM_RETRIES, int $overallAttemptBase = 0, int $overallAttemptMax = self::DEFAULT_SYSTEM_RETRIES): array { return $this->makeRequest($url, $body, $method, $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax); },
            'make_streaming_request' => function (string $url, array $body, int $timeoutSeconds, int $maxAttempts = self::DEFAULT_SYSTEM_RETRIES, int $overallAttemptBase = 0, int $overallAttemptMax = self::DEFAULT_SYSTEM_RETRIES): array { return $this->makeStreamingRequest($url, $body, $timeoutSeconds, $maxAttempts, $overallAttemptBase, $overallAttemptMax); },
            'supports_thought_summaries' => function (string $model): bool { return $this->supportsThoughtSummaries($model); }, 'is_timeout_exception' => function (\Exception $exception): bool { return $this->isTimeoutException($exception); },
            'log_info' => function (string $message): void { $this->logInfo($message); }, 'log_warning' => function (string $message): void { $this->logWarning($message); },
            'format_runtime_log_context_suffix' => function (): string { return $this->formatRuntimeLogContextSuffix(); }, 'get_runtime_log_context' => function (): array { return $this->runtimeLogContext; },
            'set_runtime_log_context' => function (array $context): void { $this->runtimeLogContext = $context; }, 'format_retry_triplet' => function (int $attempt, int $phaseAttemptMax, int $overallAttemptMax): string { return $this->formatRetryTriplet($attempt, $phaseAttemptMax, $overallAttemptMax); },
            'clear_generate_timeout_backoff_for_request' => function (string $question, string $model, string $promptInstruction): void { $this->clearGenerateTimeoutBackoffForRequest($question, $model, $promptInstruction); },
            'record_generate_timeout_backoff' => function (string $question, string $model, string $promptInstruction, int $completedAttempts): void { $this->recordGenerateTimeoutBackoff($question, $model, $promptInstruction, $completedAttempts); },
            'record_model_status' => function (string $model, string $status, string $message = ''): void { $this->recordModelStatus($model, $status, $message); },
            'extract_candidate_text' => function (array $result): string { return $this->extractCandidateText($result); }, 'build_response_meta' => function (array $result, string $model, array $promptDescriptor = []): array { return $this->buildResponseMeta($result, $model, $promptDescriptor); },
            'is_gemini2_model' => function (string $model): bool { return $this->isGemini2Model($model); }, 'get_http_timeout_seconds' => function (string $model = ''): int { return $this->getHttpTimeoutSeconds($model); },
            'get_system_retry_count' => function (): int { return $this->getSystemRetryCount(); }, 'get_human_retry_count' => function (): int { return $this->getHumanRetryCount(); },
            'get_user_scoped_option' => function (string $optionName, $default = false) { return $this->getUserScopedOption($optionName, $default); },
            'update_user_scoped_option' => function (string $optionName, $value): bool { return $this->updateUserScopedOption($optionName, $value); },
            'delete_user_scoped_option' => function (string $optionName): void { $this->deleteUserScopedOption($optionName); },
        ]);
        return $this->searchCoordinator;
    }
    private function createPromptResolver(): GeminiPromptResolver {
        if ($this->promptResolver instanceof GeminiPromptResolver) { return $this->promptResolver; }
        $this->promptResolver = new GeminiPromptResolver([
            'option_custom_prompt' => self::OPTION_CUSTOM_PROMPT, 'option_model_prompts' => self::OPTION_MODEL_PROMPTS, 'option_model_prompt_names' => self::OPTION_MODEL_PROMPT_NAMES,
            'option_model_prompt_modes' => self::OPTION_MODEL_PROMPT_MODES, 'default_system_instruction' => self::DEFAULT_SYSTEM_INSTRUCTION, 'default_system_instruction_gemini2_appendix' => self::DEFAULT_SYSTEM_INSTRUCTION_GEMINI2_APPENDIX,
            'default_system_instruction_structured_appendix' => self::DEFAULT_SYSTEM_INSTRUCTION_STRUCTURED_APPENDIX,
        ], [
            'get_model' => function (): string { return $this->getModel(); },
            'get_scoped_option' => function (string $optionName, $default = false) { return $this->getScopedOption($optionName, $default); },
            'is_gemini2_model' => function (string $model): bool { return $this->isGemini2Model($model); },
        ]);
        return $this->promptResolver;
    }
    private function createModelRegistry(): GeminiModelRegistry {
        if ($this->modelRegistry instanceof GeminiModelRegistry) { return $this->modelRegistry; }
        $this->modelRegistry = new GeminiModelRegistry(self::API_BASE, $this->apiKey, [
            'default_model' => self::DEFAULT_MODEL, 'legacy_default_models' => self::LEGACY_DEFAULT_MODELS, 'official_latest_model_aliases' => self::OFFICIAL_LATEST_MODEL_ALIASES,
            'transient_models' => self::TRANSIENT_MODELS, 'option_model' => self::OPTION_MODEL, 'option_model_selection_mode' => self::OPTION_MODEL_SELECTION_MODE,
            'option_model_status' => self::OPTION_MODEL_STATUS, 'option_connection_status' => self::OPTION_CONNECTION_STATUS, 'model_selection_mode_default' => self::MODEL_SELECTION_MODE_DEFAULT,
            'model_selection_mode_custom' => self::MODEL_SELECTION_MODE_CUSTOM, 'stale_failed_model_retention_seconds' => self::STALE_FAILED_MODEL_RETENTION_SECONDS, 'model_test_timeout_seconds' => self::MODEL_TEST_TIMEOUT_SECONDS,
        ], [
            'make_request' => function (string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = self::DEFAULT_HTTP_TIMEOUT_SECONDS): array { return $this->makeRequest($url, $body, $method, $timeoutSeconds); },
            'extract_candidate_text' => function (array $response): string { return $this->extractCandidateText($response); },
            'extract_resolved_model_name' => function (array $response, string $fallback = ''): string { return $this->extractResolvedModelName($response, $fallback); },
            'is_timeout_exception' => function (\Exception $exception): bool { return $this->isTimeoutException($exception); },
            'sanitize_connection_error_message' => function (string $message): string { return $this->sanitizeConnectionErrorMessage($message); },
            'supports_file_search' => function (string $model): bool { return $this->supportsFileSearch($model); },
            'prepend_official_latest_aliases' => function (array $models): array { return $this->prependOfficialLatestAliases($models); },
            'should_mark_model_permanently_unavailable' => function (string $model, int $httpCode, string $message): bool { return $this->shouldMarkModelPermanentlyUnavailable($model, $httpCode, $message); },
            'extract_http_code_from_message' => function (string $message): int { return $this->extractHttpCodeFromMessage($message); },
        ]);
        return $this->modelRegistry;
    }
}
