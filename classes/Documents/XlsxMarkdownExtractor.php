<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Extracts basic worksheet content from .xlsx files into Markdown without PHP ZIP/XML extensions.
 */
class XlsxMarkdownExtractor {
    /**
     * @throws \Exception
     */
    public function extract(string $filePath, string $displayName = ''): string {
        if (!is_file($filePath)) {
            throw new \Exception('Spreadsheet file does not exist.');
        }

        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            throw new \Exception('Spreadsheet extractor only supports .xlsx files.');
        }

        $archiveEntries = $this->listArchiveEntries($filePath);
        if (
            !in_array('xl/workbook.xml', $archiveEntries, true)
            || !in_array('xl/_rels/workbook.xml.rels', $archiveEntries, true)
        ) {
            throw new \Exception('Spreadsheet does not look like a valid .xlsx workbook.');
        }

        $workbookXml = $this->readArchiveEntry($filePath, 'xl/workbook.xml');
        $relsXml = $this->readArchiveEntry($filePath, 'xl/_rels/workbook.xml.rels');
        $sharedStrings = in_array('xl/sharedStrings.xml', $archiveEntries, true)
            ? $this->parseSharedStrings($this->readArchiveEntry($filePath, 'xl/sharedStrings.xml'))
            : [];

        $sheetDefinitions = $this->parseWorkbookSheets($workbookXml, $relsXml);
        if ($sheetDefinitions === []) {
            throw new \Exception('No worksheets were found in the spreadsheet.');
        }

        $documentTitle = $displayName !== '' ? $displayName : basename($filePath);
        $markdown = [];
        $markdown[] = '---';
        $markdown[] = 'title: ' . $this->escapeFrontmatterValue($documentTitle);
        $markdown[] = 'source_type: xlsx';
        $markdown[] = 'source_filename: ' . $this->escapeFrontmatterValue(basename($filePath));
        $markdown[] = '---';
        $markdown[] = '';
        $markdown[] = '# ' . $documentTitle;
        $markdown[] = '';

        foreach ($sheetDefinitions as $sheetDefinition) {
            $sheetPath = $sheetDefinition['path'];
            if (!in_array($sheetPath, $archiveEntries, true)) {
                continue;
            }

            $sheetXml = $this->readArchiveEntry($filePath, $sheetPath);
            $sheetMarkdown = $this->buildSheetMarkdown($sheetDefinition['name'], $sheetXml, $sharedStrings);
            if ($sheetMarkdown === '') {
                continue;
            }

            $markdown[] = $sheetMarkdown;
            $markdown[] = '';
        }

