<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Builds the admin settings page view model.
 */
class AdminPageConfigBuilder {
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
        $providerContext = $this->buildProviderContext($provider);
        $settingsContext = $this->buildSettingsContext($provider, $providerContext);
        $activeTab = $this->getActiveTab();
        $cacheContext = $this->buildCacheContext($provider);
        $guardContext = $this->buildPluginUpdateGuardContext();
        $panelContext = $this->buildPanelContext($provider, $cacheContext, $guardContext);

        return array_merge(
            $providerContext,
            $settingsContext,
            $cacheContext,
            $guardContext,
            $panelContext,
            $this->buildNavigationContext(),
            [
                'activeTab' => $activeTab,
                'adminViewCacheState' => AdminViewRevision::ensureCurrentState(),
                'groupDataRevision' => GroupDataRevision::ensureCurrentRevision(),
                'inlineStyleHidden' => 'style="display:none;"',
                'statusColorError' => '#d63638',
                'statusColorNeutral' => '#646970',
                'statusColorSuccess' => '#46b450',
                'conflictNotice' => $this->getConflictNotice(),
                'adminPanelsNeedPreload' => $this->adminPanelsNeedPreload($activeTab, $cacheContext, $guardContext),
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function buildProviderContext(AIProviderInterface $provider): array {
        $models = $provider->getModels();
        $selectedModel = $provider->getModel();
        $modelStatuses = $provider->getModelStatuses();
        $models = $this->includeSelectedModelIfAvailable($models, $selectedModel, $modelStatuses);
        $dropdownModels = $this->buildDropdownModels($models, $selectedModel, $provider);
        $modelHintsBuilder = new AdminPageModelHintsBuilder();

        return [
            'storeEnabled' => !empty($provider->getStoreData()),
            'connectionStatus' => get_option('geweb_aisearch_connection_status', []),
            'defaultModel' => $provider->getDefaultModel($models),
            'dropdownModels' => $dropdownModels,
            'hasValidSavedApiKey' => $this->hasValidSavedApiKey(),
            'isGeminiProvider' => $provider instanceof Gemini,
            'latestModelHints' => $modelHintsBuilder->buildLatestModelHints($models),
            'modelStatuses' => $modelStatuses,
            'models' => $models,
            'officialLatestAliases' => $modelHintsBuilder->buildOfficialLatestAliases(),
            'selectedModel' => $selectedModel,
            'workingModelHints' => $modelHintsBuilder->buildWorkingModelHints($models, $modelStatuses),
        ];
    }

    /**
     * @param array<int,string> $models
     * @param array<string,mixed> $modelStatuses
     * @return array<int,string>
     */
    private function includeSelectedModelIfAvailable(array $models, string $selectedModel, array $modelStatuses): array {
        if (
            $selectedModel !== ''
            && !in_array($selectedModel, $models, true)
            && ($this->supportsFileSearchModel)($selectedModel)
            && !$this->isPermanentlyUnavailableModel($selectedModel, $modelStatuses)
        ) {
            array_unshift($models, $selectedModel);
            $models = array_values(array_unique($models));
        }

        return $models;
    }

    /**
     * @param array<int,string> $models
     * @return array<int,string>
     */
    private function buildDropdownModels(array $models, string $selectedModel, AIProviderInterface $provider): array {
        return array_values(array_filter($models, function ($model) use ($provider, $selectedModel): bool {
            return $model === $selectedModel
                || !$provider instanceof Gemini
                || !$provider->isDeprecatedModel((string) $model);
        }));
    }

    private function hasValidSavedApiKey(): bool {
        $connectionStatus = get_option('geweb_aisearch_connection_status', []);
        return is_array($connectionStatus) && (($connectionStatus['status'] ?? '') === 'ok');
    }

    /**
     * @param array<string,mixed> $providerContext
     * @return array<string,mixed>
     */
    private function buildSettingsContext(AIProviderInterface $provider, array $providerContext): array {
        $customPrompt = UserScope::getGroupScopedOption('geweb_aisearch_custom_prompt', '');
        $customPromptName = UserScope::getGroupScopedOption('geweb_aisearch_custom_prompt_name', '');
        $defaultPrompt = $provider->getDefaultSystemInstruction();
        $promptHistory = PromptSupport::normalizePromptHistoryEntries(UserScope::getGroupScopedOption('geweb_aisearch_prompt_history', []));
        $frontendAiPageId = FrontendAiContext::getFrontendAiPageId();
        $modelPromptOverrides = $this->getArrayScopedOption('geweb_aisearch_model_prompts');
        $modelPromptOverrideNames = $this->getArrayScopedOption('geweb_aisearch_model_prompt_names');
        $modelPromptOverrideModes = $this->getArrayScopedOption('geweb_aisearch_model_prompt_modes');

        return [
            'allPostTypes' => get_post_types(['public' => true], 'objects'),
            'availableDateDisplayFormats' => DateDisplay::getAvailableFormats(),
            'availableProviders' => ProviderFactory::getAvailableProviders(),
            'conversationTrimCharLimit' => FrontendAiContext::getConversationTrimCharLimit(),
            'conversationTrimMessageLimit' => FrontendAiContext::getConversationTrimMessageLimit(),
            'currentPromptLabel' => $this->buildCurrentPromptLabel((string) $customPrompt, (string) $customPromptName),
            'customPrompt' => $customPrompt,
            'customPromptName' => $customPromptName,
            'dateDisplayFormat' => DateDisplay::getDateDisplayFormat(),
            'defaultPrompt' => $defaultPrompt,
            'frontendAiInterface' => FrontendAiContext::getFrontendAiInterface(),
            'frontendAiPageId' => $frontendAiPageId,
            'frontendAiPageTitle' => $frontendAiPageId > 0 ? get_the_title($frontendAiPageId) : '',
            'frontendAiPageUrl' => FrontendAiContext::getFrontendAiPageUrl(),
            'includeReferencedDocuments' => get_option('geweb_aisearch_include_referenced_documents', '0') === '1',
            'localConversationArchiveLimit' => FrontendAiContext::getLocalConversationArchiveLimit(),
            'modelPromptRows' => AdminPageSupport::buildModelPromptRows($providerContext['dropdownModels'], $providerContext['selectedModel'], $modelPromptOverrides, $modelPromptOverrideNames, $modelPromptOverrideModes, $provider, $defaultPrompt),
            'ocrAllUploadImages' => UserScope::getGroupScopedOption('geweb_aisearch_ocr_all_upload_images', '0') === '1',
            'postTypes' => get_option('geweb_aisearch_post_types', []),
            'preserveDataOnUninstall' => get_option('geweb_aisearch_preserve_data_on_uninstall', '0') === '1',
            'promptHistoryItems' => AdminPageSupport::buildPromptHistoryItems($promptHistory, $defaultPrompt, 'Default prompt'),
            'promptHistoryLimit' => (int) UserScope::getGroupScopedOption('geweb_aisearch_prompt_history_limit', 10),
            'selectedProvider' => ProviderFactory::getConfiguredProviderKey(),
            'storedContextCharLimit' => FrontendAiContext::getStoredContextCharLimit(),
            'storedContextMessageLimit' => FrontendAiContext::getStoredContextMessageLimit(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function getArrayScopedOption(string $optionName): array {
        $value = UserScope::getGroupScopedOption($optionName, []);
        return is_array($value) ? $value : [];
    }

    private function buildCurrentPromptLabel(string $customPrompt, string $customPromptName): string {
        if (trim($customPrompt) === '') {
            return 'Built-in default prompt';
        }

        return $customPromptName !== '' ? $customPromptName : 'Custom prompt';
    }

    private function getActiveTab(): string {
        $activeTab = isset($_GET['geweb_tab']) ? sanitize_key(wp_unslash($_GET['geweb_tab'])) : 'general';
        if ($activeTab === 'ai') {
            $activeTab = 'prompts';
        }

        return in_array($activeTab, ['general', 'prompts', 'documents', 'stores', 'conversations'], true)
            ? $activeTab
            : 'general';
    }

    /**
     * @return array<string,mixed>
     */
    private function buildCacheContext(AIProviderInterface $provider): array {
        $documentStore = new DocumentStore();
        $referencedCacheTime = $documentStore->getReferencedDocumentOverviewCacheTime();
        $providerStoreCacheTime = $provider instanceof Gemini ? $provider->getStoreOverviewCacheTime() : 0;

        return [
            'hasReferencedDocumentCache' => $documentStore->hasReferencedDocumentOverviewCache(),
            'providerHasStoreCache' => $provider instanceof Gemini ? $provider->hasStoreOverviewCache() : false,
            'providerStoreCacheLabel' => $providerStoreCacheTime > 0 ? DateDisplay::formatDateTime($providerStoreCacheTime) : '',
            'providerStoreCacheTime' => $providerStoreCacheTime,
            'providerStoreError' => $provider instanceof Gemini ? $provider->getStoreOverviewError() : '',
            'referencedCacheLabel' => $referencedCacheTime > 0 ? DateDisplay::formatDateTime($referencedCacheTime) : '',
            'referencedCacheTime' => $referencedCacheTime,
            'referencedDebug' => $documentStore->getReferencedDocumentOverviewDebug(),
            'storageEstimate' => (new GeminiStorageEstimator($documentStore, new MarkdownCacheStore()))->buildEstimate(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPluginUpdateGuardContext(): array {
        return [
            'pluginUpdateGuardActive' => PluginUpdateGuard::isActive(),
            'pluginUpdateGuardMessage' => PluginUpdateGuard::getNoticeMessage(),
        ];
    }

    /**
     * @param array<string,mixed> $cacheContext
     * @param array<string,mixed> $guardContext
     * @return array<string,mixed>
     */
    private function buildPanelContext(AIProviderInterface $provider, array $cacheContext, array $guardContext): array {
        $providerHasStoreCache = !empty($cacheContext['providerHasStoreCache']);
        $pluginUpdateGuardActive = !empty($guardContext['pluginUpdateGuardActive']);
        $documentsApiStatus = $this->buildDocumentsApiStatus();

        return [
            'conversationsHtml' => AdminPageSupport::captureHtml($this->renderConversationsTable),
            'documentsApiStatusColor' => $documentsApiStatus['color'],
            'documentsApiStatusLabel' => $documentsApiStatus['label'],
            'documentsApiStatusMessage' => $documentsApiStatus['message'],
            'geminiStoreDocumentsPanelHtml' => $this->buildGeminiStoreDocumentsPanelHtml($provider, $providerHasStoreCache, $pluginUpdateGuardActive),
            'geminiStoresHtml' => AdminPageSupport::renderPanelHtml(
                $providerHasStoreCache,
                $this->renderGeminiStoresTable,
                $pluginUpdateGuardActive ? 'Workspace AI Search is updating. Gemini Stores will be available again in a moment.' : 'Loading Gemini stores...'
            ),
            'referencedDocumentsHtml' => AdminPageSupport::renderPanelHtml(
                !empty($cacheContext['hasReferencedDocumentCache']),
                $this->renderReferencedDocumentsTable,
                $pluginUpdateGuardActive ? 'Workspace AI Search is updating. Files will be available again in a moment.' : 'Loading referenced documents...'
            ),
        ];
    }

    /**
     * @return array{color:string,label:string,message:string}
     */
    private function buildDocumentsApiStatus(): array {
        $connectionStatus = get_option('geweb_aisearch_connection_status', []);
        return AdminPageSupport::buildApiStatusDisplay($connectionStatus, '#46b450', '#646970', '#d63638');
    }

    private function buildGeminiStoreDocumentsPanelHtml(AIProviderInterface $provider, bool $providerHasStoreCache, bool $pluginUpdateGuardActive): string {
        if ($pluginUpdateGuardActive && !$providerHasStoreCache) {
            return '<div id="geweb-gemini-store-documents-panel" data-store-name="" style="margin-top:20px; padding:16px; background:#fff; border:1px solid #dcdcde;"><div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;"><div><strong id="geweb-gemini-store-documents-title">Uploaded Items</strong><div id="geweb-gemini-store-documents-subtitle" class="description" style="margin-top:4px;">Uploaded items in the selected Gemini File Search Store.</div></div><button type="button" class="button" id="geweb-refresh-gemini-store-documents" disabled>Refresh List</button></div><div id="geweb-gemini-store-documents-status" class="description" style="margin-bottom:12px; color:#646970;">Workspace AI Search is updating. Uploaded items will be available again in a moment.</div><p id="geweb-gemini-store-documents-error" class="description" style="margin:0 0 12px; color:#d63638; display:none;"></p><div id="geweb-gemini-store-documents-container"><p style="margin:0;">Workspace AI Search is updating. Uploaded items will be available again in a moment.</p></div></div>';
        }

        return AdminPageSupport::renderInitialGeminiStoreDocumentsPanel(
            $providerHasStoreCache,
            $provider,
            $this->getInitialGeminiStoreSelection,
            $this->renderGeminiStoreDocumentsPanel
        );
    }

    /**
     * @return array<string,string>
     */
    private function buildNavigationContext(): array {
        return [
            'generalTabUrl' => ($this->getTabUrl)('general'),
            'promptsTabUrl' => ($this->getTabUrl)('prompts'),
            'documentsTabUrl' => ($this->getTabUrl)('documents'),
            'storesTabUrl' => ($this->getTabUrl)('stores'),
            'conversationsTabUrl' => ($this->getTabUrl)('conversations'),
            'geminiChangelogUrl' => self::GEMINI_CHANGELOG_URL,
            'geminiDeprecationsUrl' => self::GEMINI_DEPRECATIONS_URL,
        ];
    }

    private function getConflictNotice(): string {
        return isset($_GET['geweb_conflict']) ? sanitize_text_field(wp_unslash($_GET['geweb_conflict'])) : '';
    }

    /**
     * @param array<string,mixed> $cacheContext
     * @param array<string,mixed> $guardContext
     */
    private function adminPanelsNeedPreload(string $activeTab, array $cacheContext, array $guardContext): bool {
        return empty($guardContext['pluginUpdateGuardActive'])
            && (($activeTab === 'documents' && empty($cacheContext['hasReferencedDocumentCache']))
            || ($activeTab === 'stores' && empty($cacheContext['providerHasStoreCache'])));
    }

    /**
     * @param array<string,mixed> $modelStatuses
     */
    private function isPermanentlyUnavailableModel(string $model, array $modelStatuses): bool {
        $entry = $modelStatuses[$model] ?? null;
        return is_array($entry) && !empty($entry['permanent_unavailable']);
    }
}
