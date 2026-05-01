<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Handles saving and prompt-history related admin settings.
 */
class AdminSettingsManager {
    private const OPTION_CUSTOM_PROMPT = 'geweb_aisearch_custom_prompt';
    private const OPTION_PROMPT_HISTORY_LIMIT = 'geweb_aisearch_prompt_history_limit';
    private const OPTION_CUSTOM_PROMPT_NAME = 'geweb_aisearch_custom_prompt_name';
    private const OPTION_PROVIDER = 'geweb_aisearch_provider';
    private const OPTION_MODEL_SELECTION_MODE = 'geweb_aisearch_model_selection_mode';
    private const OPTION_INCLUDE_REFERENCED_DOCUMENTS = 'geweb_aisearch_include_referenced_documents';
    private const OPTION_OCR_ALL_UPLOAD_IMAGES = 'geweb_aisearch_ocr_all_upload_images';
    private const OPTION_DATE_DISPLAY_FORMAT = 'geweb_aisearch_date_display_format';
    private const OPTION_PRESERVE_DATA_ON_UNINSTALL = 'geweb_aisearch_preserve_data_on_uninstall';
    private const OPTION_FRONTEND_AI_INTERFACE = 'geweb_aisearch_frontend_ai_interface';
    private const OPTION_SHARE_GEMINI_CONFIG_WITH_FRONTEND = 'geweb_aisearch_share_gemini_config_with_frontend';
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
        $this->saveWorkspaceSettings();
        $this->applySubmittedReferencedDocumentTargets();
        $historyLimit = $this->savePromptHistoryLimit();
        $this->saveCustomPromptSettings($historyLimit);
        $this->saveModelPromptOverrides($historyLimit);
        $this->savePromptHistoryNamesFromRequest();
        AdminViewRevision::touchPrompts();
        AdminViewRevision::touchFiles();
        GroupDataRevision::touch();
    }

    private function saveWorkspaceSettings(): void {
        (new WorkspaceAdminManager())->saveFromRequest();
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
            UserScope::updateWorkspaceConfigOption('geweb_aisearch_model', $submittedModel, false);

            $selectionMode = $submittedModel === $provider->getDefaultModel()
                ? self::MODEL_SELECTION_MODE_DEFAULT
                : self::MODEL_SELECTION_MODE_CUSTOM;
            UserScope::updateWorkspaceConfigOption(self::OPTION_MODEL_SELECTION_MODE, $selectionMode, false);
        }

        update_option(self::OPTION_INCLUDE_REFERENCED_DOCUMENTS, !empty($_POST['geweb_ai_search_include_referenced_documents']) ? '1' : '0');
        UserScope::updateGroupScopedOption(self::OPTION_OCR_ALL_UPLOAD_IMAGES, !empty($_POST['geweb_ai_search_ocr_all_upload_images']) ? '1' : '0', false);
        (new DocumentAiOcrService())->saveSettings(
            isset($_POST['geweb_ai_search_document_ai_project_id']) ? sanitize_text_field(wp_unslash($_POST['geweb_ai_search_document_ai_project_id'])) : '',
            isset($_POST['geweb_ai_search_document_ai_location']) ? sanitize_key(wp_unslash($_POST['geweb_ai_search_document_ai_location'])) : '',
            isset($_POST['geweb_ai_search_document_ai_processor_id']) ? sanitize_text_field(wp_unslash($_POST['geweb_ai_search_document_ai_processor_id'])) : '',
            isset($_POST['geweb_ai_search_document_ai_service_account_json']) ? (string) wp_unslash($_POST['geweb_ai_search_document_ai_service_account_json']) : '',
            !empty($_POST['geweb_ai_search_document_ai_clear_service_account'])
        );
        $submittedDateFormat = '';
        if (isset($_POST['geweb_ai_search_date_display_format'])) {
            $submittedDateFormat = sanitize_key(wp_unslash($_POST['geweb_ai_search_date_display_format']));
        }
        $dateDisplayFormat = DateDisplay::normalizeFormat($submittedDateFormat) ?: DateDisplay::FORMAT_ISO;
        UserScope::updateGroupScopedOption(self::OPTION_DATE_DISPLAY_FORMAT, $dateDisplayFormat, false);
        update_option(self::OPTION_PRESERVE_DATA_ON_UNINSTALL, !empty($_POST['geweb_ai_search_preserve_data_on_uninstall']) ? '1' : '0');
        $submittedFrontendInterface = $_POST['geweb_ai_search_frontend_ai_interface'] ?? '';
        update_option(
            self::OPTION_FRONTEND_AI_INTERFACE,
            $this->normalizeFrontendAiInterface(wp_unslash($submittedFrontendInterface))
        );
        UserScope::updateWorkspaceConfigOption(self::OPTION_SHARE_GEMINI_CONFIG_WITH_FRONTEND, !empty($_POST['geweb_ai_search_share_gemini_config_with_frontend']) ? '1' : '0', false);

        foreach ([
            [self::OPTION_CONVERSATION_TRIM_MESSAGE_LIMIT, 'geweb_ai_search_conversation_trim_message_limit', self::DEFAULT_CONVERSATION_TRIM_MESSAGE_LIMIT, 2, 200],
            [self::OPTION_CONVERSATION_TRIM_CHAR_LIMIT, 'geweb_ai_search_conversation_trim_char_limit', self::DEFAULT_CONVERSATION_TRIM_CHAR_LIMIT, 500, 200000],
            [self::OPTION_LOCAL_CONVERSATION_ARCHIVE_LIMIT, 'geweb_ai_search_local_conversation_archive_limit', self::DEFAULT_LOCAL_CONVERSATION_ARCHIVE_LIMIT, 1, 200],
            [self::OPTION_STORED_CONTEXT_MESSAGE_LIMIT, 'geweb_ai_search_stored_context_message_limit', self::DEFAULT_STORED_CONTEXT_MESSAGE_LIMIT, 10, 500],
            [self::OPTION_STORED_CONTEXT_CHAR_LIMIT, 'geweb_ai_search_stored_context_char_limit', self::DEFAULT_STORED_CONTEXT_CHAR_LIMIT, 5000, 500000],
        ] as $optionConfig) {
            [$optionName, $postKey, $default, $min, $max] = $optionConfig;
            $submittedValue = $_POST[$postKey] ?? null;
            update_option($optionName, $this->sanitizePositiveIntOption(wp_unslash($submittedValue), $default, $min, $max));
        }

        $this->persistOptionalPositiveIntOption(
            Gemini::OPTION_TIMEOUT_FLASH,
            isset($_POST[Gemini::OPTION_TIMEOUT_FLASH]) ? wp_unslash($_POST[Gemini::OPTION_TIMEOUT_FLASH]) : null,
            15,
            300
        );
        $this->persistOptionalPositiveIntOption(
            Gemini::OPTION_TIMEOUT_PRO,
            isset($_POST[Gemini::OPTION_TIMEOUT_PRO]) ? wp_unslash($_POST[Gemini::OPTION_TIMEOUT_PRO]) : null,
            15,
            300
        );
        foreach ([
            [Gemini::OPTION_SYSTEM_RETRIES, Gemini::DEFAULT_SYSTEM_RETRIES, 1],
            [Gemini::OPTION_HUMAN_RETRIES, Gemini::DEFAULT_HUMAN_RETRIES, 0],
        ] as $retryConfig) {
            [$optionName, $default, $min] = $retryConfig;
            $submittedValue = $_POST[$optionName] ?? null;
            update_option($optionName, $this->sanitizePositiveIntOption(wp_unslash($submittedValue), $default, $min, 4));
        }
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
            (new AdminPromptHistoryManager())->storeEntry($currentPrompt, '', max(0, $historyLimit - 1));
        }

        if ($useDefaultPrompt) {
            UserScope::deleteSharedSearchScopedOption(self::OPTION_CUSTOM_PROMPT);
            UserScope::deleteSharedSearchScopedOption(self::OPTION_CUSTOM_PROMPT_NAME);
            if (trim($defaultPrompt) !== trim($currentPrompt)) {
                (new AdminPromptHistoryManager())->storeEntry($defaultPrompt, self::LABEL_DEFAULT_PROMPT, $historyLimit);
            }
            return;
        }

        $this->updateScopedOption(self::OPTION_CUSTOM_PROMPT, $newPrompt);
        $this->updateScopedOption(self::OPTION_CUSTOM_PROMPT_NAME, $newPromptName);
        if ($newPrompt !== $currentPrompt) {
            (new AdminPromptHistoryManager())->storeEntry($newPrompt, $newPromptName, $historyLimit);
        }
    }

    /**
     * @param int $historyLimit
     * @return void
     */
    private function saveModelPromptOverrides(int $historyLimit): void {
        (new AdminPromptHistoryManager())->saveModelPromptOverrides($historyLimit);
    }

    /**
     * @return void
     */
    private function savePromptHistoryNamesFromRequest(): void {
        if (isset($_POST['geweb_ai_search_prompt_history_names']) && is_array($_POST['geweb_ai_search_prompt_history_names'])) {
            (new AdminPromptHistoryManager())->updateNames(wp_unslash($_POST['geweb_ai_search_prompt_history_names']));
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
        (new AdminPromptHistoryManager())->trim($limit);
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
        if (in_array($optionName, [self::OPTION_CUSTOM_PROMPT, self::OPTION_CUSTOM_PROMPT_NAME], true)) {
            return UserScope::getSharedSearchScopedOption($optionName, $default);
        }

        return UserScope::getGroupScopedOption($optionName, $default);
    }

    /**
     * @param mixed $value
     * @return bool
     */
    private function updateScopedOption(string $optionName, $value): bool {
        if (in_array($optionName, [self::OPTION_CUSTOM_PROMPT, self::OPTION_CUSTOM_PROMPT_NAME], true)) {
            return UserScope::updateSharedSearchScopedOption($optionName, $value, false);
        }

        return UserScope::updateGroupScopedOption($optionName, $value, false);
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
        $normalized = $default;
        if ($value !== null && $value !== '') {
            $normalized = intval($value);
            if ($normalized < $min) {
                $normalized = $min;
            } elseif ($normalized > $max) {
                $normalized = $max;
            }
        }

        return $normalized;
    }

    /**
     * @param mixed $value
     * @return void
     */
    private function persistOptionalPositiveIntOption(string $optionName, $value, int $min, int $max): void {
        if ($value === null || $value === '') {
            delete_option($optionName);
            return;
        }

        update_option($optionName, $this->sanitizePositiveIntOption($value, $min, $min, $max));
    }
}
