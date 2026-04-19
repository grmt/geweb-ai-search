<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Handles saving and prompt-history related admin settings.
 */
class AdminSettingsManager {
    private const OPTION_CUSTOM_PROMPT = 'geweb_aisearch_custom_prompt';
    private const OPTION_PROMPT_HISTORY = 'geweb_aisearch_prompt_history';
    private const OPTION_PROMPT_HISTORY_LIMIT = 'geweb_aisearch_prompt_history_limit';
    private const OPTION_CUSTOM_PROMPT_NAME = 'geweb_aisearch_custom_prompt_name';
    private const OPTION_MODEL_PROMPTS = 'geweb_aisearch_model_prompts';
    private const OPTION_MODEL_PROMPT_NAMES = 'geweb_aisearch_model_prompt_names';
    private const OPTION_MODEL_PROMPT_MODES = 'geweb_aisearch_model_prompt_modes';
    private const OPTION_PROVIDER = 'geweb_aisearch_provider';
    private const OPTION_MODEL_SELECTION_MODE = 'geweb_aisearch_model_selection_mode';
    private const OPTION_INCLUDE_REFERENCED_DOCUMENTS = 'geweb_aisearch_include_referenced_documents';
    private const OPTION_OCR_ALL_UPLOAD_IMAGES = 'geweb_aisearch_ocr_all_upload_images';
    private const OPTION_DATE_DISPLAY_FORMAT = 'geweb_aisearch_date_display_format';
    private const OPTION_PRESERVE_DATA_ON_UNINSTALL = 'geweb_aisearch_preserve_data_on_uninstall';
    private const OPTION_FRONTEND_AI_INTERFACE = 'geweb_aisearch_frontend_ai_interface';
    private const OPTION_CONVERSATION_TRIM_MESSAGE_LIMIT = 'geweb_aisearch_conversation_trim_message_limit';
    private const OPTION_CONVERSATION_TRIM_CHAR_LIMIT = 'geweb_aisearch_conversation_trim_char_limit';
    private const OPTION_LOCAL_CONVERSATION_ARCHIVE_LIMIT = 'geweb_aisearch_local_conversation_archive_limit';
    private const OPTION_STORED_CONTEXT_MESSAGE_LIMIT = 'geweb_aisearch_stored_context_message_limit';
    private const OPTION_STORED_CONTEXT_CHAR_LIMIT = 'geweb_aisearch_stored_context_char_limit';
    private const LABEL_DEFAULT_PROMPT = 'Default prompt';
    private const DEFAULT_PROMPT_HISTORY_LIMIT = 10;
    private const DEFAULT_FRONTEND_AI_INTERFACE = 'fullscreen';
    private const DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT = 12;
    private const DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT = 12000;
    private const DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT = 12;
    private const DEFAULT_STORED_CONTEXT_MESSAGE_LIMIT = 120;
    private const DEFAULT_STORED_CONTEXT_CHAR_LIMIT = 60000;
    private const MODEL_SELECTION_MODE_DEFAULT = 'default';
    private const MODEL_SELECTION_MODE_CUSTOM = 'custom';

    /**
     * @return void
     */
    public function save(): void {
        $this->handleSubmittedApiKey();
        $this->saveGeneralSettings();
        $this->applySubmittedReferencedDocumentTargets();
        $historyLimit = $this->savePromptHistoryLimit();
        $this->saveCustomPromptSettings($historyLimit);
        $this->saveModelPromptOverrides($historyLimit);
        $this->savePromptHistoryNamesFromRequest();
        AdminViewRevision::touchPrompts();
        AdminViewRevision::touchFiles();
        GroupDataRevision::touch();
    }

    /**
     * @return string
     */
    private function getSubmittedApiKey(): string {
        if (isset($_POST['geweb_gemini_token'])) {
            return sanitize_text_field(wp_unslash($_POST['geweb_gemini_token']));
        }

        if (isset($_POST['geweb_api_key'])) {
            return sanitize_text_field(wp_unslash($_POST['geweb_api_key']));
        }

        return '';
    }

