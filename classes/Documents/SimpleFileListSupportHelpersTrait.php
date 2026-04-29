<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

trait SimpleFileListSupportHelpersTrait {
    private function buildEntryFromRecord(array $record): ?array {
        $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
        if ($filePathValue === '') { return null; }
        $resolved = $this->resolveSimpleFileListRecordPath($filePathValue);
        if ($resolved === null) { return null; }
        $niceName = isset($record['FileNiceName']) ? trim((string) $record['FileNiceName']) : '';
        return ['file_path' => $resolved['file_path'], 'file_url' => $resolved['file_url'], 'display_name' => $niceName !== '' ? $niceName : $this->prettifyFileName(basename($resolved['file_path']))];
    }
    public function resolveSimpleFileListRecordPath(string $filePathValue): ?array {
        $uploads = wp_get_upload_dir(); $baseDir = wp_normalize_path((string) ($uploads['basedir'] ?? '')); $baseUrl = (string) ($uploads['baseurl'] ?? '');
        if ($baseDir === '' || $baseUrl === '') { return null; }
        $normalizedValue = wp_normalize_path(ltrim(trim($filePathValue), '/'));
        $candidates = [];
        if ($normalizedValue !== '') { $candidates[] = wp_normalize_path(trailingslashit($baseDir) . $normalizedValue); }
        $baseName = basename($normalizedValue);
        if ($baseName !== '') { $searched = $this->findFileInUploadsByBasename($baseName, $baseDir); if ($searched !== null) { $candidates[] = $searched; } }
        foreach (array_unique($candidates) as $candidate) {
            if ($candidate === '' || !is_file($candidate) || !is_readable($candidate) || strpos($candidate, $baseDir) !== 0) { continue; }
            $relativePath = ltrim(substr($candidate, strlen($baseDir)), '/');
            return ['file_path' => $candidate, 'file_url' => trailingslashit($baseUrl) . str_replace('%2F', '/', rawurlencode($relativePath))];
        }
        return null;
    }
    public function findFileInUploadsByBasename(string $baseName, string $baseDir): ?string {
        static $indexedFiles = null;
        if ($indexedFiles === null) { $indexedFiles = $this->buildUploadFileIndex($baseDir); if ($indexedFiles === null) { return null; } }
        return isset($indexedFiles[$baseName]) ? (string) $indexedFiles[$baseName][0] : null;
    }
    private function buildUploadFileIndex(string $baseDir): ?array {
        if (!is_dir($baseDir) || !is_readable($baseDir)) { return []; }
        try {
            $index = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $fileInfo) { if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile()) { $index[$fileInfo->getBasename()][] = wp_normalize_path($fileInfo->getPathname()); } }
            return $index;
        } catch (\Throwable $e) { return null; }
    }
    public function extractSimpleFileListRecordMatch(array $record, array $targets): ?array {
        $stringFields = $this->buildStringFields($record);
        if (empty($stringFields)) { return null; }
        foreach ($targets as $filePath => $target) {
            if (!$this->isTargetMatchedByFields($stringFields, $target['basename'], $target['relative_path'])) { continue; }
            $niceName = $this->findNiceNameFromMatchedFields($stringFields, $target['basename']);
            if ($niceName !== null) { return ['file_path' => $filePath, 'nice_name' => $niceName]; }
        }
        return null;
    }

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

    private function processRemoveRow(array $row, string $normalizedPath, array $targets, bool &$removedAny): void {
        if (!is_array($row) || !isset($row['option_name'], $row['option_value'])) {
            return;
        }
        $value = maybe_unserialize($row['option_value']);
        if (!is_array($value)) {
            return;
        }
        $updatedValue = [];
        $removedFromOption = false;
        foreach ($value as $record) {
            if (!is_array($record)) {
                $updatedValue[] = $record;
                continue;
            }
            $recordPath = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
            $matchedPath = $recordPath !== ''
                ? $this->matchSimpleFileListRecordPath($recordPath, $targets)
                : null;
            if ($matchedPath === $normalizedPath) {
                $removedFromOption = true;
                $removedAny = true;
                continue;
            }
            $updatedValue[] = $record;
        }
        if ($removedFromOption) {
            update_option((string) $row['option_name'], array_values($updatedValue), false);
        }
    }

    private function buildDirectoryFileTargets(array $directories): array {
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

        return $targets;
    }

    private function getSupportedFilesInDirectory(string $directory): array {
        $directory = wp_normalize_path($directory);
        if ($directory === '' || !is_dir($directory) || !is_readable($directory)) {
            return [];
        }

        $files = [];
        try {
            $iterator = new \DirectoryIterator($directory);
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }

                $filePath = wp_normalize_path($fileInfo->getPathname());
                if ($filePath !== '' && is_readable($filePath) && $this->isSupportedSimpleFileListFile($filePath)) {
                    $files[] = $filePath;
                }
            }
        } catch (\Throwable $e) {
            error_log('geweb-ai-search: could not scan Simple File List directory ' . $directory . ': ' . $e->getMessage());
            return [];
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    private function isSupportedSimpleFileListFile(string $filePath): bool {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === '' || in_array($extension, ['php', 'phtml', 'phar', 'js', 'css', 'html', 'htm', 'svg'], true)) {
            return false;
        }

        $mimeType = strtolower(trim((new DocumentStore())->resolveMimeType($filePath)));
        if ($mimeType === '') {
            return false;
        }

        if (strpos($mimeType, 'text/') === 0 || strpos($mimeType, 'image/') === 0) {
            return true;
        }

        return in_array($mimeType, [
            'application/pdf',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], true);
    }

    private function getSflCandidateTableNames(): array {
        global $wpdb;
        return array_values(array_unique(array_filter(array_merge(
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'eeSFL') . '%')),
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'simple_file_list') . '%')),
            $wpdb->get_col($wpdb->prepare(self::SQL_SHOW_TABLES_LIKE, $wpdb->esc_like($wpdb->prefix . 'eesfl') . '%'))
        ))));
    }

    private function processNiceNameFileListRow(array $row, array $targets, array &$niceNames): void {
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
            if (is_array($record)) {
                $this->applyNiceNameFromRecord($record, $targets, $niceNames, $optionName);
            }
        }
    }

    private function applyNiceNameFromRecord(array $record, array $targets, array &$niceNames, string $optionName): void {
        $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
        $niceName = isset($record['FileNiceName']) ? trim((string) $record['FileNiceName']) : '';
        if ($filePathValue === '' || $niceName === '') {
            return;
        }
        $matchedPath = $this->matchSimpleFileListRecordPath($filePathValue, $targets);
        if ($matchedPath === null || isset($niceNames[$matchedPath])) {
            return;
        }
        $niceNames[$matchedPath] = $niceName;
        error_log('geweb-ai-search: SFL nice-name match from ' . $optionName . ' for ' . basename($matchedPath) . ' => ' . $niceName);
    }

    private function findModifiedTimestampFromFields(array $stringFields): ?int {
        foreach ($stringFields as $fieldKey => $fieldValue) {
            if (!preg_match('/modified|updated|changed|date|time|timestamp/i', $fieldKey)) {
                continue;
            }
            $parsed = $this->parseSimpleFileListTimestamp($fieldValue);
            if ($parsed > 0) {
                return $parsed;
            }
        }
        return null;
    }

    private function parseSimpleFileListTimestamp(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 1000000000) {
                return $timestamp;
            }
        }

        $parsed = strtotime($value);
        return $parsed !== false ? (int) $parsed : 0;
    }

    private function buildStringFields(array $record): array {
        $stringFields = [];
        foreach ($record as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $stringValue = trim((string) $value);
            if ($stringValue !== '') {
                $stringFields[(string) $key] = $stringValue;
            }
        }
        return $stringFields;
    }

    private function isTargetMatchedByFields(array $stringFields, string $basename, string $relativePath): bool {
        foreach ($stringFields as $fieldValue) {
            $normalizedField = wp_normalize_path($fieldValue);
            if (
                $fieldValue === $basename ||
                $normalizedField === wp_normalize_path($relativePath) ||
                str_ends_with($normalizedField, '/' . $basename) ||
                strpos($normalizedField, $basename) !== false
            ) {
                return true;
            }
        }
        return false;
    }

    private function findNiceNameFromMatchedFields(array $stringFields, string $basename): ?string {
        foreach ($stringFields as $fieldKey => $fieldValue) {
            if (!preg_match('/nice|display|title|label/i', $fieldKey)) {
                continue;
            }
            $niceName = trim($fieldValue);
            if ($this->isUsableSimpleFileListNiceName($niceName, $basename)) {
                return $niceName;
            }
        }
        foreach ($stringFields as $fieldKey => $fieldValue) {
            if (!preg_match('/name/i', $fieldKey) || preg_match('/file|path|url|ext|type/i', $fieldKey)) {
                continue;
            }
            $niceName = trim($fieldValue);
            if ($this->isUsableSimpleFileListNiceName($niceName, $basename)) {
                return $niceName;
            }
        }
        return null;
    }

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

    public function isUsableSimpleFileListNiceName(string $niceName, string $basename): bool {
        if ($niceName === '' || $niceName === $basename) {
            return false;
        }

        return strpos($niceName, '/') === false && strpos($niceName, '\\') === false;
    }
}
