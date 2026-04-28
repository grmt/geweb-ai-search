<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class AdminModelDiagnosticsSectionRenderer {
    private const STATUS_COLOR_ERROR = '#d63638';
    private const STATUS_COLOR_SUCCESS = '#46b450';
    private const STALE_FAILED_MODEL_TABLE_RETENTION_SECONDS = 90 * DAY_IN_SECONDS;

    /**
     * @param array<string,mixed> $args
     * @return void
     */
    public function render(array $args): void {
        $context = $this->buildContext($args);
        $matrixRows = $this->buildMatrixRows($context);

        $this->renderSection($context, $matrixRows);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private function buildContext(array $args): array {
        $modelStatuses = isset($args['modelStatuses']) && is_array($args['modelStatuses']) ? $args['modelStatuses'] : [];
        $officialLatestAliases = isset($args['officialLatestAliases']) && is_array($args['officialLatestAliases']) ? $args['officialLatestAliases'] : [];

        return [
            'modelStatuses' => $modelStatuses,
            'selectedModel' => isset($args['selectedModel']) ? trim((string) $args['selectedModel']) : '',
            'defaultModel' => isset($args['defaultModel']) ? trim((string) $args['defaultModel']) : '',
            'workingModelHints' => isset($args['workingModelHints']) && is_array($args['workingModelHints']) ? $args['workingModelHints'] : [],
            'latestModelHints' => isset($args['latestModelHints']) && is_array($args['latestModelHints']) ? $args['latestModelHints'] : [],
            'statusColorError' => isset($args['statusColorError']) ? (string) $args['statusColorError'] : self::STATUS_COLOR_ERROR,
            'statusColorSuccess' => isset($args['statusColorSuccess']) ? (string) $args['statusColorSuccess'] : self::STATUS_COLOR_SUCCESS,
            'aliasTargets' => $this->buildAliasTargets($modelStatuses, $officialLatestAliases),
        ];
    }

    /**
     * @param array<string,mixed> $modelStatuses
     * @param array<string,mixed> $officialLatestAliases
     * @return array<string,string>
     */
    private function buildAliasTargets(array $modelStatuses, array $officialLatestAliases): array {
        return [
            'gemini-flash-latest' => !empty($modelStatuses['gemini-flash-latest']['resolved_model'])
                ? (string) $modelStatuses['gemini-flash-latest']['resolved_model']
                : (string) ($officialLatestAliases['flash_latest'] ?? ''),
            'gemini-pro-latest' => !empty($modelStatuses['gemini-pro-latest']['resolved_model'])
                ? (string) $modelStatuses['gemini-pro-latest']['resolved_model']
                : (string) ($officialLatestAliases['pro_latest'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,string>>
     */
    private function buildMatrixRows(array $context): array {
        $modelStatuses = is_array($context['modelStatuses'] ?? null) ? $context['modelStatuses'] : [];
        $aliasTargets = is_array($context['aliasTargets'] ?? null) ? $context['aliasTargets'] : [];
        $matrixRows = $this->mergeCandidateRows($this->buildCandidateRows($context), $modelStatuses);
        $this->applyLatestAliasLabels($matrixRows, $aliasTargets);
        $matrixRows = $this->filterStaleRows($matrixRows, $modelStatuses);
        $this->sortMatrixRows($matrixRows, $modelStatuses);

        return $matrixRows;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,string>>
     */
    private function buildCandidateRows(array $context): array {
        $aliasTargets = is_array($context['aliasTargets'] ?? null) ? $context['aliasTargets'] : [];
        $latestModelHints = is_array($context['latestModelHints'] ?? null) ? $context['latestModelHints'] : [];
        $candidateRows = [
            $this->buildCandidateRow('gemini-flash-latest', 'Flash', (string) ($aliasTargets['gemini-flash-latest'] ?? ''), 'gemini-flash-latest'),
            $this->buildCandidateRow('gemini-pro-latest', 'Pro', (string) ($aliasTargets['gemini-pro-latest'] ?? ''), 'gemini-pro-latest'),
            $this->buildCandidateRow('', 'Flash', (string) ($latestModelHints['stable_flash'] ?? ''), (string) ($latestModelHints['stable_flash'] ?? ''), 'Stable'),
            $this->buildCandidateRow('', 'Pro', (string) ($latestModelHints['stable_pro'] ?? ''), (string) ($latestModelHints['stable_pro'] ?? ''), 'Stable'),
        ];

        $this->appendWorkingModelRows($candidateRows, is_array($context['workingModelHints'] ?? null) ? $context['workingModelHints'] : []);
        $this->appendTestedModelRows($candidateRows, is_array($context['modelStatuses'] ?? null) ? $context['modelStatuses'] : [], $aliasTargets);

        return $candidateRows;
    }

    /**
     * @return array<string,string>
     */
    private function buildCandidateRow(string $latest, string $family, string $version, string $testModel, string $track = ''): array {
        return [
            'latest' => $latest,
            'family' => $family,
            'track' => $track !== '' ? $track : $this->getModelReleaseTrack($version),
            'version' => $version,
            'test_model' => $testModel,
        ];
    }

    /**
     * @param array<int,array<string,string>> $candidateRows
     * @param array<string,mixed> $workingModelHints
     * @return void
     */
    private function appendWorkingModelRows(array &$candidateRows, array $workingModelHints): void {
        foreach (['flash' => 'Flash', 'pro' => 'Pro'] as $hintKey => $family) {
            $workingModel = trim((string) ($workingModelHints[$hintKey] ?? ''));
            if ($workingModel !== '') {
                $candidateRows[] = $this->buildCandidateRow('', $family, $workingModel, $workingModel);
            }
        }
    }

    /**
     * @param array<int,array<string,string>> $candidateRows
     * @param array<string,mixed> $modelStatuses
     * @param array<string,string> $aliasTargets
     * @return void
     */
    private function appendTestedModelRows(array &$candidateRows, array $modelStatuses, array $aliasTargets): void {
        foreach ($modelStatuses as $testedModel => $status) {
            $testedModel = trim((string) $testedModel);
            if (!$this->shouldIncludeTestedModel($testedModel, $status)) {
                continue;
            }

            $version = $this->resolveTestedModelVersion($testedModel, is_array($status) ? $status : [], $aliasTargets);
            $candidateRows[] = $this->buildCandidateRow('', $this->getModelFamilyLabel($version), $version, $testedModel);
        }
    }

    /**
     * @param mixed $status
     */
    private function shouldIncludeTestedModel(string $testedModel, $status): bool {
        return $testedModel !== ''
            && is_array($status)
            && trim((string) ($status['status'] ?? '')) !== ''
            && !$this->isStaleFailedModelStatus($status);
    }

    /**
     * @param array<string,mixed> $status
     * @param array<string,string> $aliasTargets
     */
    private function resolveTestedModelVersion(string $testedModel, array $status, array $aliasTargets): string {
        $version = $testedModel;
        $aliasTargetVersion = trim((string) ($aliasTargets[$testedModel] ?? ''));
        $resolvedModel = trim((string) ($status['resolved_model'] ?? ''));
        if ($aliasTargetVersion !== '') {
            $version = $aliasTargetVersion;
        }
        if ($resolvedModel !== '') {
            $version = $resolvedModel;
        }

        return $version;
    }

    /**
     * @param array<int,array<string,string>> $candidateRows
     * @param array<string,mixed> $modelStatuses
     * @return array<string,array<string,string>>
     */
    private function mergeCandidateRows(array $candidateRows, array $modelStatuses): array {
        $matrixRows = [];
        foreach ($candidateRows as $row) {
            $version = trim((string) ($row['version'] ?? ''));
            if ($version === '') {
                continue;
            }

            $matrixRows[$version] = isset($matrixRows[$version])
                ? $this->mergeMatrixRow($matrixRows[$version], $row, $modelStatuses)
                : $this->normalizeMatrixRow($row, $version);
        }

        return $matrixRows;
    }

    /**
     * @param array<string,string> $row
     * @return array<string,string>
     */
    private function normalizeMatrixRow(array $row, string $version): array {
        return [
            'latest' => trim((string) ($row['latest'] ?? '')),
            'family' => trim((string) ($row['family'] ?? '')),
            'track' => trim((string) ($row['track'] ?? '')),
            'version' => $version,
            'test_model' => trim((string) ($row['test_model'] ?? '')),
        ];
    }

    /**
     * @param array<string,string> $existingRow
     * @param array<string,string> $candidateRow
     * @param array<string,mixed> $modelStatuses
     * @return array<string,string>
     */
    private function mergeMatrixRow(array $existingRow, array $candidateRow, array $modelStatuses): array {
        foreach (['latest', 'family', 'track'] as $key) {
            $candidateValue = trim((string) ($candidateRow[$key] ?? ''));
            if (($existingRow[$key] ?? '') === '' && $candidateValue !== '') {
                $existingRow[$key] = $candidateValue;
            }
        }

        $existingTestModel = trim((string) ($existingRow['test_model'] ?? ''));
        $candidateTestModel = trim((string) ($candidateRow['test_model'] ?? ''));
        if ($candidateTestModel !== '' && ($existingTestModel === '' || $this->shouldPreferModelTestEntry($modelStatuses, $candidateTestModel, $existingTestModel))) {
            $existingRow['test_model'] = $candidateTestModel;
        }

        return $existingRow;
    }

    /**
     * @param array<string,array<string,string>> $matrixRows
     * @param array<string,string> $aliasTargets
     * @return void
     */
    private function applyLatestAliasLabels(array &$matrixRows, array $aliasTargets): void {
        foreach ($matrixRows as $version => &$row) {
            $derivedLatestLabel = $this->buildLatestAliasLabelForVersion($version, $aliasTargets);
            if ($derivedLatestLabel !== '') {
                $existingLatestLabel = trim((string) ($row['latest'] ?? ''));
                $row['latest'] = $existingLatestLabel === ''
                    ? $derivedLatestLabel
                    : $this->mergeLatestAliasLabels($existingLatestLabel, $derivedLatestLabel);
            }
        }
        unset($row);
    }

    /**
     * @param array<string,array<string,string>> $matrixRows
     * @param array<string,mixed> $modelStatuses
     * @return array<int,array<string,string>>
     */
    private function filterStaleRows(array $matrixRows, array $modelStatuses): array {
        return array_values(array_filter($matrixRows, function (array $row) use ($modelStatuses): bool {
            $testModel = trim((string) ($row['test_model'] ?? ''));
            $entry = $testModel === '' ? null : $this->getModelStatusEntry($modelStatuses, $testModel);

            return $testModel === '' || !$this->isStaleFailedModelStatus($entry);
        }));
    }

    /**
     * @param array<int,array<string,string>> $matrixRows
     * @param array<string,mixed> $modelStatuses
     * @return void
     */
    private function sortMatrixRows(array &$matrixRows, array $modelStatuses): void {
        usort($matrixRows, function (array $left, array $right) use ($modelStatuses): int {
            return $this->compareMatrixRows($left, $right, $modelStatuses);
        });
    }

    /**
     * @param array<string,string> $left
     * @param array<string,string> $right
     * @param array<string,mixed> $modelStatuses
     */
    private function compareMatrixRows(array $left, array $right, array $modelStatuses): int {
        $comparison = $this->compareLatestLabels((string) ($left['latest'] ?? ''), (string) ($right['latest'] ?? ''));
        if ($comparison === 0) {
            $comparison = strnatcasecmp(strtolower(trim((string) ($left['version'] ?? ''))), strtolower(trim((string) ($right['version'] ?? ''))));
        }
        if ($comparison === 0) {
            $comparison = $this->compareLatestTestTimestamp($left, $right, $modelStatuses);
        }

        return $comparison;
    }

    private function compareLatestLabels(string $leftLatest, string $rightLatest): int {
        $leftLatest = strtolower(trim($leftLatest));
        $rightLatest = strtolower(trim($rightLatest));
        $comparison = strnatcasecmp($leftLatest, $rightLatest);
        if ($leftLatest === '' && $rightLatest !== '') {
            $comparison = 1;
        } elseif ($leftLatest !== '' && $rightLatest === '') {
            $comparison = -1;
        }

        return $comparison;
    }

    /**
     * @param array<string,string> $left
     * @param array<string,string> $right
     * @param array<string,mixed> $modelStatuses
     */
    private function compareLatestTestTimestamp(array $left, array $right, array $modelStatuses): int {
        $leftEntry = $this->getModelStatusEntry($modelStatuses, trim((string) ($left['test_model'] ?? '')));
        $rightEntry = $this->getModelStatusEntry($modelStatuses, trim((string) ($right['test_model'] ?? '')));
        $leftTimestamp = is_array($leftEntry) ? (int) ($leftEntry['timestamp'] ?? 0) : 0;
        $rightTimestamp = is_array($rightEntry) ? (int) ($rightEntry['timestamp'] ?? 0) : 0;

        return $rightTimestamp <=> $leftTimestamp;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<int,array<string,string>> $matrixRows
     * @return void
     */
    private function renderSection(array $context, array $matrixRows): void {
        ?>
        <div id="geweb-model-diagnostics">
            <?php $this->renderCurrentModelDescription($context); ?>
            <table class="widefat striped geweb-model-diagnostics-table" style="max-width:900px; margin-top:8px;">
                <?php $this->renderTableHeader(); ?>
                <tbody>
                    <?php foreach ($matrixRows as $row): ?>
                        <?php $this->renderTableRow($row, $context); ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">The list above is fetched from Gemini when possible and falls back to the bundled defaults if the API is unavailable.</p>
        </div>
        <?php
    }

    /**
     * @param array<string,mixed> $context
     */
    private function renderCurrentModelDescription(array $context): void {
        $modelStatuses = is_array($context['modelStatuses'] ?? null) ? $context['modelStatuses'] : [];
        $selectedModel = (string) ($context['selectedModel'] ?? '');
        $defaultModel = (string) ($context['defaultModel'] ?? '');
        ?>
        <p class="description">
            Current model:
            <code><?php echo esc_html($selectedModel); ?></code>
            <?php $this->renderRecentModelStatus($this->getModelStatusEntry($modelStatuses, $selectedModel)); ?>
            <br>
            Default model:
            <code><?php echo esc_html($defaultModel); ?></code>
            <?php $this->renderRecentModelStatus($this->getModelStatusEntry($modelStatuses, $defaultModel)); ?>
        </p>
        <?php
    }

    /**
     * @param array<string,mixed>|null $entry
     */
    private function renderRecentModelStatus(?array $entry): void {
        $status = is_array($entry) ? (string) ($entry['status'] ?? '') : '';
        $message = is_array($entry) ? (string) ($entry['message'] ?? '') : '';
        if ($status === 'failed') {
            echo '<span style="color:#b32d2e; cursor:help; border-bottom:1px dotted currentColor;" title="' . esc_attr($message) . '">failed recently</span>';
        } elseif ($status === 'timeout') {
            echo '<span style="color:#dba617; cursor:help; border-bottom:1px dotted currentColor;" title="' . esc_attr($message) . '">timed out recently</span>';
        }
    }

    private function renderTableHeader(): void {
        ?>
        <thead>
            <tr>
                <?php foreach ($this->getTableColumns() as $column): ?>
                    <th scope="col" aria-sort="none" style="text-align:center;">
                        <button type="button" class="button-link geweb-sortable-column" data-sort-column="<?php echo esc_attr($column['key']); ?>" data-sort-type="<?php echo esc_attr($column['type']); ?>" style="display:inline-flex;align-items:center;justify-content:center;gap:4px;font-weight:600;">
                            <span><?php echo esc_html($column['label']); ?></span>
                            <span aria-hidden="true">↕</span>
                        </button>
                    </th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <?php
    }

    /**
     * @return array<int,array{key:string,type:string,label:string}>
     */
    private function getTableColumns(): array {
        return [
            ['key' => 'version', 'type' => 'text', 'label' => 'Version'],
            ['key' => 'latest', 'type' => 'text', 'label' => 'Latest'],
            ['key' => 'family', 'type' => 'text', 'label' => 'Flash/Pro'],
            ['key' => 'track', 'type' => 'text', 'label' => 'Preview/Stable'],
            ['key' => 'test', 'type' => 'date', 'label' => 'Latest test'],
        ];
    }

    /**
     * @param array<string,string> $row
     * @param array<string,mixed> $context
     */
    private function renderTableRow(array $row, array $context): void {
        $displayRow = $this->buildDisplayRow($row, $context);
        if ($displayRow['version'] === '') {
            return;
        }
        ?>
        <tr
            data-sort-version="<?php echo esc_attr(strtolower($displayRow['version'])); ?>"
            data-sort-latest="<?php echo esc_attr(strtolower($displayRow['latest'])); ?>"
            data-sort-family="<?php echo esc_attr(strtolower($displayRow['family'])); ?>"
            data-sort-track="<?php echo esc_attr(strtolower($displayRow['track'])); ?>"
            data-sort-test="<?php echo esc_attr($displayRow['sort_timestamp']); ?>"
            data-sort-test-status="<?php echo esc_attr(strtolower($displayRow['test_status'])); ?>"
        >
            <td><?php $this->renderVersionCell($displayRow['version']); ?></td>
            <td style="text-align:center;"><?php echo $displayRow['latest'] !== '' ? '<code style="white-space:nowrap;">' . esc_html($displayRow['latest']) . '</code>' : '&nbsp;'; ?></td>
            <td style="text-align:center;"><?php echo esc_html($displayRow['family']); ?></td>
            <td style="text-align:center;"><?php echo esc_html($displayRow['track']); ?></td>
            <td style="text-align:center;"><?php $this->renderTestCell($displayRow); ?></td>
        </tr>
        <?php
    }

    /**
     * @param array<string,string> $row
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    private function buildDisplayRow(array $row, array $context): array {
        $modelStatuses = is_array($context['modelStatuses'] ?? null) ? $context['modelStatuses'] : [];
        $testModel = trim((string) ($row['test_model'] ?? ''));
        $entry = $testModel !== '' ? $this->getModelStatusEntry($modelStatuses, $testModel) : null;
        $testStatus = is_array($entry) ? (string) ($entry['status'] ?? '') : '';
        $testTimestamp = is_array($entry) && !empty($entry['timestamp']) ? DateDisplay::formatDateTime((int) $entry['timestamp']) : '';

        return [
            'version' => trim((string) ($row['version'] ?? '')),
            'latest' => trim((string) ($row['latest'] ?? '')),
            'family' => trim((string) ($row['family'] ?? '')),
            'track' => trim((string) ($row['track'] ?? '')),
            'test_model' => $testModel,
            'test_status' => $testStatus,
            'test_timestamp' => $testTimestamp,
            'test_prompt' => is_array($entry) ? trim((string) ($entry['test_prompt'] ?? '')) : '',
            'test_response' => is_array($entry) ? trim((string) ($entry['test_response'] ?? '')) : '',
            'test_message' => is_array($entry) ? (string) ($entry['message'] ?? '') : '',
            'test_color' => in_array($testStatus, ['failed', 'timeout'], true) ? (string) ($context['statusColorError'] ?? self::STATUS_COLOR_ERROR) : (string) ($context['statusColorSuccess'] ?? self::STATUS_COLOR_SUCCESS),
            'sort_timestamp' => is_array($entry) && !empty($entry['timestamp']) ? (string) ((int) $entry['timestamp']) : '0',
        ];
    }

    private function renderVersionCell(string $version): void {
        echo '<code style="white-space:nowrap;">' . esc_html($version) . '</code>';
        $provider = ProviderFactory::make();
        if ($provider instanceof Gemini && $provider->isDeprecatedModel($version)) {
            echo '<br><span style="color:#dba617; font-size:10px; font-weight:600; text-transform:uppercase;">Deprecated</span>';
        }
    }

    /**
     * @param array<string,string> $displayRow
     */
    private function renderTestCell(array $displayRow): void {
        if ($displayRow['test_status'] === '') {
            echo '&nbsp;';
            return;
        }

        ?>
        <button
            type="button"
            class="button-link geweb-model-test-details-trigger"
            data-model="<?php echo esc_attr($displayRow['test_model']); ?>"
            data-status="<?php echo esc_attr($displayRow['test_status']); ?>"
            data-timestamp="<?php echo esc_attr($displayRow['test_timestamp']); ?>"
            data-prompt="<?php echo esc_attr($displayRow['test_prompt']); ?>"
            data-response="<?php echo esc_attr($displayRow['test_response']); ?>"
            data-message="<?php echo esc_attr($displayRow['test_message']); ?>"
            title="<?php echo esc_attr($this->buildTestTitle($displayRow)); ?>"
            style="padding:0; min-height:auto; color:<?php echo esc_attr($displayRow['test_color']); ?>; font-weight:600; cursor:help; border-bottom:1px dotted currentColor;"
        >
            <small><?php echo esc_html($this->buildTestLabel($displayRow['test_status'], $displayRow['test_timestamp'])); ?></small>
        </button>
        <?php
    }

    /**
     * @param array<string,string> $displayRow
     */
    private function buildTestTitle(array $displayRow): string {
        $tooltipParts = [];
        if ($displayRow['test_prompt'] !== '') {
            $tooltipParts[] = 'Q: "' . $displayRow['test_prompt'] . '"';
        }
        if ($displayRow['test_response'] !== '') {
            $tooltipParts[] = 'A: "' . $displayRow['test_response'] . '"';
        }
        if ($displayRow['test_message'] !== '') {
            $tooltipParts[] = $displayRow['test_message'];
        }

        $title = implode("\n", $tooltipParts);
        if ($title === '') {
            $title = $displayRow['test_message'] !== '' ? $displayRow['test_message'] : 'Open latest test details';
        }

        return $title;
    }

    private function buildTestLabel(string $testStatus, string $testTimestamp): string {
        $label = ucfirst($testStatus);
        if (strtoupper($testStatus) === 'OK') {
            $label = 'OK';
        }
        if ($testTimestamp !== '') {
            $label = $testTimestamp;
        }

        return $label;
    }

    private function getModelReleaseTrack(string $model): string {
        $normalizedModel = strtolower(trim($model));

        return $normalizedModel !== '' && str_contains($normalizedModel, 'preview') ? 'Preview' : 'Stable';
    }

    private function getModelFamilyLabel(string $model): string {
        $normalizedModel = strtolower(trim($model));
        $label = '';
        if (str_contains($normalizedModel, '-pro')) {
            $label = 'Pro';
        } elseif (str_contains($normalizedModel, '-flash')) {
            $label = 'Flash';
        }

        return $label;
    }

    /**
     * @param array<string,mixed> $modelStatuses
     * @return array<string,mixed>|null
     */
    private function getModelStatusEntry(array $modelStatuses, string $model): ?array {
        $entry = $modelStatuses[$model] ?? null;
        return is_array($entry) ? $entry : null;
    }

    /**
     * @param array<string,mixed>|null $entry
     */
    private function isStaleFailedModelStatus(?array $entry): bool {
        $isStaleFailed = false;
        if (is_array($entry) && trim((string) ($entry['status'] ?? '')) === 'failed') {
            $timestamp = isset($entry['timestamp']) ? (int) $entry['timestamp'] : 0;
            $isStaleFailed = !empty($entry['permanent_unavailable'])
                || ($timestamp > 0 && (current_time('timestamp') - $timestamp) >= self::STALE_FAILED_MODEL_TABLE_RETENTION_SECONDS);
        }

        return $isStaleFailed;
    }

    /**
     * @param array<string,mixed> $modelStatuses
     */
    private function shouldPreferModelTestEntry(array $modelStatuses, string $candidateModel, string $existingModel): bool {
        $candidateEntry = $this->getModelStatusEntry($modelStatuses, $candidateModel);
        $existingEntry = $this->getModelStatusEntry($modelStatuses, $existingModel);
        $candidateRank = $this->getModelPreferenceRank($candidateEntry);
        $existingRank = $this->getModelPreferenceRank($existingEntry);
        $preferCandidate = $candidateRank > $existingRank;
        if ($candidateRank === $existingRank) {
            $candidateTimestamp = is_array($candidateEntry) ? (int) ($candidateEntry['timestamp'] ?? 0) : 0;
            $existingTimestamp = is_array($existingEntry) ? (int) ($existingEntry['timestamp'] ?? 0) : 0;
            $preferCandidate = $candidateTimestamp > $existingTimestamp;
        }

        return $preferCandidate;
    }

    /**
     * @param array<string,mixed>|null $entry
     */
    private function getModelPreferenceRank(?array $entry): int {
        $rank = $this->isStaleFailedModelStatus($entry) ? -2 : 0;
        if (is_array($entry)) {
            $status = trim((string) ($entry['status'] ?? ''));
            if ($status === 'ok') {
                $rank = 2;
            } elseif ($status !== '' && $status !== 'failed' && $status !== 'timeout') {
                $rank = 1;
            } elseif ($status === 'failed') {
                $rank = -1;
            }
        }

        return $rank;
    }

    /**
     * @param array<string,string> $aliasTargets
     */
    private function buildLatestAliasLabelForVersion(string $version, array $aliasTargets): string {
        $labels = [];
        foreach ($aliasTargets as $alias => $targetVersion) {
            if (trim($version) !== '' && trim($version) === trim((string) $targetVersion)) {
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
}
