<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Simple File List discovery and metadata helpers.
 */
class SimpleFileListSupport {
    use SimpleFileListSupportHelpersTrait;

    private const SQL_SHOW_TABLES_LIKE = 'SHOW TABLES LIKE %s';

    /**
     * Determine whether a post is rendering a Simple File List shortcode.
     */
    public function isSimpleFileListPage(\WP_Post $post): bool {
        if (!shortcode_exists('eeSFL')) {
            return false;
        }

        return has_shortcode((string) $post->post_content, 'eeSFL');
    }

    /**
     * Expand Simple File List references to include additional files in the same list folder.
     *
     * @param array<int,array<string,string>> $references
     * @return array<int,array<string,string>>
     */
    public function expandSimpleFileListReferences(array $references): array {
        $expanded = [];
        $directories = [];

        foreach ($references as $reference) {
            if (is_array($reference)) {
                $this->collectReferenceForExpansion($reference, $expanded, $directories);
            }
        }

        $niceNameMap = $this->getSimpleFileListNiceNameMap(array_keys($directories));

        foreach ($expanded as $filePath => $reference) {
            if (isset($niceNameMap[$filePath]) && $niceNameMap[$filePath] !== '') {
                $expanded[$filePath]['display_name'] = $niceNameMap[$filePath];
            }
        }

        foreach ($directories as $directory => $data) {
            foreach ($this->getSupportedFilesInDirectory($directory) as $filePath) {
                $this->expandDirectoryFile($filePath, $data, $niceNameMap, $expanded);
            }
        }

        return array_values($expanded);
    }

    /**
     * @param array<string,string> $niceNameMap
     * @param array<string,array<string,string>> $expanded
     * @param array<string,mixed> $data
     */
    private function expandDirectoryFile(string $filePath, array $data, array $niceNameMap, array &$expanded): void {
        $baseName = basename($filePath);
        $dirUrl = isset($data['dir_url']) ? (string) $data['dir_url'] : '';
        $fileUrl = $dirUrl !== '' ? $dirUrl . '/' . rawurlencode($baseName) : '';
        if ($fileUrl === '') {
            return;
        }
        $niceName = $niceNameMap[$filePath] ?? $this->prettifyFileName($baseName);
        if (isset($expanded[$filePath])) {
            if ($niceName !== '') {
                $expanded[$filePath]['display_name'] = $niceName;
            }
            return;
        }
        $expanded[$filePath] = ['file_path' => $filePath, 'file_url' => $fileUrl, 'display_name' => $niceName];
    }

    /**
     * @param array<string,string> $reference
     * @param array<string,array<string,string>> $expanded
     * @param array<string,array<string,string>> $directories
     */
    private function collectReferenceForExpansion(array $reference, array &$expanded, array &$directories): void {
        $filePath = isset($reference['file_path']) ? (string) $reference['file_path'] : '';
        $fileUrl = isset($reference['file_url']) ? (string) $reference['file_url'] : '';
        if ($filePath === '' || $fileUrl === '' || !is_readable($filePath)) {
            return;
        }
        $expanded[$filePath] = $reference;
        $directory = wp_normalize_path((string) dirname($filePath));
        if ($directory !== '') {
            $directories[$directory] = ['dir_url' => untrailingslashit((string) dirname($fileUrl))];
        }
    }

    /**
     * Merge Simple File List database entries so SFL-only files appear even without website references.
     *
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     */
    public function mergeSimpleFileListOptionEntries(array &$overview, array $uploadedByHash, callable $buildOverviewEntry): void {
        foreach ($this->getSimpleFileListEntries() as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $this->mergeEntryIntoOverview($entry, $overview, $uploadedByHash, $buildOverviewEntry);
        }
    }

