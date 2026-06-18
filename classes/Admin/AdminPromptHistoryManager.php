<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminPromptHistoryManager {
    private const OPTION_PROMPT_HISTORY = 'geweb_aisearch_prompt_history';
    private const OPTION_MODEL_PROMPTS = 'geweb_aisearch_model_prompts';
    private const OPTION_MODEL_PROMPT_NAMES = 'geweb_aisearch_model_prompt_names';
    private const OPTION_MODEL_PROMPT_MODES = 'geweb_aisearch_model_prompt_modes';
    private const LABEL_DEFAULT_PROMPT = 'Default prompt';
    private const SCOPE_GLOBAL = 'global';
    private const SCOPE_MODEL = 'model';
    private const MODE_APPEND = 'append';
    private const MODE_OVERRIDE = 'override';
    private const MODE_BASE = 'base';

    public function saveModelPromptOverrides(int $historyLimit): void {
        $current = $this->getCurrentModelPromptState();
        $submitted = $this->getSubmittedModelPromptState();

        foreach ($this->getPromptModelsForHistory($current, $submitted) as $model) {
            $this->storeModelPromptSnapshots($model, $current, $submitted, $historyLimit);
        }

        $this->persistModelPromptOption(self::OPTION_MODEL_PROMPTS, $submitted['prompts']);
        $this->persistModelPromptOption(self::OPTION_MODEL_PROMPT_NAMES, $submitted['names']);
        $this->persistModelPromptOption(self::OPTION_MODEL_PROMPT_MODES, $submitted['modes']);
    }

    public function storeEntry(string $prompt, string $name, int $limit, string $scope = self::SCOPE_GLOBAL, string $model = '', string $mode = self::MODE_BASE): void {
        $prompt = trim($prompt);
        if ($prompt === '' || $limit <= 0) {
            return;
        }

        $scope = $this->normalizeScope($scope);
        $mode = $this->normalizeMode($mode);
        $model = $scope === self::SCOPE_MODEL ? sanitize_text_field($model) : '';
        if ($scope === self::SCOPE_GLOBAL && $prompt === trim($this->getDefaultPrompt())) {
            $name = self::LABEL_DEFAULT_PROMPT;
        }

        $history = $this->getHistory();
        array_unshift($history, $this->buildHistoryEntry($prompt, $name, $scope, $model, $mode));

        $this->updateScopedOption(self::OPTION_PROMPT_HISTORY, $this->dedupeAndLimitHistory($history, $limit));
    }

    /**
     * @param array<string,string> $names
     * @return void
     */
    public function updateNames(array $names): void {
        $history = $this->getHistory();
        if (empty($history)) {
            return;
        }

        $newHistory = [];
        $seenPrompts = [];
        foreach ($history as $entry) {
            $entry = $this->applyHistoryEntryName($entry, $names, $seenPrompts);
            $newHistory[] = $entry;
        }

        if ($newHistory !== $history) {
            $this->updateScopedOption(self::OPTION_PROMPT_HISTORY, $newHistory);
        }
    }

    public function trim(int $limit): void {
        $history = $this->getHistory();
        if (empty($history)) {
            $this->updateScopedOption(self::OPTION_PROMPT_HISTORY, []);
            return;
        }

        $this->updateScopedOption(self::OPTION_PROMPT_HISTORY, $this->limitHistoryByScope($history, $limit));
    }

    /**
     * @return array{prompts:array<string,string>,names:array<string,string>,modes:array<string,string>}
     */
    private function getCurrentModelPromptState(): array {
        return [
            'prompts' => $this->getScopedArrayOption(self::OPTION_MODEL_PROMPTS),
            'names' => $this->getScopedArrayOption(self::OPTION_MODEL_PROMPT_NAMES),
            'modes' => $this->getScopedArrayOption(self::OPTION_MODEL_PROMPT_MODES),
        ];
    }

    /**
     * @return array{prompts:array<string,string>,names:array<string,string>,modes:array<string,string>}
     */
    private function getSubmittedModelPromptState(): array {
        $state = ['prompts' => [], 'names' => [], 'modes' => []];
        $postedPromptModels = $this->getPostedArray('geweb_ai_search_model_prompt_models', true);
        $postedPromptTexts = $this->getPostedArray('geweb_ai_search_model_prompts', false);
        $postedPromptNames = $this->getPostedArray('geweb_ai_search_model_prompt_names', true);
        $postedPromptModes = $this->getPostedArray('geweb_ai_search_model_prompt_modes', true);

        foreach ($postedPromptModels as $index => $rawModel) {
            $this->appendSubmittedModelPrompt($state, $rawModel, $postedPromptTexts[$index] ?? '', $postedPromptNames[$index] ?? '', $postedPromptModes[$index] ?? self::MODE_APPEND);
        }

        return $state;
    }

    /**
     * @param array{prompts:array<string,string>,names:array<string,string>,modes:array<string,string>} $state
     * @param mixed $rawModel
     * @param mixed $rawPrompt
     * @param mixed $rawName
     * @param mixed $rawMode
     * @return void
     */
    private function appendSubmittedModelPrompt(array &$state, $rawModel, $rawPrompt, $rawName, $rawMode): void {
        $model = is_string($rawModel) ? sanitize_text_field($rawModel) : '';
        $prompt = PromptSupport::normalizePromptInput($rawPrompt);
        if ($model === '' || $prompt === '') {
            return;
        }

        PromptSupport::assertNoUrls($prompt, 'Model prompt (' . $model . ')');
        $name = sanitize_text_field((string) $rawName);
        $state['prompts'][$model] = $prompt;
        $state['modes'][$model] = $this->normalizeSubmittedPromptMode($rawMode);
        if ($name !== '') {
            $state['names'][$model] = $name;
        }
    }

    /**
     * @param array{prompts:array<string,string>,names:array<string,string>,modes:array<string,string>} $current
     * @param array{prompts:array<string,string>,names:array<string,string>,modes:array<string,string>} $submitted
     * @return array<int,string>
     */
    private function getPromptModelsForHistory(array $current, array $submitted): array {
        return array_values(array_unique(array_merge(array_keys($current['prompts']), array_keys($submitted['prompts']))));
    }

    /**
     * @param array{prompts:array<string,string>,names:array<string,string>,modes:array<string,string>} $current
     * @param array{prompts:array<string,string>,names:array<string,string>,modes:array<string,string>} $submitted
     */
    private function storeModelPromptSnapshots(string $model, array $current, array $submitted, int $historyLimit): void {
        $currentEntry = $this->buildModelPromptSnapshot($model, $current);
        $nextEntry = $this->buildModelPromptSnapshot($model, $submitted);
        $hasChanged = $currentEntry !== $nextEntry;

        if ($currentEntry['prompt'] !== '' && $hasChanged) {
            $this->storeEntry($currentEntry['prompt'], $currentEntry['name'], $historyLimit, self::SCOPE_MODEL, $model, $currentEntry['mode']);
        }
        if ($nextEntry['prompt'] !== '' && $hasChanged) {
            $this->storeEntry($nextEntry['prompt'], $nextEntry['name'], $historyLimit, self::SCOPE_MODEL, $model, $nextEntry['mode']);
        }
    }

    /**
     * @param array{prompts:array<string,string>,names:array<string,string>,modes:array<string,string>} $state
     * @return array{prompt:string,name:string,mode:string}
     */
    private function buildModelPromptSnapshot(string $model, array $state): array {
        return [
            'prompt' => trim((string) ($state['prompts'][$model] ?? '')),
            'name' => trim((string) ($state['names'][$model] ?? '')),
            'mode' => $this->normalizeMode((string) ($state['modes'][$model] ?? self::MODE_APPEND)),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function getHistory(): array {
        return PromptSupport::normalizePromptHistoryEntries($this->getScopedOption(self::OPTION_PROMPT_HISTORY, []));
    }

    /**
     * @return array<string,string>
     */
    private function getScopedArrayOption(string $optionName): array {
        $value = $this->getScopedOption($optionName, []);
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int,mixed>
     */
    private function getPostedArray(string $key, bool $unslash): array {
        $value = isset($_POST[$key]) && is_array($_POST[$key]) ? $_POST[$key] : [];
        return $unslash ? wp_unslash($value) : $value;
    }

    private function normalizeSubmittedPromptMode($mode): string {
        return sanitize_key((string) $mode) === self::MODE_OVERRIDE ? self::MODE_OVERRIDE : self::MODE_APPEND;
    }

    private function normalizeMode(string $mode): string {
        $normalized = self::MODE_BASE;
        if ($mode === self::MODE_OVERRIDE || $mode === self::MODE_APPEND) {
            $normalized = $mode;
        }

        return $normalized;
    }

    private function normalizeScope(string $scope): string {
        return $scope === self::SCOPE_MODEL ? self::SCOPE_MODEL : self::SCOPE_GLOBAL;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildHistoryEntry(string $prompt, string $name, string $scope, string $model, string $mode): array {
        return [
            'entry_id' => wp_generate_uuid4(),
            'prompt' => $prompt,
            'saved_at' => current_time('timestamp'),
            'name' => $name,
            'scope' => $scope,
            'model' => $model,
            'mode' => $mode,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $history
     * @return array<int,array<string,mixed>>
     */
    private function dedupeAndLimitHistory(array $history, int $limit): array {
        $uniqueHistory = [];
        $seen = [];
        $counts = [];
        foreach ($history as $entry) {
            $normalizedEntry = $this->normalizeHistoryEntry($entry);
            $scopeKey = $normalizedEntry['scope'] . '|' . $normalizedEntry['model'];
            $dedupeKey = $scopeKey . '|' . $normalizedEntry['mode'] . '|' . $normalizedEntry['prompt'];
            if ($normalizedEntry['prompt'] === '' || isset($seen[$dedupeKey]) || $this->isScopeAtLimit($counts, $scopeKey, $limit)) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $uniqueHistory[] = $normalizedEntry;
            $counts[$scopeKey] = (int) ($counts[$scopeKey] ?? 0) + 1;
        }

        return $uniqueHistory;
    }

    /**
     * @param array<string,int> $counts
     */
    private function isScopeAtLimit(array &$counts, string $scopeKey, int $limit): bool {
        $counts[$scopeKey] = (int) ($counts[$scopeKey] ?? 0);
        return $counts[$scopeKey] >= $limit;
    }

    /**
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function normalizeHistoryEntry(array $entry): array {
        $scope = $this->normalizeScope((string) ($entry['scope'] ?? self::SCOPE_GLOBAL));
        return [
            'entry_id' => sanitize_text_field((string) ($entry['entry_id'] ?? wp_generate_uuid4())),
            'prompt' => isset($entry['prompt']) ? trim((string) $entry['prompt']) : '',
            'saved_at' => intval($entry['saved_at'] ?? current_time('timestamp')),
            'name' => sanitize_text_field((string) ($entry['name'] ?? '')),
            'scope' => $scope,
            'model' => $scope === self::SCOPE_MODEL ? sanitize_text_field((string) ($entry['model'] ?? '')) : '',
            'mode' => $this->normalizeMode((string) ($entry['mode'] ?? self::MODE_BASE)),
        ];
    }

    /**
     * @param array<string,mixed> $entry
     * @param array<string,string> $names
     * @param array<string,bool> $seenPrompts
     * @return array<string,mixed>
     */
    private function applyHistoryEntryName(array $entry, array $names, array &$seenPrompts): array {
        $normalizedEntry = $this->normalizeHistoryEntry($entry);
        $seenKey = $normalizedEntry['scope'] . '|' . $normalizedEntry['model'] . '|' . $normalizedEntry['prompt'];
        if ($normalizedEntry['scope'] === self::SCOPE_GLOBAL && $normalizedEntry['prompt'] === trim($this->getDefaultPrompt())) {
            $normalizedEntry['name'] = self::LABEL_DEFAULT_PROMPT;
        } elseif (!isset($seenPrompts[$seenKey]) && isset($names[$normalizedEntry['entry_id']])) {
            $normalizedEntry['name'] = sanitize_text_field($names[$normalizedEntry['entry_id']]);
        }

        if ($normalizedEntry['prompt'] !== '') {
            $seenPrompts[$seenKey] = true;
        }

        return $normalizedEntry;
    }

    /**
     * @param array<int,array<string,mixed>> $history
     * @return array<int,array<string,mixed>>
     */
    private function limitHistoryByScope(array $history, int $limit): array {
        $trimmed = [];
        $counts = [];
        foreach ($history as $entry) {
            $normalizedEntry = $this->normalizeHistoryEntry($entry);
            $scopeKey = $normalizedEntry['scope'] . '|' . $normalizedEntry['model'];
            if ($this->isScopeAtLimit($counts, $scopeKey, $limit)) {
                continue;
            }

            $trimmed[] = $normalizedEntry;
            $counts[$scopeKey] += 1;
        }

        return $trimmed;
    }

    /**
     * @param array<string,string> $values
     */
    private function persistModelPromptOption(string $optionName, array $values): void {
        if (!empty($values)) {
            $this->updateScopedOption($optionName, $values);
            return;
        }

        UserScope::deleteSharedSearchScopedOption($optionName);
    }

    private function getDefaultPrompt(): string {
        return ProviderFactory::make()->getDefaultSystemInstruction();
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function getScopedOption(string $optionName, $default = false) {
        if (in_array($optionName, [self::OPTION_MODEL_PROMPTS, self::OPTION_MODEL_PROMPT_NAMES, self::OPTION_MODEL_PROMPT_MODES], true)) {
            return UserScope::getSharedSearchScopedOption($optionName, $default);
        }

        return UserScope::getGroupScopedOption($optionName, $default);
    }

    /**
     * @param mixed $value
     */
    private function updateScopedOption(string $optionName, $value): bool {
        if (in_array($optionName, [self::OPTION_MODEL_PROMPTS, self::OPTION_MODEL_PROMPT_NAMES, self::OPTION_MODEL_PROMPT_MODES], true)) {
            return UserScope::updateSharedSearchScopedOption($optionName, $value, false);
        }

        return UserScope::updateGroupScopedOption($optionName, $value, false);
    }
}