        return trim(implode("\n", $markdown)) . "\n";
    }

    /**
     * @return string[]
     * @throws \Exception
     */
    private function listArchiveEntries(string $filePath): array {
        $command = sprintf('unzip -Z1 %s 2>/dev/null', escapeshellarg($filePath));
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            throw new \Exception('Could not inspect spreadsheet archive contents.');
        }

        return array_values(array_filter(array_map('trim', $output), static function ($entry): bool {
            return $entry !== '';
        }));
    }

    /**
     * @throws \Exception
     */
    private function readArchiveEntry(string $filePath, string $entryPath): string {
        $command = sprintf('unzip -p %s %s 2>/dev/null', escapeshellarg($filePath), escapeshellarg($entryPath));
        $output = shell_exec($command);
        if (!is_string($output) || $output === '') {
            throw new \Exception(sprintf('Could not read spreadsheet entry: %s', $entryPath));
        }

        return $output;
    }

    /**
     * @return array<int,string>
     */
    private function parseSharedStrings(string $xml): array {
        if (!preg_match_all('/<si\b[^>]*>(.*?)<\/si>/si', $xml, $matches)) {
            return [];
        }

        $strings = [];
        foreach ($matches[1] as $sharedStringXml) {
            $strings[] = $this->extractInlineText($sharedStringXml);
        }

        return $strings;
    }

    /**
     * @return array<int,array{name:string,path:string}>
     */
    private function parseWorkbookSheets(string $workbookXml, string $relsXml): array {
        $relationshipTargets = [];
        if (preg_match_all('/<Relationship\b([^>]*)\/>/si', $relsXml, $matches)) {
            foreach ($matches[1] as $attributes) {
                $id = $this->extractAttribute($attributes, 'Id');
                $target = $this->extractAttribute($attributes, 'Target');
                if ($id === '' || $target === '') {
                    continue;
                }

                $relationshipTargets[$id] = 'xl/' . ltrim($target, '/');
            }
        }

        $sheets = [];
        if (preg_match_all('/<sheet\b([^>]*)\/>/si', $workbookXml, $matches)) {
            foreach ($matches[1] as $attributes) {
                $name = $this->extractAttribute($attributes, 'name');
                $relationshipId = $this->extractAttribute($attributes, 'r:id');
                if ($name === '' || $relationshipId === '' || empty($relationshipTargets[$relationshipId])) {
                    continue;
                }

                $sheets[] = [
                    'name' => $name,
                    'path' => $relationshipTargets[$relationshipId],
                ];
            }
        }

        return $sheets;
    }

    private function buildSheetMarkdown(string $sheetName, string $sheetXml, array $sharedStrings): string {
        $rows = $this->parseWorksheetRows($sheetXml, $sharedStrings);
        if ($rows === []) {
            return '## Sheet: ' . $sheetName . "\n\n" . '_No visible rows found._';
        }

        $maxColumnIndex = 0;
        foreach ($rows as $row) {
            if ($row !== []) {
                $maxColumnIndex = max($maxColumnIndex, max(array_keys($row)));
            }
        }

        $maxColumnIndex = max(1, $maxColumnIndex);

        $markdown = [];
        $markdown[] = '## Sheet: ' . $sheetName;
        $markdown[] = '';

        $headerCells = ['Row'];
        for ($columnIndex = 1; $columnIndex <= $maxColumnIndex; $columnIndex++) {
            $headerCells[] = $this->columnIndexToLetters($columnIndex);
        }
        $markdown[] = $this->buildMarkdownRow($headerCells);
        $markdown[] = $this->buildMarkdownRow(array_fill(0, count($headerCells), '---'));

        foreach ($rows as $rowNumber => $rowCells) {
            $cells = [(string) $rowNumber];
            for ($columnIndex = 1; $columnIndex <= $maxColumnIndex; $columnIndex++) {
                $cells[] = $rowCells[$columnIndex] ?? '';
            }
            $markdown[] = $this->buildMarkdownRow($cells);
        }

        return implode("\n", $markdown);
    }

    /**
     * @return array<int,array<int,string>>
     */
    private function parseWorksheetRows(string $sheetXml, array $sharedStrings): array {
        if (!preg_match_all('/<row\b([^>]*)>(.*?)<\/row>/si', $sheetXml, $rowMatches, PREG_SET_ORDER)) {
            return [];
        }

        $rows = [];
        foreach ($rowMatches as $rowMatch) {
            $rowNumber = (int) $this->extractAttribute($rowMatch[1], 'r');
            if ($rowNumber <= 0) {
                $rowNumber = count($rows) + 1;
            }

            $rowCells = [];
            if (preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/si', $rowMatch[2], $cellMatches, PREG_SET_ORDER)) {
                foreach ($cellMatches as $cellMatch) {
                    $attributes = $cellMatch[1];
                    $cellBody = isset($cellMatch[2]) ? (string) $cellMatch[2] : '';
                    $reference = $this->extractAttribute($attributes, 'r');
                    $type = strtolower($this->extractAttribute($attributes, 't'));
                    $columnIndex = $this->cellReferenceToColumnIndex($reference);
                    if ($columnIndex <= 0) {
                        $columnIndex = count($rowCells) + 1;
                    }

                    $rowCells[$columnIndex] = $this->extractCellValue($type, $cellBody, $sharedStrings);
                }
            }

            if ($this->rowHasVisibleContent($rowCells)) {
                ksort($rowCells);
                $rows[$rowNumber] = $rowCells;
            }
        }

        ksort($rows);
        return $rows;
    }

    private function extractCellValue(string $type, string $cellXml, array $sharedStrings): string {
        $value = '';

        if ($type === 'inlineStr') {
            if (preg_match('/<is\b[^>]*>(.*?)<\/is>/si', $cellXml, $match)) {
                $value = $this->extractInlineText($match[1]);
            }
        } else {
            if (preg_match('/<v\b[^>]*>(.*?)<\/v>/si', $cellXml, $match)) {
                $value = html_entity_decode(trim(strip_tags($match[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (preg_match('/<t\b[^>]*>(.*?)<\/t>/si', $cellXml, $match)) {
                $value = html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        if ($type === 's') {
            $sharedStringIndex = (int) trim($value);
            $value = $sharedStrings[$sharedStringIndex] ?? '';
        } elseif ($type === 'b') {
            $value = trim($value) === '1' ? 'TRUE' : 'FALSE';
        }

        if ($value === '' && preg_match('/<f\b[^>]*>(.*?)<\/f>/si', $cellXml, $formulaMatch)) {
            $value = 'Formula: ' . html_entity_decode(trim(strip_tags($formulaMatch[1])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $this->normalizeCellText($value);
    }

    private function extractInlineText(string $xml): string {
        if (!preg_match_all('/<t\b[^>]*>(.*?)<\/t>/si', $xml, $matches)) {
            return $this->normalizeCellText(html_entity_decode(trim(strip_tags($xml)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $parts = array_map(static function (string $text): string {
            return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $matches[1]);

        return $this->normalizeCellText(implode('', $parts));
    }

    private function normalizeCellText(string $text): string {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function buildMarkdownRow(array $cells): string {
        $escapedCells = array_map(function (string $cell): string {
            $cell = trim($cell);
            $cell = str_replace('|', '\|', $cell);
            $cell = str_replace("\n", ' <br> ', $cell);
            return $cell;
        }, $cells);

        return '| ' . implode(' | ', $escapedCells) . ' |';
    }

    private function rowHasVisibleContent(array $rowCells): bool {
        foreach ($rowCells as $cell) {
            if (trim((string) $cell) !== '') {
                return true;
            }
        }

        return false;
    }

    private function extractAttribute(string $attributeText, string $attributeName): string {
        $pattern = '/\b' . preg_quote($attributeName, '/') . '="([^"]*)"/i';
        if (!preg_match($pattern, $attributeText, $match)) {
            return '';
        }

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function cellReferenceToColumnIndex(string $reference): int {
        if (!preg_match('/^([A-Z]+)/i', $reference, $match)) {
            return 0;
        }

        $letters = strtoupper($match[1]);
        $index = 0;
        $length = strlen($letters);
        for ($offset = 0; $offset < $length; $offset++) {
            $index = ($index * 26) + (ord($letters[$offset]) - 64);
        }

        return $index;
    }

    private function columnIndexToLetters(int $index): string {
        $letters = '';
        while ($index > 0) {
            $index--;
            $letters = chr(($index % 26) + 65) . $letters;
            $index = (int) floor($index / 26);
        }

        return $letters !== '' ? $letters : 'A';
    }

    private function escapeFrontmatterValue(string $value): string {
        return '"' . str_replace('"', '\"', $value) . '"';
    }
}
