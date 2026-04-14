<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Small helpers for building and capturing admin-page data.
 */
class AdminPageSupport {
    /**
     * @param array<int,string> $models
     * @param string $selectedModel
     * @param array<string,mixed> $modelPromptOverrides
     * @param array<string,mixed> $modelPromptOverrideNames
     * @param array<string,mixed> $modelPromptOverrideModes
     * @param AIProviderInterface $provider
     * @param string $defaultPrompt
     * @return array<int,array<string,mixed>>
     */
    public static function buildModelPromptRows(array $models, string $selectedModel, array $modelPromptOverrides, array $modelPromptOverrideNames, array $modelPromptOverrideModes, AIProviderInterface $provider, string $defaultPrompt): array {
        $rows = [];
        foreach ($models as $index => $model) {
            $modelName = (string) $model;
            $rows[] = [
                'default_prompt' => method_exists($provider, 'getDefaultSystemInstructionForModel')
                    ? $provider->getDefaultSystemInstructionForModel($modelName)
                    : $defaultPrompt,
                'index' => $index,
                'is_current' => $modelName === $selectedModel,
                'mode' => (isset($modelPromptOverrideModes[$modelName]) && $modelPromptOverrideModes[$modelName] === 'override') ? 'override' : 'append',
                'model' => $modelName,
                'name' => isset($modelPromptOverrideNames[$modelName]) ? (string) $modelPromptOverrideNames[$modelName] : '',
                'open' => $modelName === $selectedModel,
                'prompt' => isset($modelPromptOverrides[$modelName]) ? (string) $modelPromptOverrides[$modelName] : '',
            ];
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $promptHistory
     * @param string $defaultPrompt
     * @param string $defaultPromptLabel
     * @return array<int,array<string,mixed>>
     */
    public static function buildPromptHistoryItems(array $promptHistory, string $defaultPrompt, string $defaultPromptLabel): array {
        $items = [];
        $seenPromptHistoryEntries = [];
        foreach ($promptHistory as $entry) {
            $saved_at = intval($entry['saved_at'] ?? 0);
            if ($saved_at === 0) {
                continue;
            }

            $entryId = (string) ($entry['entry_id'] ?? '');
            $entryPrompt = (string) ($entry['prompt'] ?? '');
            $entryScope = (string) ($entry['scope'] ?? 'global');
            $entryModel = (string) ($entry['model'] ?? '');
            $entryMode = (string) ($entry['mode'] ?? 'base');
            $isDefaultPrompt = $entryScope === 'global' && trim($entryPrompt) === trim($defaultPrompt);
            $normalizedEntryPrompt = $entryScope . '|' . $entryModel . '|' . trim($entryPrompt);
            $isFirstOccurrence = !isset($seenPromptHistoryEntries[$normalizedEntryPrompt]);
            $seenPromptHistoryEntries[$normalizedEntryPrompt] = true;
            $canRename = !$isDefaultPrompt && $isFirstOccurrence;
            $name = (string) ($entry['name'] ?? '');
            if ($isDefaultPrompt) {
                $name = $defaultPromptLabel;
            } elseif ($name === '') {
                $name = 'Version from ' . DateDisplay::formatDateTime($saved_at);
            }

            if ($entryScope === 'model' && $entryModel !== '') {
                $modeLabel = $entryMode === 'override' ? 'override' : 'add';
                $scopeLabel = 'Model: ' . $entryModel . ' (' . $modeLabel . ')';
            } else {
                $scopeLabel = 'Generic prompt';
            }

            $items[] = [
                'can_rename' => $canRename,
                'entry_id' => $entryId,
                'is_default' => $isDefaultPrompt,
                'mode' => $entryMode,
                'model' => $entryModel,
                'name' => $name,
                'prompt_b64' => base64_encode($entryPrompt),
                'saved_at' => $saved_at,
                'saved_at_label' => DateDisplay::formatDateTime($saved_at),
                'scope' => $entryScope,
                'scope_label' => $scopeLabel,
            ];
        }

        return $items;
    }

    /**
     * @param mixed $connectionStatus
     * @param string $successColor
     * @param string $neutralColor
     * @param string $errorColor
     * @return array{color:string,label:string,message:string}
     */
    public static function buildApiStatusDisplay($connectionStatus, string $successColor, string $neutralColor, string $errorColor): array {
        if (!is_array($connectionStatus) || empty($connectionStatus['status'])) {
            return ['color' => '', 'label' => '', 'message' => ''];
        }

        $apiStatus = (string) $connectionStatus['status'];
        $apiMessage = isset($connectionStatus['message']) ? (string) $connectionStatus['message'] : '';
        if ($apiStatus === 'ok') {
            return ['color' => $successColor, 'label' => 'Valid', 'message' => $apiMessage];
        }

        if ($apiStatus === 'missing') {
            return ['color' => $neutralColor, 'label' => 'Missing', 'message' => $apiMessage];
        }

        return ['color' => $errorColor, 'label' => 'Invalid', 'message' => $apiMessage];
    }

    /**
     * @param bool $shouldRender
     * @param callable $renderer
     * @param string $fallbackMessage
     * @return string
     */
    public static function renderPanelHtml(bool $shouldRender, callable $renderer, string $fallbackMessage): string {
        ob_start();
        if ($shouldRender) {
            $renderer();
        } else {
            echo '<p>' . esc_html($fallbackMessage) . '</p>';
        }

        return (string) ob_get_clean();
    }

    /**
     * @param bool $providerHasStoreCache
     * @param AIProviderInterface $provider
     * @param callable $selectionResolver
     * @param callable $panelRenderer
     * @return string
     */
    public static function renderInitialGeminiStoreDocumentsPanel(bool $providerHasStoreCache, AIProviderInterface $provider, callable $selectionResolver, callable $panelRenderer): string {
        $storeOverview = $providerHasStoreCache && $provider instanceof Gemini ? $provider->getStoreOverview() : [];
        $initialStoreSelection = $selectionResolver($storeOverview);

        ob_start();
        $panelRenderer(
            (string) ($initialStoreSelection['name'] ?? ''),
            (string) ($initialStoreSelection['label'] ?? ''),
            isset($initialStoreSelection['documents']) && is_array($initialStoreSelection['documents']) ? $initialStoreSelection['documents'] : []
        );

        return (string) ob_get_clean();
    }

    /**
     * @param callable $renderer
     * @return string
     */
    public static function captureHtml(callable $renderer): string {
        ob_start();
        $renderer();
        return (string) ob_get_clean();
    }
}
