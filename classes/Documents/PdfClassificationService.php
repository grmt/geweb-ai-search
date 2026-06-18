<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

/**
 * Lightweight local PDF classifier using simple structure heuristics.
 */
class PdfClassificationService {
    private const OPTION_CACHE = 'geweb_aisearch_pdf_classification_cache';
    private const MAX_HEAD_BYTES = 2097152; // 2 MB
    private const MAX_TAIL_BYTES = 262144; // 256 KB

    /**
     * @return array{key:string,label:string,details:string}
     */
    public function classify(string $filePath): array {
        $normalizedPath = wp_normalize_path($filePath);
        if ($normalizedPath === '' || !is_file($normalizedPath) || !is_readable($normalizedPath)) {
            return [
                'key' => 'broken',
                'label' => 'Missing PDF',
                'details' => 'The PDF file is missing or unreadable on disk.',
            ];
        }

        $signature = $this->buildSignature($normalizedPath);
        $cache = $this->getCache();
        if (isset($cache[$normalizedPath]['signature'], $cache[$normalizedPath]['result']) && $cache[$normalizedPath]['signature'] === $signature) {
            /** @var array{key:string,label:string,details:string} $result */
            return $cache[$normalizedPath]['result'];
        }

        $result = $this->classifyFresh($normalizedPath);
        $cache[$normalizedPath] = [
            'signature' => $signature,
            'result' => $result,
        ];
        UserScope::updateGroupScopedOption(self::OPTION_CACHE, $cache, false);

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function getCache(): array {
        $cache = UserScope::getGroupScopedOption(self::OPTION_CACHE, []);
        return is_array($cache) ? $cache : [];
    }

    private function buildSignature(string $filePath): string {
        $size = @filesize($filePath);
        $mtime = @filemtime($filePath);
        return (string) intval($size ?: 0) . ':' . (string) intval($mtime ?: 0);
    }

    /**
     * @return array{key:string,label:string,details:string}
     */
    private function classifyFresh(string $filePath): array {
        $size = (int) (@filesize($filePath) ?: 0);
        $head = @file_get_contents($filePath, false, null, 0, self::MAX_HEAD_BYTES);
        if (!is_string($head) || $head === '') {
            return [
                'key' => 'unknown',
                'label' => 'Unknown PDF',
                'details' => 'Could not read enough of the PDF to classify it.',
            ];
        }

        $sample = $head;
        if ($size > self::MAX_HEAD_BYTES) {
            $tailOffset = max(0, $size - self::MAX_TAIL_BYTES);
            $tail = @file_get_contents($filePath, false, null, $tailOffset, self::MAX_TAIL_BYTES);
            if (is_string($tail) && $tail !== '') {
                $sample .= "\n" . $tail;
            }
        }

        $fontCount = $this->countNeedles($sample, ['/Font', '/FontDescriptor', '/ToUnicode']);
        $textOpCount = $this->countNeedles($sample, [' BT', "\nBT", ' ET', "\nET", ' Tj', ' TJ', ' Tf']);
        $imageCount = $this->countNeedles($sample, ['/Subtype /Image', '/Image', '/XObject']);

        $details = sprintf('fonts=%d, text_ops=%d, images=%d', $fontCount, $textOpCount, $imageCount);

        $classification = $this->classifyFromCounts($fontCount, $textOpCount, $imageCount);
        return [
            'key' => $classification['key'],
            'label' => $classification['label'],
            'details' => $details,
        ];
    }

    /**
     * @return array{key:string,label:string}
     */
    private function classifyFromCounts(int $fontCount, int $textOpCount, int $imageCount): array {
        $textSignalCount = $fontCount + $textOpCount;
        $key = 'unknown';
        $label = 'Unknown PDF';

        if ($textSignalCount >= 8 && $imageCount <= 2) {
            $key = 'text';
            $label = 'Text PDF';
        } elseif ($textSignalCount >= 5 && $imageCount >= 2) {
            $key = 'mixed';
            $label = 'Mixed PDF';
        } elseif ($textSignalCount <= 2 && $imageCount >= 2) {
            $key = 'scanned';
            $label = 'Scanned PDF';
        }

        return ['key' => $key, 'label' => $label];
    }

    /**
     * @param array<int,string> $needles
     */
    private function countNeedles(string $haystack, array $needles): int {
        $count = 0;
        foreach ($needles as $needle) {
            $count += substr_count($haystack, $needle);
        }

        return $count;
    }
}
