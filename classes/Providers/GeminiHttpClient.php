<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Executes Gemini HTTP and SSE requests with retry logging and diagnostics.
 */
class GeminiHttpClient {
    private string $apiKey;
    private GeminiRequestDiagnostics $diagnostics;
    private $logInfoCallback;
    private $logErrorCallback;
    private $contextSuffixCallback;
    private $isTimeoutCallback;
    private $apiFailureCallback;
    private $streamProgressCallback;

    /**
     * @param array<string,callable|null> $callbacks
     */
    public function __construct(string $apiKey, GeminiRequestDiagnostics $diagnostics, array $callbacks = []) {
        $this->apiKey = $apiKey;
        $this->diagnostics = $diagnostics;
        $this->logInfoCallback = isset($callbacks['log_info']) && is_callable($callbacks['log_info']) ? $callbacks['log_info'] : null;
        $this->logErrorCallback = isset($callbacks['log_error']) && is_callable($callbacks['log_error']) ? $callbacks['log_error'] : null;
        $this->contextSuffixCallback = isset($callbacks['context_suffix']) && is_callable($callbacks['context_suffix']) ? $callbacks['context_suffix'] : null;
        $this->isTimeoutCallback = isset($callbacks['is_timeout']) && is_callable($callbacks['is_timeout']) ? $callbacks['is_timeout'] : null;
        $this->apiFailureCallback = isset($callbacks['api_failure']) && is_callable($callbacks['api_failure']) ? $callbacks['api_failure'] : null;
        $this->streamProgressCallback = isset($callbacks['stream_progress']) && is_callable($callbacks['stream_progress']) ? $callbacks['stream_progress'] : null;
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     * @throws \Exception
     */
    public function request(string $url, ?array $body = null, string $method = 'POST', int $timeoutSeconds = 90, int $maxAttempts = 2, int $overallAttemptBase = 0, int $overallAttemptMax = 2): array {
        if ($this->apiKey === '') {
            throw new ConfigurationException('Configuration error');
        }

        $timeoutSeconds = max(5, $timeoutSeconds);
        $attemptTimeoutStepSeconds = max(1, (int) floor($timeoutSeconds / max(1, $overallAttemptBase + 1)));
        $attemptTimeoutSeconds = $timeoutSeconds;
        $args = [
            'method' => $method,
            'timeout' => $attemptTimeoutSeconds,
            'headers' => [
                'x-goog-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $bodyBytes = isset($args['body']) ? strlen((string) $args['body']) : 0;
        $attempt = 0;
        $maxAttempts = max(1, min(4, $maxAttempts));
        $overallAttemptMax = max($maxAttempts, $overallAttemptMax);

        do {
            $attempt++;
            $args['timeout'] = $attemptTimeoutSeconds;
            $startedAt = microtime(true);
            $attemptStartedAt = time();
            $overallAttempt = $overallAttemptBase + $attempt;
            $retryTriplet = $this->formatRetryTriplet($overallAttempt, $maxAttempts, $overallAttemptMax);
            $this->logInfo(sprintf(
                'request start method=%s attempt=%d/%d overall=%d/%d retry=%s timeout_s=%d body_bytes=%d endpoint="%s"%s',
                $method,
                $attempt,
                $maxAttempts,
                $overallAttempt,
                $overallAttemptMax,
                $retryTriplet,
                $attemptTimeoutSeconds,
                $bodyBytes,
                $url,
                $this->formatContextSuffix()
            ));

            $response = wp_remote_request($url, $args);
            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

            if (is_wp_error($response)) {
                $errorMessage = $response->get_error_message();
                $this->recordAttempt($url, $method, $attempt, $maxAttempts, $overallAttempt, $overallAttemptMax, $retryTriplet, $attemptStartedAt, $elapsedMs, 0, 'transport_error', $errorMessage);
                $this->logError(sprintf(
                    'request transport_error method=%s attempt=%d/%d overall=%d/%d retry=%s elapsed_ms=%d endpoint="%s" message="%s"%s',
                    $method,
                    $attempt,
                    $maxAttempts,
                    $overallAttempt,
                    $overallAttemptMax,
                    $retryTriplet,
                    $elapsedMs,
                    $url,
                    $errorMessage,
                    $this->formatContextSuffix()
                ));

                if ($attempt < $maxAttempts && $this->isTimeoutException(new \Exception($errorMessage))) {
                    $attemptTimeoutSeconds += $attemptTimeoutStepSeconds;
                    sleep(1);
                    continue;
                }

                throw new \Exception(esc_html(sprintf(
                    'API request failed after %d ms: %s',
                    $elapsedMs,
                    $errorMessage
                )));
            }

            $httpCode = wp_remote_retrieve_response_code($response);
            $responseBody = wp_remote_retrieve_body($response);
            $responseBytes = strlen($responseBody);
            $this->recordAttempt($url, $method, $attempt, $maxAttempts, $overallAttempt, $overallAttemptMax, $retryTriplet, $attemptStartedAt, $elapsedMs, $httpCode, ($httpCode >= 200 && $httpCode < 300) ? 'ok' : 'api_error', $httpCode >= 200 && $httpCode < 300 ? '' : $this->diagnostics->formatApiFailureMessage('API request failed', $elapsedMs, $httpCode, $responseBody));
            $this->logInfo(sprintf(
                'request response method=%s attempt=%d/%d overall=%d/%d retry=%s http_code=%d elapsed_ms=%d response_bytes=%d endpoint="%s"%s',
                $method,
                $attempt,
                $maxAttempts,
                $overallAttempt,
                $overallAttemptMax,
                $retryTriplet,
                $httpCode,
                $elapsedMs,
                $responseBytes,
                $url,
                $this->formatContextSuffix()
            ));

            if ($httpCode < 200 || $httpCode >= 300) {
                $responseSnippet = trim(substr(preg_replace('/\s+/', ' ', $responseBody), 0, 400));

                if ($attempt < $maxAttempts && ($httpCode === 503 || ($httpCode === 429 && !$this->diagnostics->isSpendingCapError($responseBody)))) {
                    $this->logInfo(sprintf(
                        'request retry_after_api_error method=%s attempt=%d/%d http_code=%d',
                        $method,
                        $attempt,
                        $maxAttempts,
                        $httpCode
                    ));
                    sleep(2);
                    continue;
                }

                $this->handleApiFailure($url, $httpCode, $responseBody);
                $this->logError(sprintf(
                    'request api_error method=%s http_code=%d elapsed_ms=%d endpoint="%s" response_snippet="%s"%s',
                    $method,
                    $httpCode,
                    $elapsedMs,
                    $url,
                    $responseSnippet,
                    $this->formatContextSuffix()
                ));
                throw new \Exception($this->diagnostics->formatApiFailureMessage('API request failed', $elapsedMs, $httpCode, $responseBody));
            }

            $result = json_decode($responseBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logError(sprintf(
                    'request json_decode_error method=%s elapsed_ms=%d endpoint="%s" json_error="%s"%s',
                    $method,
                    $elapsedMs,
                    $url,
                    json_last_error_msg(),
                    $this->formatContextSuffix()
                ));
                throw new \Exception(esc_html(sprintf(
                    'Failed to decode JSON response after %d ms: %s',
                    $elapsedMs,
                    json_last_error_msg()
                )));
            }

            return $result;
        } while ($attempt < $maxAttempts);

        return [];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     * @throws \Exception
     */
    public function streamingRequest(string $url, array $body, int $timeoutSeconds = 90, int $maxAttempts = 2, int $overallAttemptBase = 0, int $overallAttemptMax = 2): array {
        if ($this->apiKey === '') {
            throw new ConfigurationException('Configuration error');
        }

        if (!function_exists('curl_init')) {
            throw new \Exception('cURL extension is required for Gemini streaming requests.');
        }

        $timeoutSeconds = max(5, $timeoutSeconds);
        $attemptTimeoutStepSeconds = max(1, (int) floor($timeoutSeconds / max(1, $overallAttemptBase + 1)));
        $attemptTimeoutSeconds = $timeoutSeconds;
        $encodedBody = wp_json_encode($body);
        if (!is_string($encodedBody) || $encodedBody === '') {
            throw new \Exception('Could not encode Gemini streaming request body.');
        }

        $bodyBytes = strlen($encodedBody);
        $attempt = 0;
        $maxAttempts = max(1, min(4, $maxAttempts));
        $overallAttemptMax = max($maxAttempts, $overallAttemptMax);

        do {
            $attempt++;
            $overallAttempt = $overallAttemptBase + $attempt;
            $startedAt = microtime(true);
            $attemptStartedAt = time();
            $retryTriplet = $this->formatRetryTriplet($overallAttempt, $maxAttempts, $overallAttemptMax);
            $this->logInfo(sprintf(
                'stream start method=%s attempt=%d/%d overall=%d/%d retry=%s timeout_s=%d body_bytes=%d endpoint="%s"%s',
                'POST',
                $attempt,
                $maxAttempts,
                $overallAttempt,
                $overallAttemptMax,
                $retryTriplet,
                $attemptTimeoutSeconds,
                $bodyBytes,
                $url,
                $this->formatContextSuffix()
            ));

            $aggregate = [];
            $rawBuffer = '';
            $lineBuffer = '';
            $eventDataLines = [];
            $streamThoughts = [];
            $streamAnswer = '';
            $streamErrorMessage = '';
            $streamAccumulator = new GeminiStreamingResponseAccumulator($this->streamProgressCallback);
            $curl = curl_init($url);
            if ($curl === false) {
                throw new \Exception('Could not initialize cURL for Gemini streaming request.');
            }

            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encodedBody,
                CURLOPT_HTTPHEADER => [
                    'x-goog-api-key: ' . $this->apiKey,
                    'Content-Type: application/json',
                    'Accept: text/event-stream',
                ],
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_HEADER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_CONNECTTIMEOUT => min(30, $attemptTimeoutSeconds),
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => $attemptTimeoutSeconds,
                CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use ($streamAccumulator, &$aggregate, &$rawBuffer, &$lineBuffer, &$eventDataLines, &$streamThoughts, &$streamAnswer, &$streamErrorMessage) {
                    $rawBuffer .= $chunk;
                    $lineBuffer .= $chunk;

                    while (($lineBreakPos = strpos($lineBuffer, "\n")) !== false) {
                        $line = substr($lineBuffer, 0, $lineBreakPos);
                        $lineBuffer = substr($lineBuffer, $lineBreakPos + 1);
                        $line = rtrim($line, "\r");

                        if ($line === '') {
                            if (!$streamAccumulator->flushEventDataLines($eventDataLines, $aggregate, $streamThoughts, $streamAnswer, $streamErrorMessage)) {
                                return 0;
                            }
                            continue;
                        }

                        if (strpos($line, 'data:') === 0) {
                            $eventDataLines[] = ltrim(substr($line, 5));
                        }
                    }

                    return strlen($chunk);
                },
            ]);

            $execResult = curl_exec($curl);
            $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($curl);
            curl_close($curl);

            if ($lineBuffer !== '' || !empty($eventDataLines)) {
                $lineBuffer = '';
                if (!$streamAccumulator->flushEventDataLines($eventDataLines, $aggregate, $streamThoughts, $streamAnswer, $streamErrorMessage)) {
                    $execResult = false;
                }
            }

            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            if ($streamErrorMessage !== '') {
                $this->recordAttempt($url, 'POST', $attempt, $maxAttempts, $overallAttempt, $overallAttemptMax, $retryTriplet, $attemptStartedAt, $elapsedMs, 0, 'parse_error', $streamErrorMessage);
                $this->logError(sprintf(
                    'stream parse_error method=%s attempt=%d/%d overall=%d/%d retry=%s elapsed_ms=%d endpoint="%s" message="%s"%s',
                    'POST',
                    $attempt,
                    $maxAttempts,
                    $overallAttempt,
                    $overallAttemptMax,
                    $retryTriplet,
                    $elapsedMs,
                    $url,
                    $streamErrorMessage,
                    $this->formatContextSuffix()
                ));
                throw new \Exception($streamErrorMessage);
            }

            if ($execResult === false) {
                $errorMessage = $curlError !== '' ? $curlError : 'Unknown cURL streaming error';
                $timedOut = $this->isTimeoutException(new \Exception($errorMessage));
                $hasUsableAggregate = !empty($aggregate)
                    && (
                        trim($streamAnswer) !== ''
                        || ($httpCode >= 200 && $httpCode < 300)
                    );

                if ($timedOut && $hasUsableAggregate) {
                    $responseBytes = strlen($rawBuffer);
                    $this->recordAttempt($url, 'POST', $attempt, $maxAttempts, $overallAttempt, $overallAttemptMax, $retryTriplet, $attemptStartedAt, $elapsedMs, $httpCode, 'timeout_recovered', $errorMessage);
                    $this->logWarning(sprintf(
                        'stream timeout_recovered method=%s attempt=%d/%d overall=%d/%d retry=%s http_code=%d elapsed_ms=%d response_bytes=%d endpoint="%s" message="%s"%s',
                        'POST',
                        $attempt,
                        $maxAttempts,
                        $overallAttempt,
                        $overallAttemptMax,
                        $retryTriplet,
                        $httpCode,
                        $elapsedMs,
                        $responseBytes,
                        $url,
                        $errorMessage,
                        $this->formatContextSuffix()
                    ));

                    return $aggregate;
                }

                $this->recordAttempt($url, 'POST', $attempt, $maxAttempts, $overallAttempt, $overallAttemptMax, $retryTriplet, $attemptStartedAt, $elapsedMs, 0, 'transport_error', $errorMessage);
                $this->logError(sprintf(
                    'stream transport_error method=%s attempt=%d/%d overall=%d/%d retry=%s elapsed_ms=%d endpoint="%s" message="%s"%s',
                    'POST',
                    $attempt,
                    $maxAttempts,
                    $overallAttempt,
                    $overallAttemptMax,
                    $retryTriplet,
                    $elapsedMs,
                    $url,
                    $errorMessage,
                    $this->formatContextSuffix()
                ));

                if ($attempt < $maxAttempts && $timedOut) {
                    $attemptTimeoutSeconds += $attemptTimeoutStepSeconds;
                    sleep(1);
                    continue;
                }

                throw new \Exception(esc_html(sprintf(
                    'API streaming request failed after %d ms: %s',
                    $elapsedMs,
                    $errorMessage
                )));
            }

            $responseBytes = strlen($rawBuffer);
            $this->recordAttempt($url, 'POST', $attempt, $maxAttempts, $overallAttempt, $overallAttemptMax, $retryTriplet, $attemptStartedAt, $elapsedMs, $httpCode, ($httpCode >= 200 && $httpCode < 300) ? 'ok' : 'api_error', $httpCode >= 200 && $httpCode < 300 ? '' : $this->diagnostics->formatApiFailureMessage('API streaming request failed', $elapsedMs, $httpCode, $rawBuffer));
            $this->logInfo(sprintf(
                'stream response method=%s attempt=%d/%d overall=%d/%d retry=%s http_code=%d elapsed_ms=%d response_bytes=%d endpoint="%s"%s',
                'POST',
                $attempt,
                $maxAttempts,
                $overallAttempt,
                $overallAttemptMax,
                $retryTriplet,
                $httpCode,
                $elapsedMs,
                $responseBytes,
                $url,
                $this->formatContextSuffix()
            ));

            if ($httpCode < 200 || $httpCode >= 300) {
                $responseSnippet = trim(substr(preg_replace('/\s+/', ' ', $rawBuffer), 0, 400));
                if ($attempt < $maxAttempts && ($httpCode === 503 || ($httpCode === 429 && !$this->diagnostics->isSpendingCapError($rawBuffer)))) {
                    $this->logInfo(sprintf(
                        'stream retry_after_api_error method=%s attempt=%d/%d http_code=%d',
                        'POST',
                        $attempt,
                        $maxAttempts,
                        $httpCode
                    ));
                    sleep(2);
                    continue;
                }

                $this->logError(sprintf(
                    'stream api_error method=%s http_code=%d elapsed_ms=%d endpoint="%s" response_snippet="%s"%s',
                    'POST',
                    $httpCode,
                    $elapsedMs,
                    $url,
                    $responseSnippet,
                    $this->formatContextSuffix()
                ));
                throw new \Exception($this->diagnostics->formatApiFailureMessage('API streaming request failed', $elapsedMs, $httpCode, $rawBuffer));
            }

            if (empty($aggregate)) {
                throw new \Exception('Gemini streaming request returned no usable chunks.');
            }

            return $aggregate;
        } while ($attempt < $maxAttempts);

        return [];
    }

    private function recordAttempt(string $url, string $method, int $attempt, int $maxAttempts, int $overallAttempt, int $overallAttemptMax, string $retryTriplet, int $startedAt, int $elapsedMs, int $httpCode, string $status, string $message = ''): void {
        $this->diagnostics->recordAttempt(
            $method,
            $attempt,
            $maxAttempts,
            $overallAttempt,
            $overallAttemptMax,
            $retryTriplet,
            GeminiModelRules::extractRequestedModelFromUrl($url),
            $startedAt,
            $elapsedMs,
            $httpCode,
            $status,
            $message
        );
    }

    private function handleApiFailure(string $url, int $httpCode, string $responseBody): void {
        if ($this->apiFailureCallback !== null) {
            call_user_func(
                $this->apiFailureCallback,
                GeminiModelRules::extractRequestedModelFromUrl($url),
                $httpCode,
                $responseBody
            );
        }
    }

    private function isTimeoutException(\Exception $exception): bool {
        return $this->isTimeoutCallback !== null
            ? (bool) call_user_func($this->isTimeoutCallback, $exception)
            : false;
    }

    private function logInfo(string $message): void {
        if ($this->logInfoCallback !== null) {
            call_user_func($this->logInfoCallback, $message);
        }
    }

    private function logError(string $message): void {
        if ($this->logErrorCallback !== null) {
            call_user_func($this->logErrorCallback, $message);
        }
    }

    private function formatContextSuffix(): string {
        return $this->contextSuffixCallback !== null
            ? (string) call_user_func($this->contextSuffixCallback)
            : '';
    }

    private function formatRetryTriplet(int $attempt, int $phaseAttemptMax, int $overallAttemptMax): string {
        return $attempt . '/' . $phaseAttemptMax . '/' . $overallAttemptMax;
    }
}
