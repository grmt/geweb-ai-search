<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Builds the admin settings page view model.
 */
class AdminPageConfigBuilder {
    private const OFFICIAL_GEMINI_FLASH_LATEST = 'gemini-3-flash-preview';
    private const OFFICIAL_GEMINI_PRO_LATEST = 'gemini-3.1-pro-preview';
    private const GEMINI_CHANGELOG_URL = 'https://ai.google.dev/gemini-api/docs/changelog';
    private const GEMINI_DEPRECATIONS_URL = 'https://ai.google.dev/gemini-api/docs/deprecations';
    /**
     * @var callable
     */
    private $getTabUrl;
    /**
     * @var callable
     */
    private $renderReferencedDocumentsTable;
    /**
     * @var callable
     */
    private $renderGeminiStoresTable;
    /**
     * @var callable
     */
    private $getInitialGeminiStoreSelection;
    /**
     * @var callable
     */
    private $renderGeminiStoreDocumentsPanel;
    /**
     * @var callable
     */
    private $renderConversationsTable;
    /**
     * @var callable
     */
    private $supportsFileSearchModel;

    public function __construct(
        callable $getTabUrl,
        callable $renderReferencedDocumentsTable,
        callable $renderGeminiStoresTable,
        callable $getInitialGeminiStoreSelection,
        callable $renderGeminiStoreDocumentsPanel,
        callable $renderConversationsTable,
        callable $supportsFileSearchModel
    ) {
        $this->getTabUrl = $getTabUrl;
        $this->renderReferencedDocumentsTable = $renderReferencedDocumentsTable;
        $this->renderGeminiStoresTable = $renderGeminiStoresTable;
        $this->getInitialGeminiStoreSelection = $getInitialGeminiStoreSelection;
        $this->renderGeminiStoreDocumentsPanel = $renderGeminiStoreDocumentsPanel;
        $this->renderConversationsTable = $renderConversationsTable;
        $this->supportsFileSearchModel = $supportsFileSearchModel;
    }

