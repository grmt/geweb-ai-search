<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Builds the admin settings page view model.
 */
class AdminPageConfigBuilder {
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
        $modelStatuses = $provider->getModelStatuses();
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
        $preserveDataOnUninstall = get_option('geweb_aisearch_preserve_data_on_uninstall', '0') === '1';
        $frontendAiInterface = FrontendAiContext::getFrontendAiInterface();
        $frontendAiPageId = FrontendAiContext::getFrontendAiPageId();
        $frontendAiPageUrl = FrontendAiContext::getFrontendAiPageUrl();
        $conversationTrimMessageLimit = FrontendAiContext::getConversationTrimMessageLimit();
        $conversationTrimCharLimit = FrontendAiContext::getConversationTrimCharLimit();
        $localConversationArchiveLimit = FrontendAiContext::getLocalConversationArchiveLimit();
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
        if (!$hasReferencedDocumentCache && $activeTab === 'documents') {
            $documentStore->getReferencedDocumentOverview(true);
            $hasReferencedDocumentCache = $documentStore->hasReferencedDocumentOverviewCache();
        }
        $referencedCacheTime = $documentStore->getReferencedDocumentOverviewCacheTime();
        $referencedDebug = $documentStore->getReferencedDocumentOverviewDebug();
        $providerHasStoreCache = $provider instanceof Gemini ? $provider->hasStoreOverviewCache() : false;
        $providerStoreCacheTime = $provider instanceof Gemini ? $provider->getStoreOverviewCacheTime() : 0;
        $providerStoreError = $provider instanceof Gemini ? $provider->getStoreOverviewError() : '';

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
        $referencedDocumentsHtml = AdminPageSupport::renderPanelHtml($hasReferencedDocumentCache, $this->renderReferencedDocumentsTable, 'Referenced documents could not be loaded yet. Use Refresh List to try again.');
        $geminiStoresHtml = AdminPageSupport::renderPanelHtml($providerHasStoreCache, $this->renderGeminiStoresTable, 'Loading Gemini stores for the first time. This can take a moment if multiple stores need to be checked.');
        $geminiStoreDocumentsPanelHtml = AdminPageSupport::renderInitialGeminiStoreDocumentsPanel(
            $providerHasStoreCache,
            $provider,
            $this->getInitialGeminiStoreSelection,
            $this->renderGeminiStoreDocumentsPanel
        );
        $conversationsHtml = AdminPageSupport::captureHtml($this->renderConversationsTable);
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
            'geminiStoreDocumentsPanelHtml' => $geminiStoreDocumentsPanelHtml,
            'geminiStoresHtml' => $geminiStoresHtml,
            'groupDataRevision' => $groupDataRevision,
            'hasReferencedDocumentCache' => $hasReferencedDocumentCache,
            'hasValidSavedApiKey' => $hasValidSavedApiKey,
            'includeReferencedDocuments' => $includeReferencedDocuments,
            'inlineStyleHidden' => 'style="display:none;"',
            'isGeminiProvider' => $provider instanceof Gemini,
            'localConversationArchiveLimit' => $localConversationArchiveLimit,
            'modelPromptRows' => $modelPromptRows,
            'modelStatuses' => $modelStatuses,
            'models' => $models,
            'postTypes' => $postTypes,
            'preserveDataOnUninstall' => $preserveDataOnUninstall,
            'promptHistoryItems' => $promptHistoryItems,
            'promptHistoryLimit' => $promptHistoryLimit,
            'promptsTabUrl' => $promptsTabUrl,
            'providerHasStoreCache' => $providerHasStoreCache,
            'providerStoreCacheLabel' => $providerStoreCacheTime > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $providerStoreCacheTime) : '',
            'providerStoreCacheTime' => $providerStoreCacheTime,
            'providerStoreError' => $providerStoreError,
            'referencedCacheLabel' => $referencedCacheTime > 0 ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), $referencedCacheTime) : '',
            'referencedCacheTime' => $referencedCacheTime,
            'referencedDebug' => $referencedDebug,
            'referencedDocumentsHtml' => $referencedDocumentsHtml,
            'selectedModel' => $selectedModel,
            'selectedProvider' => $selectedProvider,
            'statusColorError' => '#d63638',
            'statusColorNeutral' => '#646970',
            'statusColorSuccess' => '#46b450',
            'storeEnabled' => $storeEnabled,
            'storesTabUrl' => $storesTabUrl,
            'conversationsTabUrl' => $conversationsTabUrl,
            'conflictNotice' => $conflictNotice,
        ];
    }
}