    /**
     * @param array<string,string> $entry
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     */
    private function mergeEntryIntoOverview(array $entry, array &$overview, array $uploadedByHash, callable $buildOverviewEntry): void {
        $filePath = isset($entry['file_path']) ? (string) $entry['file_path'] : '';
        $fileUrl = isset($entry['file_url']) ? (string) $entry['file_url'] : '';
        if ($filePath === '' || $fileUrl === '' || !is_readable($filePath)) {
            return;
        }
        $hash = hash_file('sha256', $filePath);
        if (!$hash) {
            return;
        }
        $displayName = isset($entry['display_name']) ? trim((string) $entry['display_name']) : basename($filePath);
        if (isset($overview[$hash])) {
            $overview[$hash]['managed_by_simple_file_list'] = true;
            if ($displayName !== '' && (empty($overview[$hash]['nice_name']) || $overview[$hash]['nice_name'] === basename($filePath))) {
                $overview[$hash]['nice_name'] = $displayName;
            }
            return;
        }
        $overview[$hash] = $buildOverviewEntry($hash, $filePath, $fileUrl, $displayName, $uploadedByHash[$hash] ?? null, true);
    }

    /**
     * Read Simple File List file entries directly from eeSFL_FileList_* options.
     *
     * @return array<int,array<string,string>>
     */
    public function getSimpleFileListEntries(): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL\\_FileList\\_%'",
            ARRAY_A
        );
        if (!is_array($rows)) {
            error_log('geweb-ai-search: SFL entry lookup found no eeSFL_FileList_* option rows.');
            return [];
        }

        $entries = [];
        foreach ($rows as $row) {
            $this->processSimpleFileListEntryRow($row, $entries);
        }

        error_log('geweb-ai-search: SFL entry lookup resolved ' . count($entries) . ' file(s).');

        return array_values($entries);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,string>> $entries
     */
    private function processSimpleFileListEntryRow(array $row, array &$entries): void {
        if (!is_array($row) || !isset($row['option_value'])) {
            return;
        }
        $optionName = isset($row['option_name']) ? (string) $row['option_name'] : 'unknown-option';
        $value = maybe_unserialize($row['option_value']);
        if (!is_array($value)) {
            error_log('geweb-ai-search: SFL option ' . $optionName . ' did not unserialize to an array.');
            return;
        }
        foreach ($value as $record) {
            if (!is_array($record)) {
                continue;
            }
            $entry = $this->buildEntryFromRecord($record);
            if ($entry !== null) {
                $entries[$entry['file_path']] = $entry;
            }
        }
    }

    public function buildSimpleFileListAdminUrl(string $filePath): string {
        $searchTerm = basename(wp_normalize_path($filePath));
        $baseUrl = admin_url('admin.php?page=ee-simple-file-list');

        if ($searchTerm === '') {
            return $baseUrl;
        }

        return add_query_arg([
            's' => $searchTerm,
            'eeFileSearch' => $searchTerm,
        ], $baseUrl);
    }

    public function removeSimpleFileListEntryByPath(string $filePath): bool {
        global $wpdb;

        $normalizedPath = wp_normalize_path($filePath);
        $targets = $normalizedPath !== '' && is_readable($normalizedPath)
            ? $this->buildSimpleFileListTargets([$normalizedPath])
            : [];
        if (empty($targets)) {
            return false;
        }

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL\\_FileList\\_%'",
            ARRAY_A
        );
        if (!is_array($rows) || empty($rows)) {
            return false;
        }

        $removedAny = false;
        foreach ($rows as $row) {
            $this->processRemoveRow($row, $normalizedPath, $targets, $removedAny);
        }

        return $removedAny;
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,string>
     */
    public function getSimpleFileListNiceNameMap(array $directories): array {
        global $wpdb;

        $targets = $this->buildDirectoryFileTargets($directories);
        if (empty($targets)) {
            return [];
        }

        $niceNames = $this->getSimpleFileListNiceNamesFromFileListOptions($targets);

        $optionRows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL%' OR option_name LIKE '%simple_file_list%'",
            ARRAY_A
        );
        if (is_array($optionRows)) {
            foreach ($optionRows as $row) {
                if (!is_array($row) || !isset($row['option_value'])) {
                    continue;
                }
                $this->scanSimpleFileListMetadata(maybe_unserialize($row['option_value']), $targets, $niceNames);
            }
        }

        foreach ($this->getSflCandidateTableNames() as $tableName) {
            $rows = $wpdb->get_results('SELECT * FROM ' . $tableName, ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $this->scanSimpleFileListMetadata($row, $targets, $niceNames);
                }
            }
        }

        return $niceNames;
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,int>
     */
    public function getSimpleFileListModifiedMap(array $directories): array {
        global $wpdb;

        $targets = $this->buildDirectoryFileTargets($directories);
        if (empty($targets)) {
            return [];
        }

        $modifiedMap = [];

        $optionRows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL%' OR option_name LIKE '%simple_file_list%'",
            ARRAY_A
        );
        if (is_array($optionRows)) {
            foreach ($optionRows as $row) {
                if (!is_array($row) || !isset($row['option_value'])) {
                    continue;
                }
                $this->scanSimpleFileListModifiedMetadata(maybe_unserialize($row['option_value']), $targets, $modifiedMap);
            }
        }

        foreach ($this->getSflCandidateTableNames() as $tableName) {
            $rows = $wpdb->get_results('SELECT * FROM ' . $tableName, ARRAY_A);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $this->scanSimpleFileListModifiedMetadata($row, $targets, $modifiedMap);
                }
            }
        }

        return $modifiedMap;
    }

    /**
     * @param array<string,array<string,string>> $targets
     * @return array<string,string>
     */
    public function getSimpleFileListNiceNamesFromFileListOptions(array $targets): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'eeSFL\\_FileList\\_%'",
            ARRAY_A
        );
        if (!is_array($rows)) {
            error_log('geweb-ai-search: SFL nice-name lookup found no eeSFL_FileList_* option rows.');
            return [];
        }

        error_log('geweb-ai-search: SFL nice-name lookup scanning ' . count($rows) . ' eeSFL_FileList_* option row(s) for ' . count($targets) . ' target file(s).');
        $niceNames = [];
        foreach ($rows as $row) {
            $this->processNiceNameFileListRow($row, $targets, $niceNames);
        }

        error_log('geweb-ai-search: SFL nice-name lookup resolved ' . count($niceNames) . ' file(s).');
        return $niceNames;
    }

    /**
     * @param mixed $value
     * @param array<string,array<string,string>> $targets
     * @param array<string,string> $niceNames
     */
    public function scanSimpleFileListMetadata($value, array $targets, array &$niceNames): void {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return;
        }

        $match = $this->extractSimpleFileListRecordMatch($value, $targets);
        if ($match !== null) {
            $filePath = $match['file_path'];
            $niceName = $match['nice_name'];
            if ($filePath !== '' && $niceName !== '' && !isset($niceNames[$filePath])) {
                $niceNames[$filePath] = $niceName;
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested) || is_object($nested)) {
                $this->scanSimpleFileListMetadata($nested, $targets, $niceNames);
            }
        }
    }

    /**
     * @param mixed $value
     * @param array<string,array<string,string>> $targets
     * @param array<string,int> $modifiedMap
     */
    public function scanSimpleFileListModifiedMetadata($value, array $targets, array &$modifiedMap): void {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return;
        }

        $match = $this->extractSimpleFileListRecordModifiedMatch($value, $targets);
        if ($match !== null) {
            $filePath = $match['file_path'];
            $modifiedAt = (int) $match['modified_at'];
            if ($filePath !== '' && $modifiedAt > 0 && (!isset($modifiedMap[$filePath]) || $modifiedAt > $modifiedMap[$filePath])) {
                $modifiedMap[$filePath] = $modifiedAt;
            }
        }

        foreach ($value as $nested) {
            if (is_array($nested) || is_object($nested)) {
                $this->scanSimpleFileListModifiedMetadata($nested, $targets, $modifiedMap);
            }
        }
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,array<string,string>> $targets
     * @return array{file_path:string,modified_at:int}|null
     */
    public function extractSimpleFileListRecordModifiedMatch(array $record, array $targets): ?array {
        $stringFields = $this->buildStringFields($record);
        if (empty($stringFields)) {
            return null;
        }

        foreach ($targets as $filePath => $target) {
            if (!$this->isTargetMatchedByFields($stringFields, $target['basename'], $target['relative_path'])) {
                continue;
            }
            $modifiedAt = $this->findModifiedTimestampFromFields($stringFields);
            if ($modifiedAt !== null) {
                return ['file_path' => $filePath, 'modified_at' => $modifiedAt];
            }
        }

        return null;
    }
}
