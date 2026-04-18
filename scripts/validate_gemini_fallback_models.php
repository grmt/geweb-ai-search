<?php

declare(strict_types=1);

$repoRoot = dirname(__DIR__);
$providerPath = $repoRoot . '/classes/Providers/Gemini.php';
$frontendPromptManagerPath = $repoRoot . '/classes/Frontend/FrontendAiPromptManager.php';
$adminPageSectionsPath = $repoRoot . '/classes/Admin/AdminPageSections.php';

if (!is_file($providerPath) || !is_file($frontendPromptManagerPath) || !is_file($adminPageSectionsPath)) {
    fwrite(STDERR, "Required source files are missing.\n");
    exit(1);
}

$apiKey = trim((string) getenv('GEMINI_API_KEY'));
if ($apiKey === '') {
    fwrite(STDERR, "GEMINI_API_KEY is required.\n");
    exit(1);
}

$providerSource = (string) file_get_contents($providerPath);
$frontendSource = (string) file_get_contents($frontendPromptManagerPath);
$adminSource = (string) file_get_contents($adminPageSectionsPath);
$officialAliases = [
    'gemini-flash-latest' => 'gemini-3-flash-preview',
    'gemini-pro-latest' => 'gemini-3.1-pro-preview',
];

$defaultModel = extractDefaultModel($providerSource);
$fallbackModels = extractQuotedArrayFromMethod($providerSource, 'getDefaultModels');
$frontendModels = extractQuotedArrayFromMethod($frontendSource, 'supportsFrontendAiChatModel');
$adminModels = extractQuotedArrayFromMethod($adminSource, 'supportsFileSearchModel');

$errors = [];
$warnings = [];

if ($defaultModel === '') {
    $errors[] = 'Could not extract DEFAULT_MODEL from Gemini provider.';
}

if ($fallbackModels === []) {
    $errors[] = 'Could not extract bundled fallback models from Gemini provider.';
}

if ($frontendModels === []) {
    $errors[] = 'Could not extract frontend chat model filter list.';
}

if ($adminModels === []) {
    $errors[] = 'Could not extract admin file-search model filter list.';
}

if ($errors !== []) {
    renderMessages($errors, $warnings);
    exit(1);
}

if (!in_array($defaultModel, $fallbackModels, true)) {
    $errors[] = sprintf('Default model "%s" is not present in the fallback model list.', $defaultModel);
}

if ($frontendModels !== $adminModels) {
    $errors[] = 'Frontend and admin model allowlists differ.';
}

if ($frontendModels !== array_values(array_intersect($fallbackModels, $frontendModels))) {
    $warnings[] = 'Frontend/admin allowlists are not ordered exactly like the fallback model list.';
}

$liveModels = fetchLiveGeminiModels($apiKey);
if ($liveModels === []) {
    $errors[] = 'Gemini ListModels returned no generateContent-capable models.';
    renderMessages($errors, $warnings);
    exit(1);
}

foreach ($fallbackModels as $model) {
    if (!in_array($model, $liveModels, true)) {
        $errors[] = sprintf('Fallback model "%s" is not present in the live Gemini model list.', $model);
    }
}

foreach ($frontendModels as $model) {
    if (isset($officialAliases[$model])) {
        continue;
    }

    if (!in_array($model, $liveModels, true)) {
        $errors[] = sprintf('Allowed chat model "%s" is not present in the live Gemini model list.', $model);
    }
}

$candidateModels = array_values(array_filter($liveModels, static function (string $model): bool {
    $blockedFragments = [
        'tts',
        'speech',
        'audio',
        'embedding',
        'image-generation',
        'vision-preview-generation',
        'live',
        'native-audio',
        'image',
        'video',
        'robotics',
        'deep-research',
        'computer-use',
    ];

    foreach ($blockedFragments as $fragment) {
        if (strpos($model, $fragment) !== false) {
            return false;
        }
    }

    return preg_match('/^gemini-[0-9][a-z0-9.\-]*-(pro|flash|flash-lite)(?:-|$)/', $model) === 1;
}));

$missingCandidates = array_values(array_diff($candidateModels, $fallbackModels));
if ($missingCandidates !== []) {
    $errors[] = 'Live Gemini model candidates missing from the fallback list: ' . implode(', ', $missingCandidates);
}

renderMessages($errors, $warnings);

if ($errors !== []) {
    exit(1);
}

fwrite(STDOUT, "Gemini fallback model validation passed.\n");

function extractDefaultModel(string $source): string
{
    if (!preg_match("/private const DEFAULT_MODEL = '([^']+)'/", $source, $matches)) {
        return '';
    }

    return trim((string) ($matches[1] ?? ''));
}

/**
 * @return array<int,string>
 */
function extractQuotedArrayFromMethod(string $source, string $methodName): array
{
    $pattern = sprintf('/function %s\([^{]*\)\s*:[^{]+\{(.*?)\n    \}/s', preg_quote($methodName, '/'));
    if (!preg_match($pattern, $source, $matches)) {
        return [];
    }

    if (!preg_match_all("/'([^']+)'/", (string) ($matches[1] ?? ''), $valueMatches)) {
        return [];
    }

    return array_values(array_unique(array_map('strval', $valueMatches[1])));
}

/**
 * @return array<int,string>
 */
function fetchLiveGeminiModels(string $apiKey): array
{
    $url = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode($apiKey);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        fwrite(STDERR, "Could not reach Gemini ListModels endpoint.\n");
        exit(1);
    }

    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s(\d{3})\s/', $statusLine, $statusMatches)) {
        fwrite(STDERR, "Could not determine Gemini ListModels response status.\n");
        exit(1);
    }

    $statusCode = (int) $statusMatches[1];
    if ($statusCode < 200 || $statusCode >= 300) {
        fwrite(STDERR, "Gemini ListModels failed with HTTP " . $statusCode . ".\n");
        fwrite(STDERR, $response . "\n");
        exit(1);
    }

    $payload = json_decode($response, true);
    if (!is_array($payload)) {
        fwrite(STDERR, "Gemini ListModels returned invalid JSON.\n");
        exit(1);
    }

    $models = [];
    foreach (($payload['models'] ?? []) as $model) {
        if (!is_array($model)) {
            continue;
        }

        $name = isset($model['name']) ? (string) $model['name'] : '';
        $methods = isset($model['supportedGenerationMethods']) && is_array($model['supportedGenerationMethods'])
            ? $model['supportedGenerationMethods']
            : [];

        if ($name === '' || !in_array('generateContent', $methods, true)) {
            continue;
        }

        $shortName = preg_replace('#^models/#', '', $name);
        if (!is_string($shortName) || $shortName === '') {
            continue;
        }

        $models[] = $shortName;
    }

    $models = array_values(array_unique($models));
    sort($models);

    return $models;
}

/**
 * @param array<int,string> $errors
 * @param array<int,string> $warnings
 */
function renderMessages(array $errors, array $warnings): void
{
    foreach ($warnings as $warning) {
        fwrite(STDOUT, "[warn] " . $warning . "\n");
    }

    foreach ($errors as $error) {
        fwrite(STDERR, "[error] " . $error . "\n");
    }
}