    /**
     * @return array<string,mixed>
     */
    public function build(): array {
        $provider = ProviderFactory::make();
        $storeEnabled = !empty($provider->getStoreData());

        $models = $provider->getModels();
        $selectedModel = $provider->getModel();
        if ($selectedModel !== '' && !in_array($selectedModel, $models, true) && ($this->supportsFileSearchModel)($selectedModel)) {
            array_unshift($models, $selectedModel);
            $models = array_values(array_unique($models));
        }

        $defaultModel = $provider->getDefaultModel($models);
        $latestModelHints = $this->buildLatestModelHints($models);
        $officialLatestAliases = $this->buildOfficialLatestAliases();
        $modelStatuses = $provider->getModelStatuses();
        $workingModelHints = $this->buildWorkingModelHints($models, $modelStatuses);
        $connectionStatus = get_option('geweb_aisearch_connection_status', []);
        $hasValidSavedApiKey = is_array($connectionStatus) && (($connectionStatus['status'] ?? '') === 'ok');
        $selectedProvider = ProviderFactory::getConfiguredProviderKey();
        $availableProviders = ProviderFactory::getAvailableProviders();
        $customPrompt = UserScope::getGroupScopedOption('geweb_aisearch_custom_prompt', '');
        $customPromptName = UserScope::getGroupScopedOption('geweb_aisearch_custom_prompt_name', '');
        $modelPromptOverrides = UserScope::getGroupScopedOption('geweb_aisearch_model_prompts', []);
        $modelPromptOverrideNames = UserScope::getGroupScopedOption('geweb_aisearch_model_prompt_names', []);
        $modelPromptOverrideModes = UserScope::getGroupScopedOption('geweb_aisearch_model_prompt_modes', []);
        $defaultPrompt = $provider->getDefaultSystemInstruction();
        $isUsingDefaultPrompt = trim((string) $customPrompt) === '';
        $modelPromptOverrides = is_array($modelPromptOverrides) ? $modelPromptOverrides : [];
        $modelPromptOverrideNames = is_array($modelPromptOverrideNames) ? $modelPromptOverrideNames : [];
        $modelPromptOverrideModes = is_array($modelPromptOverrideModes) ? $modelPromptOverrideModes : [];
        $promptHistoryLimit = (int) UserScope::getGroupScopedOption('geweb_aisearch_prompt_history_limit', 10);
        $promptHistory = PromptSupport::normalizePromptHistoryEntries(UserScope::getGroupScopedOption('geweb_aisearch_prompt_history', []));
        $postTypes = get_option('geweb_aisearch_post_types', []);
        $includeReferencedDocuments = get_option('geweb_aisearch_include_referenced_documents', '0') === '1';
        $ocrAllUploadImages = UserScope::getGroupScopedOption('geweb_aisearch_ocr_all_upload_images', '0') === '1';
        $dateDisplayFormat = DateDisplay::getDateDisplayFormat();
        $availableDateDisplayFormats = DateDisplay::getAvailableFormats();
        $preserveDataOnUninstall = get_option('geweb_aisearch_preserve_data_on_uninstall', '0') === '1';
        $frontendAiInterface = FrontendAiContext::getFrontendAiInterface();
        $frontendAiPageId = FrontendAiContext::getFrontendAiPageId();
        $frontendAiPageUrl = FrontendAiContext::getFrontendAiPageUrl();
        $conversationTrimMessageLimit = FrontendAiContext::getConversationTrimMessageLimit();
        $conversationTrimCharLimit = FrontendAiContext::getConversationTrimCharLimit();
        $localConversationArchiveLimit = FrontendAiContext::getLocalConversationArchiveLimit();
        $storedContextMessageLimit = FrontendAiContext::getStoredContextMessageLimit();
        $storedContextCharLimit = FrontendAiContext::getStoredContextCharLimit();
        $allPostTypes = get_post_types(['public' => true], 'objects');
        $activeTab = isset($_GET['geweb_tab']) ? sanitize_key(wp_unslash($_GET['geweb_tab'])) : 'general';
        if ($activeTab === 'ai') {
            $activeTab = 'prompts';
        }
        if (!in_array($activeTab, ['general', 'prompts', 'documents', 'stores', 'conversations'], true)) {
            $activeTab = 'general';
        }

        $documentStore = new DocumentStore();
        $hasReferencedDocumentCache = $documentStore->hasReferencedDocumentOverviewCache();
        $referencedCacheTime = $documentStore->getReferencedDocumentOverviewCacheTime();
        $referencedDebug = $documentStore->getReferencedDocumentOverviewDebug();
        $providerHasStoreCache = $provider instanceof Gemini ? $provider->hasStoreOverviewCache() : false;
        $providerStoreCacheTime = $provider instanceof Gemini ? $provider->getStoreOverviewCacheTime() : 0;
        $providerStoreError = $provider instanceof Gemini ? $provider->getStoreOverviewError() : '';
        $storageEstimate = (new GeminiStorageEstimator($documentStore, new MarkdownCacheStore()))->buildEstimate();

        $currentPromptLabel = 'Built-in default prompt';
        if (!$isUsingDefaultPrompt) {
            $currentPromptLabel = $customPromptName !== '' ? $customPromptName : 'Custom prompt';
        }

        $frontendAiPageTitle = $frontendAiPageId > 0 ? get_the_title($frontendAiPageId) : '';
        $generalTabUrl = ($this->getTabUrl)('general');
        $promptsTabUrl = ($this->getTabUrl)('prompts');
        $documentsTabUrl = ($this->getTabUrl)('documents');
        $storesTabUrl = ($this->getTabUrl)('stores');
        $conversationsTabUrl = ($this->getTabUrl)('conversations');
        $modelPromptRows = AdminPageSupport::buildModelPromptRows($models, $selectedModel, $modelPromptOverrides, $modelPromptOverrideNames, $modelPromptOverrideModes, $provider, $defaultPrompt);
        $promptHistoryItems = AdminPageSupport::buildPromptHistoryItems($promptHistory, $defaultPrompt, 'Default prompt');
        $documentsApiStatus = AdminPageSupport::buildApiStatusDisplay($connectionStatus, '#46b450', '#646970', '#d63638');
        $referencedDocumentsHtml = $activeTab === 'documents'
            ? AdminPageSupport::renderPanelHtml($hasReferencedDocumentCache, $this->renderReferencedDocumentsTable, 'Referenced documents could not be loaded yet. Use Refresh List to try again.')
            : '<p>Open the Documents tab to load referenced documents.</p>';
        $geminiStoresHtml = $activeTab === 'stores'
            ? AdminPageSupport::renderPanelHtml($providerHasStoreCache, $this->renderGeminiStoresTable, 'Loading Gemini stores for the first time. This can take a moment if multiple stores need to be checked.')
            : '<p>Open the Gemini Stores tab to load store data.</p>';
        $geminiStoreDocumentsPanelHtml = $activeTab === 'stores'
            ? AdminPageSupport::renderInitialGeminiStoreDocumentsPanel(
                $providerHasStoreCache,
                $provider,
                $this->getInitialGeminiStoreSelection,
                $this->renderGeminiStoreDocumentsPanel
            )
            : '';
        $conversationsHtml = $activeTab === 'conversations'
            ? AdminPageSupport::captureHtml($this->renderConversationsTable)
            : '<p>Open the Chats tab to load saved chats.</p>';
        $groupDataRevision = GroupDataRevision::ensureCurrentRevision();
        $conflictNotice = isset($_GET['geweb_conflict']) ? sanitize_text_field(wp_unslash($_GET['geweb_conflict'])) : '';

        return [
            'activeTab' => $activeTab,
            'allPostTypes' => $allPostTypes,
            'availableProviders' => $availableProviders,
            'connectionStatus' => $connectionStatus,
            'conversationTrimCharLimit' => $conversationTrimCharLimit,
            'conversationTrimMessageLimit' => $conversationTrimMessageLimit,
            'conversationsHtml' => $conversationsHtml,
            'currentPromptLabel' => $currentPromptLabel,
            'customPrompt' => $customPrompt,
            'customPromptName' => $customPromptName,
            'dateDisplayFormat' => $dateDisplayFormat,
            'availableDateDisplayFormats' => $availableDateDisplayFormats,
            'defaultModel' => $defaultModel,
            'defaultPrompt' => $defaultPrompt,
            'documentsApiStatusColor' => $documentsApiStatus['color'],
            'documentsApiStatusLabel' => $documentsApiStatus['label'],
            'documentsApiStatusMessage' => $documentsApiStatus['message'],
            'documentsTabUrl' => $documentsTabUrl,
            'frontendAiInterface' => $frontendAiInterface,
            'frontendAiPageId' => $frontendAiPageId,
            'frontendAiPageTitle' => $frontendAiPageTitle,
            'frontendAiPageUrl' => $frontendAiPageUrl,
            'generalTabUrl' => $generalTabUrl,
            'geminiChangelogUrl' => self::GEMINI_CHANGELOG_URL,
            'geminiDeprecationsUrl' => self::GEMINI_DEPRECATIONS_URL,
            'geminiStoreDocumentsPanelHtml' => $geminiStoreDocumentsPanelHtml,
            'geminiStoresHtml' => $geminiStoresHtml,
            'groupDataRevision' => $groupDataRevision,
            'hasReferencedDocumentCache' => $hasReferencedDocumentCache,
            'hasValidSavedApiKey' => $hasValidSavedApiKey,
            'includeReferencedDocuments' => $includeReferencedDocuments,
            'inlineStyleHidden' => 'style="display:none;"',
            'isGeminiProvider' => $provider instanceof Gemini,
            'ocrAllUploadImages' => $ocrAllUploadImages,
            'officialLatestAliases' => $officialLatestAliases,
            'localConversationArchiveLimit' => $localConversationArchiveLimit,
            'latestModelHints' => $latestModelHints,
            'modelPromptRows' => $modelPromptRows,
            'modelStatuses' => $modelStatuses,
            'models' => $models,
            'workingModelHints' => $workingModelHints,
            'postTypes' => $postTypes,
            'preserveDataOnUninstall' => $preserveDataOnUninstall,
            'promptHistoryItems' => $promptHistoryItems,
            'promptHistoryLimit' => $promptHistoryLimit,
            'promptsTabUrl' => $promptsTabUrl,
            'providerHasStoreCache' => $providerHasStoreCache,
            'providerStoreCacheLabel' => $providerStoreCacheTime > 0 ? DateDisplay::formatDateTime($providerStoreCacheTime) : '',
            'providerStoreCacheTime' => $providerStoreCacheTime,
            'providerStoreError' => $providerStoreError,
            'referencedCacheLabel' => $referencedCacheTime > 0 ? DateDisplay::formatDateTime($referencedCacheTime) : '',
            'referencedCacheTime' => $referencedCacheTime,
            'referencedDebug' => $referencedDebug,
            'referencedDocumentsHtml' => $referencedDocumentsHtml,
            'selectedModel' => $selectedModel,
            'selectedProvider' => $selectedProvider,
            'storageEstimate' => $storageEstimate,
            'statusColorError' => '#d63638',
            'statusColorNeutral' => '#646970',
            'statusColorSuccess' => '#46b450',
            'storedContextCharLimit' => $storedContextCharLimit,
            'storedContextMessageLimit' => $storedContextMessageLimit,
            'storeEnabled' => $storeEnabled,
            'storesTabUrl' => $storesTabUrl,
            'conversationsTabUrl' => $conversationsTabUrl,
            'conflictNotice' => $conflictNotice,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function buildOfficialLatestAliases(): array {
        return [
            'flash_latest' => self::OFFICIAL_GEMINI_FLASH_LATEST,
            'pro_latest' => self::OFFICIAL_GEMINI_PRO_LATEST,
        ];
    }

    /**
     * @param array<int,string> $models
     * @return array<string,string>
     */
    private function buildLatestModelHints(array $models): array {
        $latestFlash = $this->pickLatestModelByFamily($models, 'flash');
        $latestPro = $this->pickLatestModelByFamily($models, 'pro');
        $latestStableFlash = $this->pickLatestModelByFamily($models, 'flash', true);
        $latestStablePro = $this->pickLatestModelByFamily($models, 'pro', true);

        return [
            'flash' => $latestFlash,
            'pro' => $latestPro,
            'stable_flash' => $latestStableFlash,
            'stable_pro' => $latestStablePro,
        ];
    }

    /**
     * @param array<int,string> $models
     * @param array<string,mixed> $modelStatuses
     * @return array<string,string>
     */
    private function buildWorkingModelHints(array $models, array $modelStatuses): array {
        return [
            'flash' => $this->pickLatestWorkingModelByFamily($models, $modelStatuses, 'flash'),
            'pro' => $this->pickLatestWorkingModelByFamily($models, $modelStatuses, 'pro'),
        ];
    }

    /**
     * @param array<int,string> $models
     * @param array<string,mixed> $modelStatuses
     */
    private function pickLatestWorkingModelByFamily(array $models, array $modelStatuses, string $family): string {
        $workingModels = array_values(array_filter($models, function ($model) use ($modelStatuses): bool {
            $status = $modelStatuses[(string) $model] ?? null;
            return is_array($status) && (($status['status'] ?? '') === 'ok');
        }));

        return $this->pickLatestModelByFamily($workingModels, $family);
    }

    /**
     * @param array<int,string> $models
     */
    private function pickLatestModelByFamily(array $models, string $family, bool $stableOnly = false): string {
        $bestModel = '';
        $bestRank = null;

        foreach ($models as $model) {
            $normalizedModel = strtolower(trim((string) $model));
            if ($normalizedModel === '') {
                continue;
            }

            if ($family === 'flash') {
                if (!str_contains($normalizedModel, '-flash') || str_contains($normalizedModel, 'flash-lite')) {
                    continue;
                }
            } elseif ($family === 'pro') {
                if (!str_contains($normalizedModel, '-pro')) {
                    continue;
                }
            } else {
                continue;
            }

            if ($stableOnly && str_contains($normalizedModel, 'preview')) {
                continue;
            }

            $rank = $this->rankModelName($normalizedModel);
            if ($bestRank === null || $rank > $bestRank) {
                $bestRank = $rank;
                $bestModel = (string) $model;
            }
        }

        return $bestModel;
    }

    /**
     * @return array<int,int>
     */
    private function rankModelName(string $model): array {
        $major = 0;
        $minor = 0;
        if (preg_match('/gemini-(\d+)(?:\.(\d+))?/i', $model, $matches)) {
            $major = isset($matches[1]) ? (int) $matches[1] : 0;
            $minor = isset($matches[2]) ? (int) $matches[2] : 0;
        }

        return [
            $major,
            $minor,
            str_contains($model, 'preview') ? 0 : 1,
            str_contains($model, 'lite') ? 0 : 1,
        ];
    }
}
