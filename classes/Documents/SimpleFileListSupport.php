<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Simple File List discovery and metadata helpers.
 */
class SimpleFileListSupport {
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
            if (!is_array($reference)) {
                continue;
            }

            $filePath = isset($reference['file_path']) ? (string) $reference['file_path'] : '';
            $fileUrl = isset($reference['file_url']) ? (string) $reference['file_url'] : '';
            if ($filePath === '' || $fileUrl === '' || !is_readable($filePath)) {
                continue;
            }

            $expanded[$filePath] = $reference;
            $directory = wp_normalize_path((string) dirname($filePath));
            if ($directory !== '') {
                $directories[$directory] = [
                    'dir_url' => untrailingslashit((string) dirname($fileUrl)),
                ];
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
                $baseName = basename($filePath);
                $dirUrl = isset($data['dir_url']) ? (string) $data['dir_url'] : '';
                $fileUrl = $dirUrl !== '' ? $dirUrl . '/' . rawurlencode($baseName) : '';
                if ($fileUrl === '') {
                    continue;
                }

                $niceName = $niceNameMap[$filePath] ?? $this->prettifyFileName($baseName);
                if (isset($expanded[$filePath])) {
                    if ($niceName !== '') {
                        $expanded[$filePath]['display_name'] = $niceName;
                    }
                    continue;
                }

                $expanded[$filePath] = [
                    'file_path' => $filePath,
                    'file_url' => $fileUrl,
                    'display_name' => $niceName,
                ];
            }
        }

        return array_values($expanded);
    }

    /**
     * Merge Simple File List database entries so SFL-only files appear even without website references.
     *
     * @param array<string,array<string,mixed>> $overview
     * @param array<string,array<string,mixed>> $uploadedByHash
     */
    public function mergeSimpleFileListOptionEntries(array &$overview, array $uploadedByHash, bool $uploadsEnabled, string $connectionState, callable $buildOverviewEntry): void {
        foreach ($this->getSimpleFileListEntries() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $filePath = isset($entry['file_path']) ? (string) $entry['file_path'] : '';
            $fileUrl = isset($entry['file_url']) ? (string) $entry['file_url'] : '';
            if ($filePath === '' || $fileUrl === '' || !is_readable($filePath)) {
                continue;
            }

            $hash = hash_file('sha256', $filePath);
            if (!$hash) {
                continue;
            }

            $displayName = isset($entry['display_name']) ? trim((string) $entry['display_name']) : basename($filePath);
            if (isset($overview[$hash])) {
                $overview[$hash]['managed_by_simple_file_list'] = true;
                if ($displayName !== '' && (empty($overview[$hash]['nice_name']) || $overview[$hash]['nice_name'] === basename($filePath))) {
                    $overview[$hash]['nice_name'] = $displayName;
                }
                continue;
            }

            $uploaded = $uploadedByHash[$hash] ?? null;
            $overview[$hash] = $buildOverviewEntry(
                $hash,
                $filePath,
                $fileUrl,
                $displayName,
                $uploaded,
                $uploadsEnabled,
                $connectionState,
                true
            );
        }
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
            if (!is_array($row) || !isset($row['option_value'])) {
                continue;
            }

            $optionName = isset($row['option_name']) ? (string) $row['option_name'] : 'unknown-option';
            $value = maybe_unserialize($row['option_value']);
            if (!is_array($value)) {
                error_log('geweb-ai-search: SFL option ' . $optionName . ' did not unserialize to an array.');
                continue;
            }

            foreach ($value as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
                if ($filePathValue === '') {
                    continue;
                }

                $resolved = $this->resolveSimpleFileListRecordPath($filePathValue);
                if ($resolved === null) {
                    continue;
                }

                $entries[$resolved['file_path']] = [
                    'file_path' => $resolved['file_path'],
                    'file_url' => $resolved['file_url'],
                    'display_name' => isset($record['FileNiceName']) && trim((string) $record['FileNiceName']) !== ''
                        ? trim((string) $record['FileNiceName'])
                        : $this->prettifyFileName(basename($resolved['file_path'])),
                ];
            }
        }

        error_log('geweb-ai-search: SFL entry lookup resolved ' . count($entries) . ' file(s).');

        return array_values($entries);
    }

    /**
     * @return array<int,string>
     */
    public function getSupportedFilesInDirectory(string $directory): array {
        if ($directory === '' || !is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        $files = [];
        $entries = @scandir($directory);
        if (!is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $filePath = wp_normalize_path(trailingslashit($directory) . $entry);
            if (!is_file($filePath) || !is_readable($filePath)) {
                continue;
            }

            $fileType = wp_check_filetype($entry);
            $extension = isset($fileType['ext']) ? (string) $fileType['ext'] : '';
            $typeGroup = $extension !== '' ? wp_ext2type($extension) : false;
            if (in_array($typeGroup, ['image', 'audio', 'video'], true)) {
                continue;
            }

            $files[] = $filePath;
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /**
     * @param array<string,mixed> $reference
     */
    public function resolveOverviewDisplayName(array $reference, string $filePath): string {
        $displayName = isset($reference['display_name']) ? trim((string) $reference['display_name']) : '';
        if ($displayName !== '') {
            return $displayName;
        }

        return $this->prettifyFileName(basename($filePath));
    }

    public function prettifyFileName(string $fileName): string {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        if ($name === '') {
            return $fileName;
        }

        $name = str_replace(['_', '-'], ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        $name = trim((string) $name);

        return $name !== '' ? $name : $fileName;
    }

    /**
     * @param array<int,string> $directories
     * @return array<string,string>
     */
    public function getSimpleFileListNiceNameMap(array $directories): array {
        global $wpdb;

        $targets = [];
        $uploads = wp_get_upload_dir();
        $baseDir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));

        foreach ($directories as $directory) {
            foreach ($this->getSupportedFilesInDirectory($directory) as $filePath) {
                $normalizedPath = wp_normalize_path($filePath);
                $targets[$normalizedPath] = [
                    'basename' => basename($normalizedPath),
                    'relative_path' => $baseDir !== '' && strpos($normalizedPath, $baseDir) === 0
                        ? ltrim(substr($normalizedPath, strlen($baseDir)), '/')
                        : basename($normalizedPath),
                ];
            }
        }

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

                $value = maybe_unserialize($row['option_value']);
                $this->scanSimpleFileListMetadata($value, $targets, $niceNames);
            }
        }

        $candidateTables = array_unique(array_filter(array_merge(
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'eeSFL') . '%')),
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'simple_file_list') . '%')),
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'eesfl') . '%'))
        )));

        foreach ($candidateTables as $tableName) {
            $tableName = (string) $tableName;
            if ($tableName === '') {
                continue;
            }

            $rows = $wpdb->get_results('SELECT * FROM ' . $tableName, ARRAY_A);
            if (!is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                $this->scanSimpleFileListMetadata($row, $targets, $niceNames);
            }
        }

        return $niceNames;
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
            if (!is_array($row) || !isset($row['option_value'])) {
                continue;
            }

            $optionName = isset($row['option_name']) ? (string) $row['option_name'] : 'unknown-option';
            $value = maybe_unserialize($row['option_value']);
            if (!is_array($value)) {
                error_log('geweb-ai-search: SFL option ' . $optionName . ' did not unserialize to an array.');
                continue;
            }

            foreach ($value as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
                $niceName = isset($record['FileNiceName']) ? trim((string) $record['FileNiceName']) : '';
                if ($filePathValue === '' || $niceName === '') {
                    continue;
                }

                $matchedPath = $this->matchSimpleFileListRecordPath($filePathValue, $targets);
                if ($matchedPath === null || isset($niceNames[$matchedPath])) {
                    continue;
                }

                $niceNames[$matchedPath] = $niceName;
                error_log('geweb-ai-search: SFL nice-name match from ' . $optionName . ' for ' . basename($matchedPath) . ' => ' . $niceName);
            }
        }

        error_log('geweb-ai-search: SFL nice-name lookup resolved ' . count($niceNames) . ' file(s).');
        return $niceNames;
    }

    /**
     * @return array<string,string>|null
     */
    public function resolveSimpleFileListRecordPath(string $filePathValue): ?array {
        $uploads = wp_get_upload_dir();
        $baseDir = wp_normalize_path((string) ($uploads['basedir'] ?? ''));
        $baseUrl = (string) ($uploads['baseurl'] ?? '');
        if ($baseDir === '' || $baseUrl === '') {
            return null;
        }

        $normalizedValue = wp_normalize_path(ltrim(trim($filePathValue), '/'));
        $candidates = [];

        if ($normalizedValue !== '') {
            $candidates[] = wp_normalize_path(trailingslashit($baseDir) . $normalizedValue);
        }

        $baseName = basename($normalizedValue);
        if ($baseName !== '') {
            $searched = $this->findFileInUploadsByBasename($baseName, $baseDir);
            if ($searched !== null) {
                $candidates[] = $searched;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === '' || !is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            if (strpos($candidate, $baseDir) !== 0) {
                continue;
            }

            $relativePath = ltrim(substr($candidate, strlen($baseDir)), '/');
            return [
                'file_path' => $candidate,
                'file_url' => trailingslashit($baseUrl) . str_replace('%2F', '/', rawurlencode($relativePath)),
            ];
        }

        return null;
    }

    public function findFileInUploadsByBasename(string $baseName, string $baseDir): ?string {
        static $indexedFiles = null;

        if ($indexedFiles === null) {
            $indexedFiles = [];
            if (is_dir($baseDir) && is_readable($baseDir)) {
                try {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
                    );

                    foreach ($iterator as $fileInfo) {
                        if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                            continue;
                        }

                        $indexedFiles[$fileInfo->getBasename()][] = wp_normalize_path($fileInfo->getPathname());
                    }
                } catch (\Throwable $e) {
                    return null;
                }
            }
        }

        if (empty($indexedFiles[$baseName])) {
            return null;
        }

        return (string) $indexedFiles[$baseName][0];
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
     * @param array<string,mixed> $record
     * @param array<string,array<string,string>> $targets
     * @return array<string,string>|null
     */
    public function extractSimpleFileListRecordMatch(array $record, array $targets): ?array {
        $stringFields = [];
        foreach ($record as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                continue;
            }

            $stringFields[(string) $key] = $stringValue;
        }

        if (empty($stringFields)) {
            return null;
        }

        foreach ($targets as $filePath => $target) {
            $basename = $target['basename'];
            $relativePath = $target['relative_path'];
            $matched = false;

            foreach ($stringFields as $fieldValue) {
                $normalizedField = wp_normalize_path($fieldValue);
                if (
                    $fieldValue === $basename ||
                    $normalizedField === wp_normalize_path($relativePath) ||
                    str_ends_with($normalizedField, '/' . $basename) ||
                    strpos($normalizedField, $basename) !== false
                ) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            foreach ($stringFields as $fieldKey => $fieldValue) {
                if (!preg_match('/nice|display|title|label/i', $fieldKey)) {
                    continue;
                }

                $niceName = trim($fieldValue);
                if ($this->isUsableSimpleFileListNiceName($niceName, $basename)) {
                    return [
                        'file_path' => $filePath,
                        'nice_name' => $niceName,
                    ];
                }
            }

            foreach ($stringFields as $fieldKey => $fieldValue) {
                if (!preg_match('/name/i', $fieldKey) || preg_match('/file|path|url|ext|type/i', $fieldKey)) {
                    continue;
                }

                $niceName = trim($fieldValue);
                if ($this->isUsableSimpleFileListNiceName($niceName, $basename)) {
                    return [
                        'file_path' => $filePath,
                        'nice_name' => $niceName,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param array<string,array<string,string>> $targets
     */
    public function matchSimpleFileListRecordPath(string $filePathValue, array $targets): ?string {
        $normalizedValue = wp_normalize_path(ltrim(trim($filePathValue), '/'));

        foreach ($targets as $filePath => $target) {
            $basename = wp_normalize_path((string) ($target['basename'] ?? basename($filePath)));
            $relativePath = wp_normalize_path(ltrim((string) ($target['relative_path'] ?? ''), '/'));

            if ($normalizedValue === $basename || $normalizedValue === $relativePath || str_ends_with($relativePath, '/' . $normalizedValue)) {
                return $filePath;
            }
        }

        return null;
    }

    public function isUsableSimpleFileListNiceName(string $niceName, string $basename): bool {
        if ($niceName === '' || $niceName === $basename) {
            return false;
        }

        if (strpos($niceName, '/') !== false || strpos($niceName, '\\') !== false) {
            return false;
        }

        return true;
    }
}
