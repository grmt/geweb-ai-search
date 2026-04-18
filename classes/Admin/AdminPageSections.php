<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminPageSections {
    private const STATUS_COLOR_ERROR = '#d63638';
    private const DEFAULT_CONVERSATION_LIMIT = 50;
    private const STALE_FAILED_MODEL_TABLE_RETENTION_SECONDS = 90 * DAY_IN_SECONDS;

    private ConversationManager $conversationManager;

    public function __construct(ConversationManager $conversationManager) {
        $this->conversationManager = $conversationManager;
    }

    public function renderReferencedDocumentsTable(): void {
        $table = new ReferencedDocumentListTable();
        $table->prepare_items();
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="geweb-referenced-documents-table-form">
            <input type="hidden" name="page" value="geweb-ai-search">
            <input type="hidden" name="geweb_tab" value="documents">
            <?php $table->search_box(__('Search', 'geweb-ai-search'), 'geweb-referenced-documents'); ?>
            <?php $table->display(); ?>
        </form>
        <?php
    }

    public function renderGeminiStoresTable(): void {
        $table = new GeminiStoreListTable();
        $table->prepare_items();
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="geweb-gemini-stores-table-form">
            <input type="hidden" name="page" value="geweb-ai-search">
            <input type="hidden" name="geweb_tab" value="stores">
            <?php $table->display(); ?>
        </form>
        <?php
    }

    public function renderConversationsTable(): void {
        $conversations = $this->conversationManager->getConversationLog();
        $frontendAiPageUrl = FrontendAiContext::getFrontendAiPageUrl();
        $latestConversation = isset($conversations[0]) && is_array($conversations[0]) ? $conversations[0] : [];
        $latestConversationId = isset($latestConversation['id']) ? (string) $latestConversation['id'] : '';

        echo '<p class="description" style="margin:0 0 12px;">';
        echo esc_html(sprintf(_n('%d saved chat.', '%d saved chats.', count($conversations), 'geweb-ai-search'), count($conversations)));
        echo '</p>';
        echo '<p class="description" style="margin:0 0 12px;">';
        echo esc_html(sprintf(__('The %d most recently used chats are kept automatically; the oldest unused ones are pruned first.', 'geweb-ai-search'), self::DEFAULT_CONVERSATION_LIMIT));
        echo '</p>';

        if (empty($conversations)) {
            echo '<div class="notice notice-info inline" style="margin:0 0 12px;"><p>';
            echo esc_html__('No saved chats yet. A chat is added here after a successful AI response.', 'geweb-ai-search');
            echo '</p></div>';
            return;
        }

        if ($frontendAiPageUrl !== '' && $latestConversationId !== '') {
            $latestConversationUrl = FrontendAiContext::getFrontendAiConversationUrl($latestConversationId);
            echo '<p style="margin:0 0 12px;">';
            echo '<a class="button button-primary" href="' . esc_url($latestConversationUrl) . '">Open Latest Chat</a>';
            echo '</p>';
        }

        $table = new ConversationListTable($conversations, $frontendAiPageUrl);
        $table->prepare_items();
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="geweb-conversations-table-form">';
        echo '<input type="hidden" name="page" value="geweb-ai-search">';
        echo '<input type="hidden" name="geweb_tab" value="conversations">';
        $table->search_box(__('Search chats', 'geweb-ai-search'), 'geweb-conversations');
        $table->display();
        echo '</form>';
    }

    /**
     * @param array<string,mixed> $args
     * @return void
     */
    public function renderModelDiagnosticsSection(array $args): void {
        $models = isset($args['models']) && is_array($args['models']) ? $args['models'] : [];
        $modelStatuses = isset($args['modelStatuses']) && is_array($args['modelStatuses']) ? $args['modelStatuses'] : [];
        $selectedModel = isset($args['selectedModel']) ? trim((string) $args['selectedModel']) : '';
        $defaultModel = isset($args['defaultModel']) ? trim((string) $args['defaultModel']) : '';
        $officialLatestAliases = isset($args['officialLatestAliases']) && is_array($args['officialLatestAliases']) ? $args['officialLatestAliases'] : [];
        $workingModelHints = isset($args['workingModelHints']) && is_array($args['workingModelHints']) ? $args['workingModelHints'] : [];
        $latestModelHints = isset($args['latestModelHints']) && is_array($args['latestModelHints']) ? $args['latestModelHints'] : [];
        $statusColorError = isset($args['statusColorError']) ? (string) $args['statusColorError'] : self::STATUS_COLOR_ERROR;
        $statusColorSuccess = isset($args['statusColorSuccess']) ? (string) $args['statusColorSuccess'] : '#46b450';
        $selectedModelStatus = is_array($modelStatuses[$selectedModel] ?? null) ? (string) ($modelStatuses[$selectedModel]['status'] ?? '') : '';
        $defaultModelStatus = is_array($modelStatuses[$defaultModel] ?? null) ? (string) ($modelStatuses[$defaultModel]['status'] ?? '') : '';
        $aliasTargets = [
            'gemini-flash-latest' => !empty($modelStatuses['gemini-flash-latest']['resolved_model'])
                ? (string) $modelStatuses['gemini-flash-latest']['resolved_model']
                : (string) ($officialLatestAliases['flash_latest'] ?? ''),
            'gemini-pro-latest' => !empty($modelStatuses['gemini-pro-latest']['resolved_model'])
                ? (string) $modelStatuses['gemini-pro-latest']['resolved_model']
                : (string) ($officialLatestAliases['pro_latest'] ?? ''),
        ];
        $candidateRows = [
            [
                'latest' => 'gemini-flash-latest',
                'family' => 'Flash',
                'track' => $this->getModelReleaseTrack($aliasTargets['gemini-flash-latest']),
                'version' => $aliasTargets['gemini-flash-latest'],
                'test_model' => 'gemini-flash-latest',
            ],
            [
                'latest' => 'gemini-pro-latest',
                'family' => 'Pro',
                'track' => $this->getModelReleaseTrack($aliasTargets['gemini-pro-latest']),
                'version' => $aliasTargets['gemini-pro-latest'],
                'test_model' => 'gemini-pro-latest',
            ],
            [
                'latest' => '',
                'family' => 'Flash',
                'track' => 'Stable',
                'version' => (string) ($latestModelHints['stable_flash'] ?? ''),
                'test_model' => (string) ($latestModelHints['stable_flash'] ?? ''),
            ],
            [
                'latest' => '',
                'family' => 'Pro',
                'track' => 'Stable',
                'version' => (string) ($latestModelHints['stable_pro'] ?? ''),
                'test_model' => (string) ($latestModelHints['stable_pro'] ?? ''),
            ],
        ];

        $workingFlash = trim((string) ($workingModelHints['flash'] ?? ''));
        if ($workingFlash !== '') {
            $candidateRows[] = [
                'latest' => '',
                'family' => 'Flash',
                'track' => $this->getModelReleaseTrack($workingFlash),
                'version' => $workingFlash,
                'test_model' => $workingFlash,
            ];
        }

        $workingPro = trim((string) ($workingModelHints['pro'] ?? ''));
        if ($workingPro !== '') {
            $candidateRows[] = [
                'latest' => '',
                'family' => 'Pro',
                'track' => $this->getModelReleaseTrack($workingPro),
                'version' => $workingPro,
                'test_model' => $workingPro,
            ];
        }

        foreach ($modelStatuses as $testedModel => $status) {
            if (!is_array($status) || trim((string) ($status['status'] ?? '')) === '') {
                continue;
            }

            $testedModel = trim((string) $testedModel);
            if ($testedModel === '') {
                continue;
            }

            if ($this->isStaleFailedModelStatus($status)) {
                continue;
            }

            $resolvedModel = trim((string) ($status['resolved_model'] ?? ''));
            $aliasTargetVersion = trim((string) ($aliasTargets[$testedModel] ?? ''));
            $version = $resolvedModel !== ''
                ? $resolvedModel
                : ($aliasTargetVersion !== '' ? $aliasTargetVersion : $testedModel);
            $candidateRows[] = [
                'latest' => '',
                'family' => $this->getModelFamilyLabel($version),
                'track' => $this->getModelReleaseTrack($version),
                'version' => $version,
                'test_model' => $testedModel,
            ];
        }

        $matrixRows = [];
        foreach ($candidateRows as $row) {
            $version = trim((string) ($row['version'] ?? ''));
            if ($version === '') {
                continue;
            }

            if (!isset($matrixRows[$version])) {
                $matrixRows[$version] = [
                    'latest' => trim((string) ($row['latest'] ?? '')),
                    'family' => trim((string) ($row['family'] ?? '')),
                    'track' => trim((string) ($row['track'] ?? '')),
                    'version' => $version,
                    'test_model' => trim((string) ($row['test_model'] ?? '')),
                ];
                continue;
            }

            if ($matrixRows[$version]['latest'] === '' && trim((string) ($row['latest'] ?? '')) !== '') {
                $matrixRows[$version]['latest'] = trim((string) $row['latest']);
            }

            if ($matrixRows[$version]['family'] === '' && trim((string) ($row['family'] ?? '')) !== '') {
                $matrixRows[$version]['family'] = trim((string) $row['family']);
            }

            if ($matrixRows[$version]['track'] === '' && trim((string) ($row['track'] ?? '')) !== '') {
                $matrixRows[$version]['track'] = trim((string) $row['track']);
            }

            $existingTestModel = trim((string) $matrixRows[$version]['test_model']);
            $candidateTestModel = trim((string) ($row['test_model'] ?? ''));
            if ($existingTestModel === '' && $candidateTestModel !== '') {
                $matrixRows[$version]['test_model'] = $candidateTestModel;
            } elseif ($candidateTestModel !== '' && $this->shouldPreferModelTestEntry($modelStatuses, $candidateTestModel, $existingTestModel)) {
                $matrixRows[$version]['test_model'] = $candidateTestModel;
            }
        }

        foreach ($matrixRows as $version => &$row) {
            $derivedLatestLabel = $this->buildLatestAliasLabelForVersion($version, $aliasTargets);
            if ($derivedLatestLabel === '') {
                continue;
            }

            $existingLatestLabel = trim((string) ($row['latest'] ?? ''));
            if ($existingLatestLabel === '') {
                $row['latest'] = $derivedLatestLabel;
                continue;
            }

            if ($existingLatestLabel !== $derivedLatestLabel) {
                $row['latest'] = $this->mergeLatestAliasLabels($existingLatestLabel, $derivedLatestLabel);
            }
        }
        unset($row);

        $matrixRows = array_values(array_filter($matrixRows, function (array $row) use ($modelStatuses): bool {
            $testModel = trim((string) ($row['test_model'] ?? ''));
            if ($testModel === '') {
                return true;
            }

            $entry = $this->getModelStatusEntry($modelStatuses, $testModel);
            return !$this->isStaleFailedModelStatus($entry);
        }));

        usort($matrixRows, function (array $left, array $right) use ($modelStatuses): int {
            $leftLatest = strtolower(trim((string) ($left['latest'] ?? '')));
            $rightLatest = strtolower(trim((string) ($right['latest'] ?? '')));
            if ($leftLatest === '' && $rightLatest !== '') {
                return 1;
            }

            if ($leftLatest !== '' && $rightLatest === '') {
                return -1;
            }

            $latestComparison = strnatcasecmp($leftLatest, $rightLatest);
            if ($latestComparison !== 0) {
                return $latestComparison;
            }

            $leftVersion = strtolower(trim((string) ($left['version'] ?? '')));
            $rightVersion = strtolower(trim((string) ($right['version'] ?? '')));
            $versionComparison = strnatcasecmp($leftVersion, $rightVersion);
            if ($versionComparison !== 0) {
                return $versionComparison;
            }

            $leftTestModel = trim((string) ($left['test_model'] ?? ''));
            $rightTestModel = trim((string) ($right['test_model'] ?? ''));
            $leftEntry = $this->getModelStatusEntry($modelStatuses, $leftTestModel);
            $rightEntry = $this->getModelStatusEntry($modelStatuses, $rightTestModel);
            $leftTimestamp = is_array($leftEntry) ? (int) ($leftEntry['timestamp'] ?? 0) : 0;
            $rightTimestamp = is_array($rightEntry) ? (int) ($rightEntry['timestamp'] ?? 0) : 0;

            return $rightTimestamp <=> $leftTimestamp;
        });
        ?>
        <div id="geweb-model-diagnostics">
            <p class="description">
                Current model:
                <code><?php echo esc_html($selectedModel); ?></code>
                <?php if ($selectedModelStatus === 'failed'): ?>
                    <span style="color:#b32d2e;">failed recently</span>
                <?php endif; ?>
                <br>
                Default model:
                <code><?php echo esc_html($defaultModel); ?></code>
                <?php if ($defaultModelStatus === 'failed'): ?>
                    <span style="color:#b32d2e;">failed recently</span>
                <?php endif; ?>
            </p>
            <table class="widefat striped geweb-model-diagnostics-table" style="max-width:900px; margin-top:8px;">
                <thead>
                    <tr>
                        <th scope="col" aria-sort="none" style="text-align:center;">
                            <button type="button" class="button-link geweb-sortable-column" data-sort-column="version" data-sort-type="text" style="display:inline-flex;align-items:center;justify-content:center;gap:4px;font-weight:600;">
                                <span>Version</span>
                                <span aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th scope="col" aria-sort="none" style="text-align:center;">
                            <button type="button" class="button-link geweb-sortable-column" data-sort-column="latest" data-sort-type="text" style="display:inline-flex;align-items:center;justify-content:center;gap:4px;font-weight:600;">
                                <span>Latest</span>
                                <span aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th scope="col" aria-sort="none" style="text-align:center;">
                            <button type="button" class="button-link geweb-sortable-column" data-sort-column="family" data-sort-type="text" style="display:inline-flex;align-items:center;justify-content:center;gap:4px;font-weight:600;">
                                <span>Flash/Pro</span>
                                <span aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th scope="col" aria-sort="none" style="text-align:center;">
                            <button type="button" class="button-link geweb-sortable-column" data-sort-column="track" data-sort-type="text" style="display:inline-flex;align-items:center;justify-content:center;gap:4px;font-weight:600;">
                                <span>Preview/Stable</span>
                                <span aria-hidden="true">↕</span>
                            </button>
                        </th>
                        <th scope="col" aria-sort="none" style="text-align:center;">
                            <button type="button" class="button-link geweb-sortable-column" data-sort-column="test" data-sort-type="date" style="display:inline-flex;align-items:center;justify-content:center;gap:4px;font-weight:600;">
                                <span>Latest test</span>
                                <span aria-hidden="true">↕</span>
                            </button>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matrixRows as $row): ?>
                        <?php if (trim((string) $row['version']) === '') { continue; } ?>
                        <?php
                        $versionLabel = trim((string) ($row['version'] ?? ''));
                        $latestLabel = trim((string) ($row['latest'] ?? ''));
                        $familyLabel = trim((string) ($row['family'] ?? ''));
                        $trackLabel = trim((string) ($row['track'] ?? ''));
                        $testModel = trim((string) ($row['test_model'] ?? ''));
                        $entry = $testModel !== '' && is_array($modelStatuses[$testModel] ?? null) ? $modelStatuses[$testModel] : null;
                        $testStatus = is_array($entry) ? (string) ($entry['status'] ?? '') : '';
                        $testTimestamp = is_array($entry) && !empty($entry['timestamp'])
                            ? DateDisplay::formatDateTime((int) $entry['timestamp'])
                            : '';
                        $testMessage = is_array($entry) ? (string) ($entry['message'] ?? '') : '';
                        $testPrompt = is_array($entry) ? trim((string) ($entry['test_prompt'] ?? '')) : '';
                        $testResponse = is_array($entry) ? trim((string) ($entry['test_response'] ?? '')) : '';
                        $hasTestDetails = $testPrompt !== '' || $testResponse !== '';
                        $testColor = $testStatus === 'failed' ? $statusColorError : $statusColorSuccess;
                        $tooltipParts = [];
                        if ($testPrompt !== '') {
                            $tooltipParts[] = 'Q: "' . $testPrompt . '"';
                        }
                        if ($testResponse !== '') {
                            $tooltipParts[] = 'A: "' . $testResponse . '"';
                        }
                        if ($testMessage !== '') {
                            $tooltipParts[] = $testMessage;
                        }
                        $testTooltip = implode("\n", $tooltipParts);
                        ?>
                        <tr
                            data-sort-version="<?php echo esc_attr(strtolower($versionLabel)); ?>"
                            data-sort-latest="<?php echo esc_attr(strtolower($latestLabel)); ?>"
                            data-sort-family="<?php echo esc_attr(strtolower($familyLabel)); ?>"
                            data-sort-track="<?php echo esc_attr(strtolower($trackLabel)); ?>"
                            data-sort-test="<?php echo esc_attr(is_array($entry) && !empty($entry['timestamp']) ? (string) ((int) $entry['timestamp']) : '0'); ?>"
                            data-sort-test-status="<?php echo esc_attr(strtolower($testStatus)); ?>"
                        >
                            <td><code style="white-space:nowrap;"><?php echo esc_html($versionLabel); ?></code></td>
                            <td style="text-align:center;">
                                <?php echo $latestLabel !== '' ? '<code style="white-space:nowrap;">' . esc_html($latestLabel) . '</code>' : '&nbsp;'; ?>
                            </td>
                            <td style="text-align:center;"><?php echo esc_html($familyLabel); ?></td>
                            <td style="text-align:center;"><?php echo esc_html($trackLabel); ?></td>
                            <td style="text-align:center;">
                                <?php if ($testStatus !== ''): ?>
                                    <button
                                        type="button"
                                        class="button-link geweb-model-test-details-trigger"
                                        data-model="<?php echo esc_attr($testModel); ?>"
                                        data-status="<?php echo esc_attr($testStatus); ?>"
                                        data-timestamp="<?php echo esc_attr($testTimestamp); ?>"
                                        data-prompt="<?php echo esc_attr($testPrompt); ?>"
                                        data-response="<?php echo esc_attr($testResponse); ?>"
                                        title="<?php echo esc_attr($testTooltip !== '' ? $testTooltip : ($testMessage !== '' ? $testMessage : 'Open latest test details')); ?>"
                                        style="padding:0; min-height:auto; color:<?php echo esc_attr($testColor); ?>; font-weight:600;"
                                    >
                                        <small><?php echo esc_html($testTimestamp !== '' ? $testTimestamp : (strtoupper($testStatus) === 'OK' ? 'OK' : ucfirst($testStatus))); ?></small>
                                    </button>
                                <?php elseif ($testStatus === ''): ?>
                                    &nbsp;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">The list above is fetched from Gemini when possible and falls back to the bundled defaults if the API is unavailable.</p>
        </div>
        <?php
    }

    private function getModelReleaseTrack(string $model): string {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') {
            return '';
        }

        return str_contains($normalizedModel, 'preview') ? 'Preview' : 'Stable';
    }

    private function getModelFamilyLabel(string $model): string {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') {
            return '';
        }

        if (str_contains($normalizedModel, '-pro')) {
            return 'Pro';
        }

        if (str_contains($normalizedModel, '-flash')) {
            return 'Flash';
        }

        return '';
    }

    /**
     * @param array<string,mixed> $modelStatuses
     * @param string $model
     * @return array<string,mixed>|null
     */
    private function getModelStatusEntry(array $modelStatuses, string $model): ?array {
        $entry = $modelStatuses[$model] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /**
     * @param array<string,mixed>|null $entry
     * @return bool
     */
    private function isStaleFailedModelStatus(?array $entry): bool {
        if (!is_array($entry) || trim((string) ($entry['status'] ?? '')) !== 'failed') {
            return false;
        }

        $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
        if ($timestamp <= 0) {
            return false;
        }

        return (current_time('timestamp') - $timestamp) >= self::STALE_FAILED_MODEL_TABLE_RETENTION_SECONDS;
    }

    /**
     * @param array<string,mixed> $modelStatuses
     * @param string $candidateModel
     * @param string $existingModel
     * @return bool
     */
    private function shouldPreferModelTestEntry(array $modelStatuses, string $candidateModel, string $existingModel): bool {
        $candidateEntry = $this->getModelStatusEntry($modelStatuses, $candidateModel);
        $existingEntry = $this->getModelStatusEntry($modelStatuses, $existingModel);

        $candidateIsStaleFailed = $this->isStaleFailedModelStatus($candidateEntry);
        $existingIsStaleFailed = $this->isStaleFailedModelStatus($existingEntry);
        if ($candidateIsStaleFailed !== $existingIsStaleFailed) {
            return !$candidateIsStaleFailed;
        }

        $candidateStatusRank = $this->getModelStatusRank($candidateEntry);
        $existingStatusRank = $this->getModelStatusRank($existingEntry);
        if ($candidateStatusRank !== $existingStatusRank) {
            return $candidateStatusRank > $existingStatusRank;
        }

        $candidateTimestamp = is_array($candidateEntry) ? (int) ($candidateEntry['timestamp'] ?? 0) : 0;
        $existingTimestamp = is_array($existingEntry) ? (int) ($existingEntry['timestamp'] ?? 0) : 0;

        return $candidateTimestamp > $existingTimestamp;
    }

    /**
     * @param array<string,mixed>|null $entry
     * @return int
     */
    private function getModelStatusRank(?array $entry): int {
        if (!is_array($entry)) {
            return 0;
        }

        $status = trim((string) ($entry['status'] ?? ''));
        if ($status === 'ok') {
            return 2;
        }

        if ($status !== '' && $status !== 'failed') {
            return 1;
        }

        return $status === 'failed' ? -1 : 0;
    }

    /**
     * @param array<string,string> $aliasTargets
     * @return string
     */
    private function buildLatestAliasLabelForVersion(string $version, array $aliasTargets): string {
        $version = trim($version);
        if ($version === '') {
            return '';
        }

        $labels = [];
        foreach ($aliasTargets as $alias => $targetVersion) {
            if ($version === trim((string) $targetVersion)) {
                $labels[] = $alias;
            }
        }

        return implode(', ', $labels);
    }

    private function mergeLatestAliasLabels(string $left, string $right): string {
        $labels = array_filter(array_map('trim', array_merge(explode(',', $left), explode(',', $right))), static function (string $label): bool {
            return $label !== '';
        });

        return implode(', ', array_values(array_unique($labels)));
    }

    /**
     * @param string $storeName
     * @param string $storeLabel
     * @param array<int,array<string,mixed>> $documents
     * @return void
     */
    public function renderGeminiStoreDocumentsPanel(string $storeName, string $storeLabel, array $documents): void {
        ?>
        <div id="geweb-gemini-store-documents-panel" data-store-name="<?php echo esc_attr($storeName); ?>" style="margin-top:20px; padding:16px; background:#fff; border:1px solid #dcdcde;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px;">
                <div>
                    <strong id="geweb-gemini-store-documents-title"><?php echo esc_html($storeLabel !== '' ? $storeLabel : $storeName); ?></strong>
                    <div id="geweb-gemini-store-documents-subtitle" class="description" style="margin-top:4px;">
                        Uploaded items in the selected Gemini File Search Store.
                    </div>
                </div>
                <button type="button" class="button" id="geweb-refresh-gemini-store-documents" <?php disabled($storeName === ''); ?>>Refresh List</button>
            </div>
            <div id="geweb-gemini-store-documents-status" class="description" style="margin-bottom:12px; color:#646970;">
                <?php echo $storeName === '' ? 'Select a store to view uploaded items.' : 'Showing uploaded items for the selected store.'; ?>
            </div>
            <p id="geweb-gemini-store-documents-error" class="description" style="margin:0 0 12px; color:<?php echo esc_attr(self::STATUS_COLOR_ERROR); ?>; display:none;"></p>
            <div id="geweb-gemini-store-documents-container">
                <?php
                if ($storeName === '') {
                    echo '<p style="margin:0;">Select a store to view uploaded items.</p>';
                } else {
                    echo GeminiStoreListTable::renderDocumentList($documents); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{name:string,label:string,documents:array<int,array<string,mixed>>}
     */
    public function getInitialGeminiStoreSelection(array $items): array {
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['is_active'])) {
                continue;
            }

            return [
                'name' => (string) ($item['name'] ?? ''),
                'label' => (string) (($item['display_name'] ?? '') !== '' ? $item['display_name'] : ($item['name'] ?? '')),
                'documents' => isset($item['documents']) && is_array($item['documents']) ? $item['documents'] : [],
            ];
        }

        $first = isset($items[0]) && is_array($items[0]) ? $items[0] : [];

        return [
            'name' => (string) ($first['name'] ?? ''),
            'label' => (string) (($first['display_name'] ?? '') !== '' ? $first['display_name'] : ($first['name'] ?? '')),
            'documents' => isset($first['documents']) && is_array($first['documents']) ? $first['documents'] : [],
        ];
    }

    public function supportsFileSearchModel(string $model): bool {
        $normalizedModel = strtolower(trim($model));
        if ($normalizedModel === '') {
            return false;
        }

        if (in_array($normalizedModel, ['gemini-flash-latest', 'gemini-pro-latest'], true)) {
            return true;
        }

        foreach ([
            'tts',
            'speech',
            'audio',
            'embedding',
            'image-generation',
            'vision-preview-generation',
            'image',
            'video',
            'live',
            'robotics',
            'deep-research',
            'computer-use',
        ] as $fragment) {
            if (strpos($normalizedModel, $fragment) !== false) {
                return false;
            }
        }

        return preg_match('/^gemini-[0-9][a-z0-9.\-]*-(pro|flash|flash-lite)(?:-|$)/', $normalizedModel) === 1;
    }
}
