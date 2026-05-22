<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Configuration exception
 */
class ConfigurationException extends \Exception {}

/**
 * Gemini AI Provider
 *
 * Handles all interactions with Google Gemini API
 */
class Gemini implements AIProviderInterface {
    use GeminiHelpersTrait, GeminiStoreTrait;

    /**
     * API endpoints
     */
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Option key for Model
     */
    public const OPTION_TIMEOUT_FLASH = 'geweb_aisearch_timeout_flash';
    public const OPTION_TIMEOUT_PRO = 'geweb_aisearch_timeout_pro';
    public const OPTION_SYSTEM_RETRIES = 'geweb_aisearch_gemini_system_retries';
    public const OPTION_HUMAN_RETRIES = 'geweb_aisearch_gemini_human_retries';
    public const DEFAULT_HTTP_TIMEOUT_SECONDS = 90;
    public const DEFAULT_PRO_HTTP_TIMEOUT_SECONDS = 90;
    public const DEFAULT_SYSTEM_RETRIES = 2;
    public const DEFAULT_HUMAN_RETRIES = 2;

    /**
     * @var string Gemini API key
     */
    private string $apiKey;

    /**
     * @var string Selected model name
     */
    private string $model;

    private GeminiRequestDiagnostics $requestDiagnostics;

    /**
     * @var callable|null
     */
    private $streamProgressCallback = null;

    /**
     * Constructor
     *
     * @param string $apiKey Gemini API key
     * @param string $model Model name
     */
    public function __construct() {
        $encryption = new Encryption();

        $this->apiKey = $encryption->getApiKey();
        $this->model = $this->getModel();
        $this->requestDiagnostics = new GeminiRequestDiagnostics();
    }

    /**
     * @param callable|null $callback Receives array{stage:string,label:string,thoughts:array<int,string>,answer_preview:string}
     */
    public function setStreamProgressCallback($callback): void {
        $this->streamProgressCallback = is_callable($callback) ? $callback : null;
    }

    public function hasStoreDocumentsCache(string $storeName): bool {
        return $this->hasCachedStoreDocuments($storeName);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLastRequestAttempts(): array {
        return $this->requestDiagnostics->getAttempts();
    }

    /**
     * @return string
     */
    public function getProviderKey(): string {
        return ProviderFactory::PROVIDER_GEMINI;
    }

    /**
     * @return string
     */
    public function getProviderLabel(): string {
        return 'Google Gemini';
    }

    /**
     * Upload document to Gemini File Search Store
     *
     * @param string $content Markdown document content
     * @param int $postId WordPress post ID
     * @return string Document name in Gemini system
     * @throws \Exception On upload error
     */
    public function uploadDocument(string $content, int $postId): string {
        return $this->createUploadClient()->uploadDocument($content, $postId);
    }

    /**
     * Upload a named markdown document to Gemini File Search Store.
     *
     * @param string $content Markdown document content
     * @param string $displayName Uploaded filename shown in Gemini
     * @return string
     * @throws \Exception
     */
    public function uploadNamedDocument(string $content, string $displayName): string {
        return $this->createUploadClient()->uploadNamedDocument($content, $displayName);
    }

    /**
     * Upload a local file to Gemini File Search Store without converting it.
     *
     * @param string $filePath Absolute local path
     * @param string $displayName Uploaded filename shown in Gemini
     * @param string $mimeType File MIME type
     * @return string
     * @throws \Exception
     */
    public function uploadLocalFile(string $filePath, string $displayName, string $mimeType): string {
        return $this->createUploadClient()->uploadLocalFile($filePath, $displayName, $mimeType);
    }

    public function extractImageText(string $filePath, string $mimeType): string {
        return $this->createVisionClient()->extractImageText($filePath, $mimeType);
    }

    public function describeImage(string $filePath, string $mimeType): string {
        return $this->createVisionClient()->describeImage($filePath, $mimeType);
    }

    /**
     * Delete document from Gemini File Search Store
     *
     * @param string $documentName Full document name in Gemini system
     * @return void
     * @throws \Exception On deletion error
     */
    public function deleteDocument(string $documentName): void {
        $this->createUploadClient()->deleteDocument($documentName);
    }

    /**
     * Search in documents using Gemini File Search
     *
     * @param array $messages Array of messages in format [['role' => 'user', 'content' => '...'], ...]
     * @return array Response ['answer' => '...', 'sources' => [...]] or ['answer' => '...']
     * @throws \Exception On API or network error
     */
    public function search(array $messages, ?string $model = null, ?string $promptOverride = null, array $excludedSources = []): array {
        if (empty($this->apiKey)) {
            throw new ConfigurationException('Configuration error');
        }

        return $this->createSearchCoordinator()->search($messages, $model, $promptOverride, $excludedSources);
    }

    /**
     * Build a concise API-generated summary for older conversation turns.
     *
     * @param array<int,array<string,mixed>> $messages
     * @param string|null $model
     * @param int $maxItems
     * @return string
     */
    public function summarizeConversationForContext(array $messages, ?string $model = null, int $maxItems = 5, string $previousSummary = ''): string {
        $normalizedItems = [];

        foreach ($messages as $message) {
            $normalized = is_array($message) ? $this->normalizeMessageForSummary($message) : null;
            if ($normalized !== null) {
                $normalizedItems[] = $normalized;
            }
        }

        if (empty($normalizedItems)) {
            return '';
        }

        $lines = [];
        foreach ($normalizedItems as $item) {
            $lines[] = '- ' . ucfirst((string) $item['role']) . ': ' . (string) $item['content'];
        }

        $maxItems = max(1, min(8, $maxItems));
        $previousSummary = trim($previousSummary);
        $prompt = "Summarize the earlier conversation for continuation.\n" .
            "Return exactly a short Dutch summary with at most {$maxItems} bullet points.\n" .
            "Focus only on: verified facts, corrections, open questions, and constraints.\n" .
            "Do not include markdown code blocks.\n\n" .
            ($previousSummary !== ''
                ? ("Previous summary (N-1), refine and keep only still relevant items:\n" . $previousSummary . "\n\n")
                : '') .
            "Conversation:\n" . implode("\n", $lines);

        $requestModel = is_string($model) && $model !== '' ? $model : $this->model;
        $url = self::API_BASE . '/models/' . $requestModel . ':generateContent';
        $body = [
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.1,
            ],
        ];

        $result = $this->makeRequest($url, $body, 'POST', $this->getSummaryTimeoutSeconds());
        $summary = $this->extractCandidateText($result);
        if ($summary === '') {
            return '';
        }

        return "Earlier conversation summary:\n" . trim($summary);
    }
}