    /**
     * @return void
     */
    private function handleSubmittedApiKey(): void {
        $submittedApiKey = $this->getSubmittedApiKey();
        if ($submittedApiKey === '') {
            return;
        }

        $encryption = new Encryption();
        $encryption->saveApiKey($submittedApiKey);

        $provider = ProviderFactory::make();
        $provider->clearModelsCache();
        $connectionStatus = $provider->validateConnection();
        $shouldCreateStore = ($connectionStatus['status'] ?? '') === 'ok'
            && (empty($provider->getStoreData()) || isset($_POST['geweb_ai_search_create_store']));
        if (!$shouldCreateStore) {
            return;
        }

        $storeCreated = $provider->createStore();
        if ($storeCreated && isset($_POST['geweb_ai_search_create_store'])) {
            $this->clearLocalIndexTracking();
        }
    }

    /**
     * @return void
     */
    private function saveGeneralSettings(): void {
        update_option(self::OPTION_PROVIDER, ProviderFactory::getConfiguredProviderKey());

        $postTypes = [];
        if (isset($_POST['geweb_ai_search_post_types']) && is_array($_POST['geweb_ai_search_post_types'])) {
            $postTypes = array_map('sanitize_key', wp_unslash($_POST['geweb_ai_search_post_types']));
        }
        update_option('geweb_aisearch_post_types', $postTypes);

        if (isset($_POST['geweb_ai_search_model'])) {
            $submittedModel = sanitize_text_field(wp_unslash($_POST['geweb_ai_search_model']));
            $provider = ProviderFactory::make();
            update_option('geweb_aisearch_model', $submittedModel);

            $selectionMode = $submittedModel === $provider->getDefaultModel()
                ? self::MODEL_SELECTION_MODE_DEFAULT
                : self::MODEL_SELECTION_MODE_CUSTOM;
            update_option(self::OPTION_MODEL_SELECTION_MODE, $selectionMode);
        }

        update_option(self::OPTION_INCLUDE_REFERENCED_DOCUMENTS, !empty($_POST['geweb_ai_search_include_referenced_documents']) ? '1' : '0');
        UserScope::updateGroupScopedOption(self::OPTION_OCR_ALL_UPLOAD_IMAGES, !empty($_POST['geweb_ai_search_ocr_all_upload_images']) ? '1' : '0', false);
        UserScope::updateGroupScopedOption(
            self::OPTION_DATE_DISPLAY_FORMAT,
            DateDisplay::normalizeFormat(isset($_POST['geweb_ai_search_date_display_format']) ? sanitize_key(wp_unslash($_POST['geweb_ai_search_date_display_format'])) : '') ?: DateDisplay::FORMAT_ISO,
            false
        );
        update_option(self::OPTION_PRESERVE_DATA_ON_UNINSTALL, !empty($_POST['geweb_ai_search_preserve_data_on_uninstall']) ? '1' : '0');
        update_option(
            self::OPTION_FRONTEND_AI_INTERFACE,
            $this->normalizeFrontendAiInterface(isset($_POST['geweb_ai_search_frontend_ai_interface']) ? wp_unslash($_POST['geweb_ai_search_frontend_ai_interface']) : '')
        );
        update_option(
            self::OPTION_CONVERSATION_TRIM_MESSAGE_LIMIT,
            $this->sanitizePositiveIntOption(
                isset($_POST['geweb_ai_search_conversation_trim_message_limit']) ? wp_unslash($_POST['geweb_ai_search_conversation_trim_message_limit']) : null,
                self::DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT,
                2,
                200
            )
        );
        update_option(
            self::OPTION_CONVERSATION_TRIM_CHAR_LIMIT,
            $this->sanitizePositiveIntOption(
                isset($_POST['geweb_ai_search_conversation_trim_char_limit']) ? wp_unslash($_POST['geweb_ai_search_conversation_trim_char_limit']) : null,
                self::DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT,
                500,
                200000
            )
        );
        update_option(
            self::OPTION_LOCAL_CONVERSATION_ARCHIVE_LIMIT,
            $this->sanitizePositiveIntOption(
                isset($_POST['geweb_ai_search_local_conversation_archive_limit']) ? wp_unslash($_POST['geweb_ai_search_local_conversation_archive_limit']) : null,
                self::DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT,
                1,
                200
            )
        );
        update_option(
            self::OPTION_STORED_CONTEXT_MESSAGE_LIMIT,
            $this->sanitizePositiveIntOption(
                isset($_POST['geweb_ai_search_stored_context_message_limit']) ? wp_unslash($_POST['geweb_ai_search_stored_context_message_limit']) : null,
                self::DEFAULT_STORED_CONTEXT_MESSAGE_LIMIT,
                10,
                500
            )
        );
        update_option(
            self::OPTION_STORED_CONTEXT_CHAR_LIMIT,
            $this->sanitizePositiveIntOption(
                isset($_POST['geweb_ai_search_stored_context_char_limit']) ? wp_unslash($_POST['geweb_ai_search_stored_context_char_limit']) : null,
                self::DEFAULT_STORED_CONTEXT_CHAR_LIMIT,
                5000,
                500000
            )
        );
    }

