<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Manages Gemini model discovery, selection, statuses, and connection checks.
 */
class GeminiModelRegistry {
    private string $apiBase;
    private string $apiKey;
    private string $defaultModel;
    private array $legacyDefaultModels;
    private array $officialLatestModelAliases;
    private string $transientModels;
    private string $optionModel;
    private string $optionModelSelectionMode;
    private string $optionModelStatus;
    private string $optionConnectionStatus;
    private string $modelSelectionModeDefault;
    private string $modelSelectionModeCustom;
    private int $staleFailedModelRetentionSeconds;
    private int $modelTestTimeoutSeconds;
    private $makeRequestCallback;
    private $extractCandidateTextCallback;
    private $extractResolvedModelNameCallback;
    private $isTimeoutExceptionCallback;
    private $sanitizeConnectionErrorMessageCallback;
    private $supportsFileSearchCallback;
    private $prependOfficialLatestAliasesCallback;
    private $shouldMarkModelPermanentlyUnavailableCallback;
    private $extractHttpCodeFromMessageCallback;

    /**
     * @param array<string,mixed> $options
     * @param array<string,callable|null> $callbacks
     */
    public function __construct(string $apiBase, string $apiKey, array $options, array $callbacks) {
        $this->apiBase = $apiBase;
        $this->apiKey = $apiKey;
        $this->defaultModel = (string) ($options['default_model'] ?? '');
        $this->legacyDefaultModels = isset($options['legacy_default_models']) && is_array($options['legacy_default_models']) ? $options['legacy_default_models'] : [];
        $this->officialLatestModelAliases = isset($options['official_latest_model_aliases']) && is_array($options['official_latest_model_aliases']) ? $options['official_latest_model_aliases'] : [];
        $this->transientModels = (string) ($options['transient_models'] ?? '');
        $this->optionModel = (string) ($options['option_model'] ?? '');
        $this->optionModelSelectionMode = (string) ($options['option_model_selection_mode'] ?? '');
        $this->optionModelStatus = (string) ($options['option_model_status'] ?? '');
        $this->optionConnectionStatus = (string) ($options['option_connection_status'] ?? '');
        $this->modelSelectionModeDefault = (string) ($options['model_selection_mode_default'] ?? 'default');
        $this->modelSelectionModeCustom = (string) ($options['model_selection_mode_custom'] ?? 'custom');
        $this->staleFailedModelRetentionSeconds = (int) ($options['stale_failed_model_retention_seconds'] ?? WEEK_IN_SECONDS);
        $this->modelTestTimeoutSeconds = (int) ($options['model_test_timeout_seconds'] ?? 20);
        $this->makeRequestCallback = $this->callableOrNull($callbacks, 'make_request');
        $this->extractCandidateTextCallback = $this->callableOrNull($callbacks, 'extract_candidate_text');
        $this->extractResolvedModelNameCallback = $this->callableOrNull($callbacks, 'extract_resolved_model_name');
        $this->isTimeoutExceptionCallback = $this->callableOrNull($callbacks, 'is_timeout_exception');
        $this->sanitizeConnectionErrorMessageCallback = $this->callableOrNull($callbacks, 'sanitize_connection_error_message');
        $this->supportsFileSearchCallback = $this->callableOrNull($callbacks, 'supports_file_search');
        $this->prependOfficialLatestAliasesCallback = $this->callableOrNull($callbacks, 'prepend_official_latest_aliases');
        $this->shouldMarkModelPermanentlyUnavailableCallback = $this->callableOrNull($callbacks, 'should_mark_model_permanently_unavailable');
        $this->extractHttpCodeFromMessageCallback = $this->callableOrNull($callbacks, 'extract_http_code_from_message');
    }

