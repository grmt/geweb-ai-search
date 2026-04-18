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
        $pluginUpdateGuardActive = PluginUpdateGuard::isActive();
        $pluginUpdateGuardMessage = PluginUpdateGuard::getNoticeMessage();
        $referencedDocumentsHtml = AdminPageSupport::renderPanelHtml(
            $pluginUpdateGuardActive ? $hasReferencedDocumentCache : $hasReferencedDocumentCache,
            $this->renderReferencedDocumentsTable,
            $pluginUpdateGuardActive
                ? 'Workspace AI Search is updating. Files will be available again in a moment.'
                : 'Loading referenced documents...'
        );
        $geminiStoresHtml = AdminPageSupport::renderPanelHtml(
            $pluginUpdateGuardActive ? $providerHasStoreCache : $providerHasStoreCache,
            $this->renderGeminiStoresTable,
            $pluginUpdateGuardActive
                ? 'Workspace AI Search is updating. Gemini Stores will be available again in a moment.'
                : 'Loading Gemini stores...'
        );
        $geminiStoreDocumentsPanelHtml = $pluginUpdateGuardActive && !$providerHasStoreCache
            ? '<div id="geweb-gemini-store-documents-panel" data-store-name="" style="margin-top:20px; padding:16px; background:#fff; border:1px solid #dcdcde;"><div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;"><div><strong id="geweb-gemini-store-documents-title">Uploaded Items</strong><div id="geweb-gemini-store-documents-subtitle" class="description" style="margin-top:4px;">Uploaded items in the selected Gemini File Search Store.</div></div><button type="button" class="button" id="geweb-refresh-gemini-store-documents" disabled>Refresh List</button></div><div id="geweb-gemini-store-documents-status" class="description" style="margin-bottom:12px; color:#646970;">Workspace AI Search is updating. Uploaded items will be available again in a moment.</div><p id="geweb-gemini-store-documents-error" class="description" style="margin:0 0 12px; color:#d63638; display:none;"></p><div id="geweb-gemini-store-documents-container"><p style="margin:0;">Workspace AI Search is updating. Uploaded items will be available again in a moment.</p></div></div>'
            : AdminPageSupport::renderInitialGeminiStoreDocumentsPanel(
                $providerHasStoreCache,
                $provider,
                $this->getInitialGeminiStoreSelection,
                $this->renderGeminiStoreDocumentsPanel
            );
        $conversationsHtml = AdminPageSupport::captureHtml($this->renderConversationsTable);
        $groupDataRevision = GroupDataRevision::ensureCurrentRevision();
        $adminViewCacheState = AdminViewRevision::ensureCurrentState();
        $conflictNotice = isset($_GET['geweb_conflict']) ? sanitize_text_field(wp_unslash($_GET['geweb_conflict'])) : '';

        return [
            'activeTab' => $activeTab,
            'adminViewCacheState' => $adminViewCacheState,
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
            'pluginUpdateGuardActive' => $pluginUpdateGuardActive,
            'pluginUpdateGuardMessage' => $pluginUpdateGuardMessage,
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
            'adminPanelsNeedPreload' => !$pluginUpdateGuardActive
                && (($activeTab === 'documents' && !$hasReferencedDocumentCache)
                || ($activeTab === 'stores' && !$providerHasStoreCache)),
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
        $workingModels = [];
        foreach ($models as $model) {
            $status = $modelStatuses[(string) $model] ?? null;
            if (!is_array($status) || (($status['status'] ?? '') !== 'ok')) {
                continue;
            }

            $resolvedModel = trim((string) ($status['resolved_model'] ?? ''));
            $workingModels[] = $resolvedModel !== '' ? $resolvedModel : (string) $model;
        }

        $workingModels = array_values(array_unique(array_filter($workingModels, static function ($model): bool {
            return is_string($model) && trim($model) !== '';
        })));

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