    /**
     * @return void
     */
    private function applySubmittedReferencedDocumentTargets(): void {
        if (!isset($_POST['geweb_ai_search_referenced_document_targets'])) {
            return;
        }

        $rawTargets = wp_unslash($_POST['geweb_ai_search_referenced_document_targets']);
        $decodedTargets = json_decode(is_string($rawTargets) ? $rawTargets : '', true);
        if (!is_array($decodedTargets)) {
            return;
        }

        $normalizedTargets = [];
        foreach ($decodedTargets as $fileHash => $target) {
            if (!is_string($fileHash) || $fileHash === '') {
                continue;
            }

            $normalizedTargets[sanitize_text_field($fileHash)] = (bool) $target;
        }

        $documentManager = new ReferencedDocumentManager();
        $documentManager->applyReferencedDocumentSelectionTargets($normalizedTargets);
    }

    /**
     * @return int
     */
    private function savePromptHistoryLimit(): int {
        $historyLimit = self::DEFAULT_PROMPT_HISTORY_LIMIT;
        if (isset($_POST['geweb_ai_search_prompt_history_limit'])) {
            $historyLimit = max(1, intval($_POST['geweb_ai_search_prompt_history_limit']));
        }

        $this->updateScopedOption(self::OPTION_PROMPT_HISTORY_LIMIT, $historyLimit);
        $this->trimPromptHistory(max(0, $historyLimit - 1));
        return $historyLimit;
    }

    /**
     * @param int $historyLimit
     * @return void
     */
    private function saveCustomPromptSettings(int $historyLimit): void {
        if (!isset($_POST['geweb_ai_search_custom_prompt'])) {
            return;
        }

        $newPrompt = PromptSupport::normalizePromptInput($_POST['geweb_ai_search_custom_prompt']);
        if ($newPrompt !== '') {
            PromptSupport::assertNoUrls($newPrompt, 'Custom prompt');
        }
        $currentPrompt = (string) $this->getScopedOption(self::OPTION_CUSTOM_PROMPT, '');
        $newPromptName = isset($_POST['geweb_ai_search_custom_prompt_name'])
            ? sanitize_text_field(wp_unslash($_POST['geweb_ai_search_custom_prompt_name']))
            : '';
        $defaultPrompt = $this->getDefaultPrompt();
        $useDefaultPrompt = ($newPrompt === '' || trim($newPrompt) === trim($defaultPrompt));
        $effectivePrompt = $useDefaultPrompt ? '' : $newPrompt;

        if ($effectivePrompt !== $currentPrompt) {
            $this->storePromptHistory($currentPrompt, max(0, $historyLimit - 1));
        }

        if ($useDefaultPrompt) {
            $this->deleteScopedOption(self::OPTION_CUSTOM_PROMPT);
            $this->deleteScopedOption(self::OPTION_CUSTOM_PROMPT_NAME);
            if (trim($defaultPrompt) !== trim($currentPrompt)) {
                $this->storeCurrentPromptSnapshot($defaultPrompt, self::LABEL_DEFAULT_PROMPT, $historyLimit);
            }
            return;
        }

        $this->updateScopedOption(self::OPTION_CUSTOM_PROMPT, $newPrompt);
        $this->updateScopedOption(self::OPTION_CUSTOM_PROMPT_NAME, $newPromptName);
        if ($newPrompt !== $currentPrompt) {
            $this->storeCurrentPromptSnapshot($newPrompt, $newPromptName, $historyLimit);
        }
    }