    /**
     * @return array<int,string>
     */
    public function getModels(bool $forceRefresh = false): array {
        $models = $this->getDefaultModels();
        $filteredCachedModels = [];

        if ($this->apiKey === '') {
            $this->recordConnectionStatus('missing', 'No API key saved.');
            return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($models)));
        }

        $cachedModels = get_transient($this->transientModels);
        if (is_array($cachedModels) && !empty($cachedModels)) {
            $filteredCachedModels = array_values(array_filter($cachedModels, function ($model): bool {
                return is_string($model) && $this->supportsFileSearch($model);
            }));
            if (!empty($filteredCachedModels)) {
                set_transient($this->transientModels, $filteredCachedModels, 12 * HOUR_IN_SECONDS);
                if (!$forceRefresh) {
                    return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($filteredCachedModels)));
                }
            }
        }

        try {
            $remoteModels = $this->fetchUsableModels();
            if (!empty($remoteModels)) {
                set_transient($this->transientModels, $remoteModels, 12 * HOUR_IN_SECONDS);
                $this->recordConnectionStatus('ok', 'Gemini API key is valid.');
                return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($remoteModels)));
            }
        } catch (\Exception $e) {
            $this->recordConnectionStatus('failed', $this->sanitizeConnectionErrorMessage($e->getMessage()));
        }

        if (!empty($filteredCachedModels)) {
            return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($filteredCachedModels)));
        }

        return apply_filters('geweb_aisearch_gemini_models', $this->prependOfficialLatestAliases($this->filterStaleFailedModels($models)));
    }

    public function getModel(): string {
        $selectionMode = $this->getModelSelectionMode();
        $storedModel = (string) get_option($this->optionModel, '');
        if ($this->isPermanentlyUnavailableModel($storedModel)) {
            $storedModel = '';
            update_option($this->optionModel, $this->defaultModel);
            update_option($this->optionModelSelectionMode, $this->modelSelectionModeDefault);
        }

        if ($selectionMode === $this->modelSelectionModeDefault) {
            if ($storedModel !== $this->defaultModel) {
                update_option($this->optionModel, $this->defaultModel);
            }

            return $this->defaultModel;
        }

        if ($storedModel !== '') {
            return $storedModel;
        }

        $models = $this->getModels();
        return $this->getDefaultModel($models);
    }

    public function getDefaultModel(?array $models = null): string {
        $models = $models ?? $this->getModels();
        return in_array($this->defaultModel, $models, true) ? $this->defaultModel : $models[0];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getModelStatuses(): array {
        $statuses = get_option($this->optionModelStatus, []);
        return is_array($statuses) ? $statuses : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function getConnectionStatus(): array {
        $status = get_option($this->optionConnectionStatus, []);
        return is_array($status) ? $status : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function validateConnection(): array {
        if ($this->apiKey === '') {
            return $this->storeConnectionValidationResult([
                'status' => 'missing',
                'message' => 'No API key saved.',
                'timestamp' => current_time('timestamp'),
            ]);
        }

        try {
            $remoteModels = $this->fetchUsableModels();
            $result = [
                'status' => !empty($remoteModels) ? 'ok' : 'failed',
                'message' => !empty($remoteModels) ? 'Gemini API key is valid.' : 'Gemini API returned no usable models.',
                'timestamp' => current_time('timestamp'),
            ];
            if (!empty($remoteModels)) {
                set_transient($this->transientModels, $remoteModels, 12 * HOUR_IN_SECONDS);
            }
            return $this->storeConnectionValidationResult($result);
        } catch (\Exception $e) {
            return $this->storeConnectionValidationResult([
                'status' => 'failed',
                'message' => $this->sanitizeConnectionErrorMessage($e->getMessage()),
                'timestamp' => current_time('timestamp'),
            ]);
        }
    }

    public function clearModelsCache(): void {
        delete_transient($this->transientModels);
    }

    /**
     * @return array<string,mixed>
     */
    public function testModel(string $model): array {
        $requestModel = trim($model);
        if ($requestModel === '') {
            return [
                'status' => 'failed',
                'message' => 'No model selected.',
                'timestamp' => current_time('timestamp'),
            ];
        }

        try {
            $testPrompt = 'Reply with OK.';
            $body = [
                'contents' => [[
                    'parts' => [[
                        'text' => $testPrompt,
                    ]],
                ]],
                'generationConfig' => [
                    'temperature' => 0,
                    'maxOutputTokens' => 8,
                ],
            ];

            $url = $this->apiBase . '/models/' . $requestModel . ':generateContent';
            $response = $this->makeRequest($url, $body, 'POST', $this->modelTestTimeoutSeconds);
            $responseText = $this->extractCandidateText($response);
            $resolvedModel = $this->extractResolvedModelName($response, $requestModel);
            $this->recordModelStatus($requestModel, 'ok', '', [
                'test_prompt' => $testPrompt,
                'test_response' => $responseText,
                'resolved_model' => $resolvedModel,
            ]);

            return [
                'status' => 'ok',
                'message' => 'Model responded successfully.',
                'test_prompt' => $testPrompt,
                'test_response' => $responseText,
                'resolved_model' => $resolvedModel,
                'timestamp' => current_time('timestamp'),
            ];
        } catch (\Exception $e) {
            $isTimeout = $this->isTimeoutException($e);
            $this->recordModelStatus($requestModel, $isTimeout ? 'timeout' : 'failed', $e->getMessage(), [
                'test_prompt' => isset($testPrompt) ? $testPrompt : 'Reply with OK.',
                'test_response' => '',
                'resolved_model' => '',
                'permanent_unavailable' => $this->shouldMarkModelPermanentlyUnavailable(
                    $requestModel,
                    $this->extractHttpCodeFromMessage($e->getMessage()),
                    $e->getMessage()
                ),
            ]);

            return [
                'status' => $isTimeout ? 'timeout' : 'failed',
                'message' => $this->sanitizeConnectionErrorMessage($e->getMessage()),
                'test_prompt' => isset($testPrompt) ? $testPrompt : 'Reply with OK.',
                'test_response' => '',
                'resolved_model' => '',
                'timestamp' => current_time('timestamp'),
            ];
        }
    }

    public function isDeprecatedModel(string $model): bool {
        return GeminiModelRules::isDeprecatedModel($model);
    }

    public function isPermanentlyUnavailableModel(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') {
            return false;
        }

        $statuses = $this->getModelStatuses();
        $entry = $statuses[$normalizedModel] ?? null;
        return is_array($entry) && !empty($entry['permanent_unavailable']);
    }

    public function recordModelStatus(string $model, string $status, string $message = '', array $details = []): void {
        if ($model === '') {
            return;
        }

        $statuses = $this->getModelStatuses();
        $statuses[$model] = [
            'status' => $status,
            'timestamp' => current_time('timestamp'),
            'message' => $message,
            'test_prompt' => isset($details['test_prompt']) ? trim((string) $details['test_prompt']) : '',
            'test_response' => isset($details['test_response']) ? trim((string) $details['test_response']) : '',
            'resolved_model' => isset($details['resolved_model']) ? trim((string) $details['resolved_model']) : '',
            'permanent_unavailable' => !empty($details['permanent_unavailable']),
        ];

        update_option($this->optionModelStatus, $statuses);
    }

    public function sanitizeConnectionErrorMessage(string $message): string {
        $message = trim($message);
        if ($message === '') {
            return 'Could not validate the API key. This plugin expects a Google AI Studio Gemini API key.';
        }

        if (GeminiModelRules::messageContainsAny($message, ['API_KEY_INVALID', 'API key not valid'])) {
            return 'The API key is invalid. Enter a valid Google AI Studio Gemini API key.';
        }

        if (GeminiModelRules::messageContainsAny($message, ['PERMISSION_DENIED', 'permission denied', 'forbidden'])) {
            return 'The API key does not have permission to use the Gemini API or the selected resource.';
        }

        if (GeminiModelRules::messageContainsAny($message, ['RESOURCE_EXHAUSTED', 'quota', 'rate limit', 'too many requests'])) {
            return 'The Gemini API quota or rate limit has been reached for this project.';
        }

        if (GeminiModelRules::messageContainsAny($message, ['SERVICE_DISABLED', 'api has not been used', 'is not enabled'])) {
            return 'The Gemini API is not enabled for this Google project.';
        }

        if (GeminiModelRules::messageContainsAny($message, ['API key expired', 'API_KEY_SERVICE_BLOCKED', 'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_IP_ADDRESS_BLOCKED'])) {
            return 'This API key is blocked by its Google API key restrictions.';
        }

        if (GeminiModelRules::messageContainsAny($message, ['UNAVAILABLE', 'timed out', 'could not resolve host', 'network'])) {
            return 'The Gemini API could not be reached right now. Please try again.';
        }

        if (preg_match('/HTTP code\s+(\d{3})/', $message, $matches)) {
            $httpCode = (int) $matches[1];
            if ($httpCode === 400) {
                return 'The Gemini API rejected the request (HTTP 400). Check the API key and request settings.';
            }
            if ($httpCode === 401) {
                return 'Authentication failed (HTTP 401). The API key is missing, invalid, or not accepted.';
            }
            if ($httpCode === 403) {
                return 'Access denied (HTTP 403). The API key may lack permission or be blocked by restrictions.';
            }
            if ($httpCode === 429) {
                return 'The Gemini API rate limit or quota has been exceeded (HTTP 429).';
            }
            if ($httpCode >= 500) {
                return 'The Gemini API returned a server error (HTTP ' . $httpCode . '). Please try again.';
            }

            return 'Gemini API request failed (HTTP ' . $httpCode . ').';
        }

        return preg_replace('/\s+/', ' ', $message) ?: 'Could not validate the API key. This plugin expects a Google AI Studio Gemini API key.';
    }

    private function getModelSelectionMode(): string {
        $storedMode = (string) get_option($this->optionModelSelectionMode, '');
        if (in_array($storedMode, [$this->modelSelectionModeDefault, $this->modelSelectionModeCustom], true)) {
            return $storedMode;
        }

        $storedModel = (string) get_option($this->optionModel, '');
        $resolvedMode = in_array($storedModel, array_merge([''], $this->legacyDefaultModels), true)
            ? $this->modelSelectionModeDefault
            : $this->modelSelectionModeCustom;

        update_option($this->optionModelSelectionMode, $resolvedMode);

        return $resolvedMode;
    }

    /**
     * @return array<int,string>
     */
    private function getDefaultModels(): array {
        $models = [
            $this->defaultModel,
            'gemini-3-flash-preview',
            'gemini-3.1-flash-lite-preview',
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
        ];

        $cachedModels = get_transient($this->transientModels);
        if (is_array($cachedModels)) {
            foreach ($cachedModels as $model) {
                if (is_string($model) && $this->supportsFileSearch($model)) {
                    $models[] = $model;
                }
            }
        }

        $storedModel = (string) get_option($this->optionModel, '');
        if ($storedModel !== '' && $this->supportsFileSearch($storedModel)) {
            $models[] = $storedModel;
        }

        return array_values(array_unique($models));
    }

    /**
     * @param array<int,string> $models
     * @return array<int,string>
     */
    private function filterStaleFailedModels(array $models): array {
        $statuses = $this->getModelStatuses();
        $now = current_time('timestamp');
        $connectionStatus = $this->getConnectionStatus();
        $hasRecentSuccessfulConnection = is_array($connectionStatus)
            && (($connectionStatus['status'] ?? '') === 'ok')
            && ($now - (int) ($connectionStatus['timestamp'] ?? 0)) < $this->staleFailedModelRetentionSeconds;

        return array_values(array_filter($models, function ($model) use ($statuses, $now, $hasRecentSuccessfulConnection): bool {
            if (!is_string($model) || trim($model) === '') {
                return false;
            }

            $status = $statuses[$model] ?? null;
            if (!is_array($status) || ($status['status'] ?? '') !== 'failed') {
                return true;
            }

            if (!empty($status['permanent_unavailable'])) {
                return false;
            }

            $timestamp = isset($status['timestamp']) ? (int) $status['timestamp'] : 0;
            if ($timestamp <= 0) {
                return true;
            }

            return ($now - $timestamp) < $this->staleFailedModelRetentionSeconds || !$hasRecentSuccessfulConnection;
        }));
    }

    /**
     * @return array<string,mixed>
     */
    private function storeConnectionValidationResult(array $result): array {
        update_option($this->optionConnectionStatus, $result);
        return $result;
    }

    private function recordConnectionStatus(string $status, string $message = ''): void {
        if ($status === 'failed') {
            $message = $this->sanitizeConnectionErrorMessage($message);
        }

        update_option($this->optionConnectionStatus, [
            'status' => $status,
            'timestamp' => current_time('timestamp'),
            'message' => $message,
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function fetchUsableModels(): array {
        $result = $this->makeRequest($this->apiBase . '/models', null, 'GET');
        $models = [];

        foreach (($result['models'] ?? []) as $model) {
            $shortName = $this->extractUsableModelName($model);
            if ($shortName !== '') {
                $models[] = $shortName;
            }
        }

        $models = array_values(array_unique($models));
        sort($models);

        return $models;
    }

    /**
     * @param mixed $model
     */
    private function extractUsableModelName($model): string {
        if (!is_array($model)) {
            return '';
        }

        $name = isset($model['name']) ? (string) $model['name'] : '';
        $methods = isset($model['supportedGenerationMethods']) && is_array($model['supportedGenerationMethods'])
            ? $model['supportedGenerationMethods']
            : [];

        if ($name === '' || !in_array('generateContent', $methods, true)) {
            return '';
        }

        $shortName = preg_replace('#^models/#', '', $name);
        if (!is_string($shortName) || $shortName === '') {
            return '';
        }

        if (strpos($shortName, 'embedding') !== false || !$this->supportsFileSearch($shortName)) {
            return '';
        }

        return $shortName;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function makeRequest(string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = 90): array {
        if ($this->makeRequestCallback === null) {
            throw new \Exception('Gemini model request callback is not configured.');
        }

        return call_user_func($this->makeRequestCallback, $url, $body, $method, $timeoutSeconds);
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractCandidateText(array $response): string {
        return $this->extractCandidateTextCallback !== null ? (string) call_user_func($this->extractCandidateTextCallback, $response) : '';
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractResolvedModelName(array $response, string $fallback = ''): string {
        return $this->extractResolvedModelNameCallback !== null ? (string) call_user_func($this->extractResolvedModelNameCallback, $response, $fallback) : $fallback;
    }

    private function isTimeoutException(\Exception $exception): bool {
        return $this->isTimeoutExceptionCallback !== null ? (bool) call_user_func($this->isTimeoutExceptionCallback, $exception) : false;
    }

    private function supportsFileSearch(string $model): bool {
        return $this->supportsFileSearchCallback !== null ? (bool) call_user_func($this->supportsFileSearchCallback, $model) : false;
    }

    /**
     * @param array<int,string> $models
     * @return array<int,string>
     */
    private function prependOfficialLatestAliases(array $models): array {
        if ($this->prependOfficialLatestAliasesCallback !== null) {
            return (array) call_user_func($this->prependOfficialLatestAliasesCallback, $models);
        }

        return GeminiModelRules::prependOfficialLatestAliases($models, $this->officialLatestModelAliases);
    }

    private function shouldMarkModelPermanentlyUnavailable(string $model, int $httpCode, string $message): bool {
        return $this->shouldMarkModelPermanentlyUnavailableCallback !== null
            ? (bool) call_user_func($this->shouldMarkModelPermanentlyUnavailableCallback, $model, $httpCode, $message)
            : GeminiModelRules::shouldMarkModelPermanentlyUnavailable($model, $httpCode, $message);
    }

    private function extractHttpCodeFromMessage(string $message): int {
        return $this->extractHttpCodeFromMessageCallback !== null
            ? (int) call_user_func($this->extractHttpCodeFromMessageCallback, $message)
            : GeminiModelRules::extractHttpCodeFromMessage($message);
    }

    private function callableOrNull(array $callbacks, string $key) {
        return isset($callbacks[$key]) && is_callable($callbacks[$key]) ? $callbacks[$key] : null;
    }
}
