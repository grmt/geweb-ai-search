<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class DocumentAiOcrService {
    public const OPTION_PROJECT_ID = 'geweb_aisearch_document_ai_project_id';
    public const OPTION_LOCATION = 'geweb_aisearch_document_ai_location';
    public const OPTION_PROCESSOR_ID = 'geweb_aisearch_document_ai_processor_id';
    public const OPTION_SERVICE_ACCOUNT_JSON = 'geweb_aisearch_document_ai_service_account_json_encrypted';
    private const TOKEN_TRANSIENT = 'geweb_aisearch_document_ai_access_token';
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const DOCUMENT_AI_SCOPE = 'https://www.googleapis.com/auth/cloud-platform';
    private const GEMINI_API_BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function isConfigured(): bool {
        return $this->getProjectId() !== ''
            && $this->getLocation() !== ''
            && $this->getProcessorId() !== ''
            && $this->getServiceAccountJson() !== '';
    }

    public function convertPdfToMarkdown(string $filePath): string {
        if (!$this->isConfigured()) {
            throw new ReferencedDocumentException('Document AI OCR is not configured.');
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new ReferencedDocumentException('Could not read local PDF for Document AI OCR.');
        }

        $document = $this->processDocument($content);
        $rawText = trim((string) ($document['text'] ?? ''));
        if ($rawText === '') {
            throw new ReferencedDocumentException('Document AI OCR returned no text.');
        }

        return $this->convertDocumentAiTextToMarkdown($rawText, $this->buildLayoutContext($document));
    }

    public function getProjectId(): string {
        return trim((string) get_option(self::OPTION_PROJECT_ID, ''));
    }

    public function getLocation(): string {
        $location = strtolower(trim((string) get_option(self::OPTION_LOCATION, '')));
        return in_array($location, ['us', 'eu'], true) ? $location : '';
    }

    public function getProcessorId(): string {
        return trim((string) get_option(self::OPTION_PROCESSOR_ID, ''));
    }

    public function hasServiceAccountJson(): bool {
        return $this->getServiceAccountJson() !== '';
    }

    public function saveSettings(string $projectId, string $location, string $processorId, string $serviceAccountJson, bool $clearServiceAccount): void {
        update_option(self::OPTION_PROJECT_ID, sanitize_text_field($projectId), false);
        update_option(self::OPTION_LOCATION, sanitize_key($location), false);
        update_option(self::OPTION_PROCESSOR_ID, sanitize_text_field($processorId), false);

        if ($clearServiceAccount) {
            delete_option(self::OPTION_SERVICE_ACCOUNT_JSON);
            delete_transient(self::TOKEN_TRANSIENT);
            return;
        }

        $serviceAccountJson = trim($serviceAccountJson);
        if ($serviceAccountJson === '') {
            return;
        }

        $decoded = json_decode($serviceAccountJson, true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            return;
        }

        update_option(self::OPTION_SERVICE_ACCOUNT_JSON, Encryption::encrypt($serviceAccountJson), false);
        delete_transient(self::TOKEN_TRANSIENT);
    }

    /**
     * @return array<string,mixed>
     */
    private function processDocument(string $content): array {
        $url = sprintf(
            'https://%s-documentai.googleapis.com/v1/projects/%s/locations/%s/processors/%s:process',
            rawurlencode($this->getLocation()),
            rawurlencode($this->getProjectId()),
            rawurlencode($this->getLocation()),
            rawurlencode($this->getProcessorId())
        );
        $body = [
            'rawDocument' => [
                'content' => base64_encode($content),
                'mimeType' => 'application/pdf',
            ],
            'skipHumanReview' => true,
        ];

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            throw new ReferencedDocumentException('Document AI OCR request failed: ' . $response->get_error_message());
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new ReferencedDocumentException('Document AI OCR failed with HTTP code ' . $httpCode . ': ' . $this->shortenResponse($responseBody));
        }

        $result = json_decode($responseBody, true);
        if (!is_array($result) || !isset($result['document']) || !is_array($result['document'])) {
            throw new ReferencedDocumentException('Document AI OCR returned an invalid response.');
        }

        return $result['document'];
    }

    private function convertDocumentAiTextToMarkdown(string $rawText, string $layoutContext): string {
        $apiKey = (new Encryption())->getApiKey();
        if ($apiKey === '') {
            throw new ReferencedDocumentException('Gemini API key is required to convert Document AI OCR to Markdown.');
        }

        $model = apply_filters('geweb_aisearch_document_ai_markdown_model', 'gemini-2.5-pro');
        $model = is_string($model) && trim($model) !== '' ? trim($model) : 'gemini-2.5-pro';
        $url = self::GEMINI_API_BASE . '/models/' . rawurlencode($model) . ':generateContent';
        $prompt = "Below is text extracted from a scanned PDF via Google Document AI.\n" .
            "Using this text and layout hints, recreate the original document in Markdown format.\n\n" .
            "CRITICAL RULES:\n" .
            "1. Detect headers and use #, ##, ### accordingly.\n" .
            "2. If you see tabular data, format it into a valid Markdown table.\n" .
            "3. Fix broken words caused by OCR when the correction is clear.\n" .
            "4. Preserve dates, amounts, names, references, and legal identifiers exactly.\n" .
            "5. Return ONLY the Markdown content.\n\n" .
            ($layoutContext !== '' ? "LAYOUT HINTS:\n{$layoutContext}\n\n" : '') .
            "RAW TEXT:\n{$rawText}";

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'x-goog-api-key' => $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode([
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => [
                    'temperature' => 0.1,
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            throw new ReferencedDocumentException('Gemini Markdown conversion failed: ' . $response->get_error_message());
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new ReferencedDocumentException('Gemini Markdown conversion failed with HTTP code ' . $httpCode . ': ' . $this->shortenResponse($responseBody));
        }

        $result = json_decode($responseBody, true);
        $markdown = $this->extractGeminiText(is_array($result) ? $result : []);
        if ($markdown === '') {
            throw new ReferencedDocumentException('Gemini Markdown conversion returned no text.');
        }

        return $markdown;
    }

    private function getAccessToken(): string {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $serviceAccount = json_decode($this->getServiceAccountJson(), true);
        if (!is_array($serviceAccount)) {
            throw new ReferencedDocumentException('Document AI service account JSON is invalid.');
        }

        $clientEmail = trim((string) ($serviceAccount['client_email'] ?? ''));
        $privateKey = (string) ($serviceAccount['private_key'] ?? '');
        if ($clientEmail === '' || $privateKey === '') {
            throw new ReferencedDocumentException('Document AI service account JSON is missing client_email or private_key.');
        }

        $now = time();
        $assertion = $this->buildJwt([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ], [
            'iss' => $clientEmail,
            'scope' => self::DOCUMENT_AI_SCOPE,
            'aud' => self::OAUTH_TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $privateKey);

        $response = wp_remote_post(self::OAUTH_TOKEN_URL, [
            'timeout' => 30,
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ],
        ]);

        if (is_wp_error($response)) {
            throw new ReferencedDocumentException('Document AI authentication failed: ' . $response->get_error_message());
        }

        $httpCode = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new ReferencedDocumentException('Document AI authentication failed with HTTP code ' . $httpCode . ': ' . $this->shortenResponse($body));
        }

        $result = json_decode($body, true);
        $accessToken = is_array($result) ? trim((string) ($result['access_token'] ?? '')) : '';
        $expiresIn = is_array($result) ? (int) ($result['expires_in'] ?? 3600) : 3600;
        if ($accessToken === '') {
            throw new ReferencedDocumentException('Document AI authentication returned no access token.');
        }

        set_transient(self::TOKEN_TRANSIENT, $accessToken, max(60, $expiresIn - 120));
        return $accessToken;
    }

    /**
     * @param array<string,mixed> $header
     * @param array<string,mixed> $claims
     */
    private function buildJwt(array $header, array $claims, string $privateKey): string {
        $segments = [
            $this->base64UrlEncode((string) wp_json_encode($header)),
            $this->base64UrlEncode((string) wp_json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);
        $signature = '';
        if (!function_exists('openssl_sign') || !openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new ReferencedDocumentException('Could not sign Document AI authentication token.');
        }

        $segments[] = $this->base64UrlEncode($signature);
        return implode('.', $segments);
    }

    private function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function getServiceAccountJson(): string {
        $encrypted = get_option(self::OPTION_SERVICE_ACCOUNT_JSON, '');
        return is_string($encrypted) ? Encryption::decrypt($encrypted) : '';
    }

    /**
     * @param array<string,mixed> $document
     */
    private function buildLayoutContext(array $document): string {
        $pages = isset($document['pages']) && is_array($document['pages']) ? $document['pages'] : [];
        $parts = [];
        foreach ($pages as $index => $page) {
            if (!is_array($page)) {
                continue;
            }

            $tables = isset($page['tables']) && is_array($page['tables']) ? count($page['tables']) : 0;
            $paragraphs = isset($page['paragraphs']) && is_array($page['paragraphs']) ? count($page['paragraphs']) : 0;
            $parts[] = sprintf('Page %d: paragraphs=%d tables=%d', $index + 1, $paragraphs, $tables);
        }

        return implode("\n", array_slice($parts, 0, 80));
    }

    /**
     * @param array<string,mixed> $result
     */
    private function extractGeminiText(array $result): string {
        $candidates = isset($result['candidates']) && is_array($result['candidates']) ? $result['candidates'] : [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $parts = isset($candidate['content']['parts']) && is_array($candidate['content']['parts']) ? $candidate['content']['parts'] : [];
            $textParts = [];
            foreach ($parts as $part) {
                if (is_array($part) && isset($part['text'])) {
                    $textParts[] = (string) $part['text'];
                }
            }

            if (!empty($textParts)) {
                return trim(implode('', $textParts));
            }
        }

        return '';
    }

    private function shortenResponse(string $body): string {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));
        return substr($body, 0, 300);
    }
}