    /**
     * @param int $historyLimit
     * @return void
     */
    private function saveModelPromptOverrides(int $historyLimit): void {
        $currentModelPrompts = $this->getScopedOption(self::OPTION_MODEL_PROMPTS, []);
        $currentModelPromptNames = $this->getScopedOption(self::OPTION_MODEL_PROMPT_NAMES, []);
        $currentModelPromptModes = $this->getScopedOption(self::OPTION_MODEL_PROMPT_MODES, []);
        $currentModelPrompts = is_array($currentModelPrompts) ? $currentModelPrompts : [];
        $currentModelPromptNames = is_array($currentModelPromptNames) ? $currentModelPromptNames : [];
        $currentModelPromptModes = is_array($currentModelPromptModes) ? $currentModelPromptModes : [];
        $modelPrompts = [];
        $modelPromptNames = [];
        $modelPromptModes = [];
        $postedPromptModels = isset($_POST['geweb_ai_search_model_prompt_models']) && is_array($_POST['geweb_ai_search_model_prompt_models'])
            ? wp_unslash($_POST['geweb_ai_search_model_prompt_models'])
            : [];
        $postedPromptTexts = isset($_POST['geweb_ai_search_model_prompts']) && is_array($_POST['geweb_ai_search_model_prompts'])
            ? $_POST['geweb_ai_search_model_prompts']
            : [];
        $postedPromptNames = isset($_POST['geweb_ai_search_model_prompt_names']) && is_array($_POST['geweb_ai_search_model_prompt_names'])
            ? wp_unslash($_POST['geweb_ai_search_model_prompt_names'])
            : [];
        $postedPromptModes = isset($_POST['geweb_ai_search_model_prompt_modes']) && is_array($_POST['geweb_ai_search_model_prompt_modes'])
            ? wp_unslash($_POST['geweb_ai_search_model_prompt_modes'])
            : [];

        foreach ($postedPromptModels as $index => $rawModel) {
            $model = is_string($rawModel) ? sanitize_text_field($rawModel) : '';
            if ($model === '') {
                continue;
            }

            $prompt = isset($postedPromptTexts[$index]) ? PromptSupport::normalizePromptInput($postedPromptTexts[$index]) : '';
            if ($prompt === '') {
                continue;
            }

            PromptSupport::assertNoUrls($prompt, 'Model prompt (' . $model . ')');

            $name = isset($postedPromptNames[$index]) ? sanitize_text_field((string) $postedPromptNames[$index]) : '';
            $mode = isset($postedPromptModes[$index]) ? sanitize_key((string) $postedPromptModes[$index]) : 'append';
            $modelPrompts[$model] = $prompt;
            $modelPromptModes[$model] = $mode === 'override' ? 'override' : 'append';
            if ($name !== '') {
                $modelPromptNames[$model] = $name;
            }
        }

        $allPromptModels = array_values(array_unique(array_merge(array_keys($currentModelPrompts), array_keys($modelPrompts))));
        foreach ($allPromptModels as $model) {
            $currentPrompt = trim((string) ($currentModelPrompts[$model] ?? ''));
            $currentName = trim((string) ($currentModelPromptNames[$model] ?? ''));
            $currentMode = (($currentModelPromptModes[$model] ?? 'append') === 'override') ? 'override' : 'append';
            $nextPrompt = trim((string) ($modelPrompts[$model] ?? ''));
            $nextName = trim((string) ($modelPromptNames[$model] ?? ''));
            $nextMode = (($modelPromptModes[$model] ?? 'append') === 'override') ? 'override' : 'append';
            $hasChanged = $currentPrompt !== $nextPrompt || $currentName !== $nextName || $currentMode !== $nextMode;

            if ($currentPrompt !== '' && $hasChanged) {
                $this->storePromptHistoryEntry($currentPrompt, $currentName, $historyLimit, 'model', $model, $currentMode);
            }

            if ($nextPrompt !== '' && $hasChanged) {
                $this->storeCurrentPromptSnapshot($nextPrompt, $nextName, $historyLimit, 'model', $model, $nextMode);
            }
        }

        $this->persistModelPromptOption(self::OPTION_MODEL_PROMPTS, $modelPrompts);
        $this->persistModelPromptOption(self::OPTION_MODEL_PROMPT_NAMES, $modelPromptNames);
        $this->persistModelPromptOption(self::OPTION_MODEL_PROMPT_MODES, $modelPromptModes);
    }

