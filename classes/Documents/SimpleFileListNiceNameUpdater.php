<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class SimpleFileListNiceNameUpdater {
    private SimpleFileListSupport $simpleFileListSupport;

    public function __construct(?SimpleFileListSupport $simpleFileListSupport = null) {
        $this->simpleFileListSupport = $simpleFileListSupport ?? new SimpleFileListSupport();
    }

    /**
     * @param mixed $row
     * @return array<int|mixed,mixed>|null
     */
    public function buildUpdatedOption($row, string $filePath, string $niceName): ?array {
        if (!is_array($row) || !isset($row['option_name'], $row['option_value'])) {
            return null;
        }

        $value = maybe_unserialize($row['option_value']);
        if (!is_array($value)) {
            return null;
        }

        $updated = false;
        foreach ($value as $index => $record) {
            if (!$this->recordMatchesPath($record, $filePath)) {
                continue;
            }

            $value[$index]['FileNiceName'] = $niceName;
            $updated = true;
        }

        return $updated ? $value : null;
    }

    /**
     * @param mixed $record
     */
    private function recordMatchesPath($record, string $filePath): bool {
        if (!is_array($record)) {
            return false;
        }

        $filePathValue = isset($record['FilePath']) ? trim((string) $record['FilePath']) : '';
        if ($filePathValue === '') {
            return false;
        }

        $resolved = $this->simpleFileListSupport->resolveSimpleFileListRecordPath($filePathValue);
        return is_array($resolved) && (($resolved['file_path'] ?? '') === $filePath);
    }
}