    /**
     * @param string $optionName
     * @param array<string,string> $values
     * @return void
     */
    private function persistModelPromptOption(string $optionName, array $values): void {
        if (!empty($values)) {
            $this->updateScopedOption($optionName, $values);
            return;
        }

        $this->deleteScopedOption($optionName);
    }

    /**
     * @return void
     */
    private function savePromptHistoryNamesFromRequest(): void {
        if (isset($_POST['geweb_ai_search_prompt_history_names']) && is_array($_POST['geweb_ai_search_prompt_history_names'])) {
            $this->updatePromptHistoryNames(wp_unslash($_POST['geweb_ai_search_prompt_history_names']));
        }
    }

    /**
     * @param string $prompt
     * @param int $limit
     * @return void
     */
    private function storePromptHistory(string $prompt, int $limit): void {
        $this->storePromptHistoryEntry($prompt, '', $limit);
    }

    /**
     * @param string $prompt
     * @param string $name
     * @param int $limit
     * @param string $scope
     * @param string $model
     * @param string $mode
     * @return void
     */
    private function storeCurrentPromptSnapshot(string $prompt, string $name, int $limit, string $scope = 'global', string $model = '', string $mode = 'base'): void {
        $this->storePromptHistoryEntry($prompt, $name, $limit, $scope, $model, $mode);
    }

    /**
     * @param string $prompt
     * @param string $name
     * @param int $limit
     * @param string $scope
     * @param string $model
     * @param string $mode
     * @return void
     */
    private function storePromptHistoryEntry(string $prompt, string $name, int $limit, string $scope = 'global', string $model = '', string $mode = 'base'): void {
        $prompt = trim($prompt);
        if ($prompt === '' || $limit <= 0) {
            return;
        }

        $scope = $scope === 'model' ? 'model' : 'global';
        $model = $scope === 'model' ? sanitize_text_field($model) : '';
        if ($mode === 'override') {
            $mode = 'override';
        } elseif ($mode === 'append') {
            $mode = 'append';
        } else {
            $mode = 'base';
        }

        if ($scope === 'global' && $prompt === trim($this->getDefaultPrompt())) {
            $name = self::LABEL_DEFAULT_PROMPT;
        }

        $history = PromptSupport::normalizePromptHistoryEntries($this->getScopedOption(self::OPTION_PROMPT_HISTORY, []));
        array_unshift($history, [
            'entry_id' => wp_generate_uuid4(),
            'prompt' => $prompt,
            'saved_at' => current_time('timestamp'),
            'name' => $name,
            'scope' => $scope,
            'model' => $model,
            'mode' => $mode,
        ]);

        $uniqueHistory = [];
        $seen = [];
        $counts = [];
        foreach ($history as $entry) {
            $entryPrompt = isset($entry['prompt']) ? trim((string) ($entry['prompt'] ?? '')) : '';
            $entryScope = (($entry['scope'] ?? 'global') === 'model') ? 'model' : 'global';
            $entryModel = $entryScope === 'model' ? sanitize_text_field((string) ($entry['model'] ?? '')) : '';
            $rawEntryMode = (string) ($entry['mode'] ?? 'base');
            if ($rawEntryMode === 'override') {
                $entryMode = 'override';
            } elseif ($rawEntryMode === 'append') {
                $entryMode = 'append';
            } else {
                $entryMode = 'base';
            }

            $scopeKey = $entryScope . '|' . $entryModel;
            $dedupeKey = $scopeKey . '|' . $entryMode . '|' . $entryPrompt;
            if ($entryPrompt === '' || isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $counts[$scopeKey] = (int) ($counts[$scopeKey] ?? 0);
            if ($counts[$scopeKey] >= $limit) {
                continue;
            }

            $uniqueHistory[] = [
                'entry_id' => sanitize_text_field((string) ($entry['entry_id'] ?? wp_generate_uuid4())),
                'prompt' => $entryPrompt,
                'saved_at' => intval($entry['saved_at'] ?? current_time('timestamp')),
                'name' => sanitize_text_field((string) ($entry['name'] ?? '')),
                'scope' => $entryScope,
                'model' => $entryModel,
                'mode' => $entryMode,
            ];
            $counts[$scopeKey] += 1;
        }

        $this->updateScopedOption(self::OPTION_PROMPT_HISTORY, $uniqueHistory);
    }

    /**
     * @param array<string,string> $names
     * @return void
     */
    private function updatePromptHistoryNames(array $names): void {
        $history = PromptSupport::normalizePromptHistoryEntries($this->getScopedOption(self::OPTION_PROMPT_HISTORY, []));
        if (empty($history)) {
            return;
        }

        $newHistory = [];
        $seenPrompts = [];
        foreach ($history as $entry) {
            $entryId = (string) ($entry['entry_id'] ?? '');
            $entryPrompt = isset($entry['prompt']) ? trim((string) $entry['prompt']) : '';
            $entryScope = (($entry['scope'] ?? 'global') === 'model') ? 'model' : 'global';
            $entryModel = $entryScope === 'model' ? sanitize_text_field((string) ($entry['model'] ?? '')) : '';
            $scopeKey = $entryScope . '|' . $entryModel;
            $seenKey = $scopeKey . '|' . $entryPrompt;
            $isDefaultPromptEntry = $entryScope === 'global' && $entryPrompt !== '' && $entryPrompt === trim($this->getDefaultPrompt());
            if ($isDefaultPromptEntry) {
                $entry['name'] = self::LABEL_DEFAULT_PROMPT;
                $newHistory[] = $entry;
                $seenPrompts[$seenKey] = true;
                continue;
            }

            if (!isset($seenPrompts[$seenKey]) && $entryId !== '' && isset($names[$entryId])) {
                $entry['name'] = sanitize_text_field($names[$entryId]);
            }
            $newHistory[] = $entry;
            if ($entryPrompt !== '') {
                $seenPrompts[$seenKey] = true;
            }
        }

        if ($newHistory !== $history) {
            $this->updateScopedOption(self::OPTION_PROMPT_HISTORY, $newHistory);
        }
    }

    /**
     * @return string
     */
    private function getDefaultPrompt(): string {
        return ProviderFactory::make()->getDefaultSystemInstruction();
    }

    /**
     * @param int $limit
     * @return void
     */
    private function trimPromptHistory(int $limit): void {
        $history = PromptSupport::normalizePromptHistoryEntries($this->getScopedOption(self::OPTION_PROMPT_HISTORY, []));
        if (empty($history)) {
            $this->updateScopedOption(self::OPTION_PROMPT_HISTORY, []);
            return;
        }

        $trimmed = [];
        $counts = [];
        foreach ($history as $entry) {
            $scope = (($entry['scope'] ?? 'global') === 'model') ? 'model' : 'global';
            $model = $scope === 'model' ? sanitize_text_field((string) ($entry['model'] ?? '')) : '';
            $scopeKey = $scope . '|' . $model;
            $counts[$scopeKey] = (int) ($counts[$scopeKey] ?? 0);
            if ($counts[$scopeKey] >= $limit) {
                continue;
            }

            $trimmed[] = $entry;
            $counts[$scopeKey] += 1;
        }

        $this->updateScopedOption(self::OPTION_PROMPT_HISTORY, $trimmed);
    }

    /**
     * @return void
     */
    private function clearLocalIndexTracking(): void {
        $documentManager = new ReferencedDocumentManager();
        $documentManager->clearAllTrackedDocuments();
        PostIndexManager::clearAllIndexedState();
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    private function getScopedOption(string $optionName, $default = false) {
        return UserScope::getGroupScopedOption($optionName, $default);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function updateScopedOption(string $optionName, $value): bool {
        return UserScope::updateGroupScopedOption($optionName, $value, false);
    }

    private function deleteScopedOption(string $optionName): void {
        UserScope::deleteGroupScopedOption($optionName);
    }

    /**
     * @param mixed $value
     * @param string $default
     * @return string
     */
    private function normalizeFrontendAiInterface($value, string $default = self::DEFAULT_FRONTEND_AI_INTERFACE): string {
        $normalized = sanitize_key((string) $value);
        return in_array($normalized, ['modal', 'fullscreen'], true) ? $normalized : $default;
    }

    /**
     * @param mixed $value
     * @param int $default
     * @param int $min
     * @param int $max
     * @return int
     */
    private function sanitizePositiveIntOption($value, int $default, int $min, int $max): int {
        if ($value === null || $value === '') {
            return $default;
        }

        $normalized = intval($value);
        if ($normalized < $min) {
            return $min;
        }

        if ($normalized > $max) {
            return $max;
        }

        return $normalized;
    }
}
