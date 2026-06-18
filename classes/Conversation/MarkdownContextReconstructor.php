<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

class MarkdownContextReconstructor {
    private MarkdownCacheStore $markdownCacheStore;
    private ManagedSourceReferenceResolver $resolver;

    public function __construct(?MarkdownCacheStore $markdownCacheStore = null, ?ManagedSourceReferenceResolver $resolver = null) {
        $this->markdownCacheStore = $markdownCacheStore ?? new MarkdownCacheStore();
        $this->resolver = $resolver ?? new ManagedSourceReferenceResolver();
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,array<string,string>>
     */
    public function reconstructBatch(array $items): array {
        $results = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = isset($item['key']) ? trim((string) $item['key']) : '';
            $url = isset($item['url']) ? trim((string) $item['url']) : '';
            $sourceUrl = isset($item['source_url']) ? trim((string) $item['source_url']) : '';
            $snippet = isset($item['text']) ? trim((string) $item['text']) : '';
            if ($key === '' || $url === '' || $snippet === '') {
                continue;
            }

            $reconstructed = $this->reconstructForUrl($url, $snippet, $sourceUrl);
            if ($reconstructed !== '') {
                $resolvedUrl = $this->resolveUrl($url, $sourceUrl);
                $results[$key] = [
                    'markdown' => $reconstructed,
                    'url' => $resolvedUrl ?: $url,
                ];
            }
        }

        return $results;
    }

    public function reconstructForUrl(string $url, string $snippet, string $sourceUrl = ''): string {
        $postId = $this->resolver->extractPostIdFromUrl($url);
        if ($postId <= 0 && $sourceUrl !== '') {
            $postId = $this->resolver->extractPostIdFromUrl($sourceUrl);
        }
        if ($postId <= 0) {
            return '';
        }

        $markdown = $this->markdownCacheStore->getMarkdown($postId);
        if ($markdown === '') {
            return '';
        }

        $reconstructed = $this->reconstructFromMarkdown($markdown, $snippet);
        return $reconstructed !== '' ? $this->resolveInternalLinks($reconstructed) : '';
    }

    private function reconstructFromMarkdown(string $markdown, string $snippet): string {
        $tableBlocks = $this->extractMarkdownTableBlocks($markdown);
        if (empty($tableBlocks)) {
            return '';
        }

        $snippetTokens = $this->tokenizeText($snippet);
        if (count($snippetTokens) < 3) {
            return '';
        }

        $bestScore = 0.0;
        $bestBlock = '';

        foreach ($tableBlocks as $block) {
            $candidate = $this->findBestMatchingTableExcerpt($block, $snippetTokens);
            if ($candidate['score'] > $bestScore) {
                $bestScore = $candidate['score'];
                $bestBlock = $candidate['markdown'];
            }
        }

        return $bestScore >= 0.34 ? trim($bestBlock) : '';
    }

    /**
     * @return array<int,array{header:string,separator:string,rows:array<int,string>}>
     */
    private function extractMarkdownTableBlocks(string $markdown): array {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $blocks = [];
        $count = count($lines);

        for ($index = 0; $index < $count - 1; $index++) {
            $header = trim((string) $lines[$index]);
            $separator = trim((string) $lines[$index + 1]);
            if (!$this->isMarkdownTableLine($header) || !$this->isMarkdownTableSeparator($separator)) {
                continue;
            }

            $rows = [];
            $nextIndex = $index + 2;
            while ($nextIndex < $count) {
                $row = trim((string) $lines[$nextIndex]);
                if (!$this->isMarkdownTableLine($row)) {
                    break;
                }

                $rows[] = $row;
                $nextIndex++;
            }

            if (!empty($rows)) {
                $blocks[] = [
                    'header' => $header,
                    'separator' => $separator,
                    'rows' => $rows,
                ];
            }

            $index = $nextIndex - 1;
        }

        return $blocks;
    }

    private function isMarkdownTableLine(string $line): bool {
        return $line !== '' && substr_count($line, '|') >= 2;
    }

    private function isMarkdownTableSeparator(string $line): bool {
        $cells = $this->parseMarkdownTableCells($line);
        if (empty($cells)) {
            return false;
        }

        foreach ($cells as $cell) {
            if (!preg_match('/^:?-{3,}:?$/', $cell)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{header:string,separator:string,rows:array<int,string>} $block
     * @param array<int,string> $snippetTokens
     * @return array{score:float,markdown:string}
     */
    private function findBestMatchingTableExcerpt(array $block, array $snippetTokens): array {
        $bestScore = 0.0;
        $bestMarkdown = '';
        $rowCount = count($block['rows']);

        foreach ($block['rows'] as $index => $row) {
            $rowTokens = $this->tokenizeText($row);
            if (empty($rowTokens)) {
                continue;
            }

            $score = $this->scoreTokenOverlap($snippetTokens, $rowTokens);
            if ($score > $bestScore) {
                $excerptRows = [$row];
                if ($score >= 0.5 && $index + 1 < $rowCount) {
                    $excerptRows[] = $block['rows'][$index + 1];
                }

                $bestScore = $score;
                $bestMarkdown = implode("\n", array_filter([
                    $block['header'],
                    $block['separator'],
                    implode("\n", $excerptRows),
                ]));
            }
        }

        return [
            'score' => $bestScore,
            'markdown' => $bestMarkdown,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function parseMarkdownTableCells(string $line): array {
        $trimmed = trim($line);
        $trimmed = preg_replace('/^\|/', '', $trimmed);
        $trimmed = preg_replace('/\|$/', '', (string) $trimmed);
        $cells = explode('|', (string) $trimmed);

        return array_values(array_filter(array_map(static function ($cell): string {
            return trim((string) $cell);
        }, $cells), static function ($cell): bool {
            return $cell !== '';
        }));
    }

    /**
     * @return array<int,string>
     */
    private function tokenizeText(string $text): array {
        $normalized = strtolower($this->stripMarkdown($text));
        $parts = preg_split('/[^a-z0-9]+/i', $normalized) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $token = trim((string) $part);
            if ($token === '' || strlen($token) < 3) {
                continue;
            }

            $tokens[$token] = $token;
        }

        return array_values($tokens);
    }

    private function scoreTokenOverlap(array $needleTokens, array $haystackTokens): float {
        if (empty($needleTokens) || empty($haystackTokens)) {
            return 0.0;
        }

        $haystackLookup = array_fill_keys($haystackTokens, true);
        $matched = 0;

        foreach ($needleTokens as $token) {
            if (isset($haystackLookup[$token])) {
                $matched++;
            }
        }

        return $matched / max(count($needleTokens), 1);
    }

    private function stripMarkdown(string $text): string {
        return trim((string) preg_replace([
            '/!\[[^\]]*]\(([^)]+)\)/',
            '/\[([^\]]+)\]\(([^)]+)\)/',
            '/[*_~`>#]/',
            '/\|/',
        ], [
            ' ',
            '$1',
            ' ',
            ' ',
        ], $text));
    }

    private function resolveUrl(string $url, string $sourceUrl = ''): string {
        $resolved = $this->resolver->resolve($url);
        if (!empty($resolved['url'])) {
            return $resolved['url'];
        }

        if ($sourceUrl !== '') {
            $resolved = $this->resolver->resolve($sourceUrl);
            if (!empty($resolved['url'])) {
                return $resolved['url'];
            }
        }

        return '';
    }

    private function resolveInternalLinks(string $markdown): string {
        $result = $markdown;

        $result = (string) preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($matches) {
                $text = $matches[1];
                $url = trim((string) $matches[2]);

                $resolved = $this->resolver->resolve($url);
                if (!empty($resolved['url'])) {
                    return '[' . $text . '](' . $resolved['url'] . ')';
                }

                return $matches[0];
            },
            $result
        );

        $result = (string) preg_replace_callback(
            '/\b(\d+)\.md\b/',
            function ($matches) {
                $url = $matches[0];
                $resolved = $this->resolver->resolve($url);
                if (!empty($resolved['url'])) {
                    return $resolved['url'];
                }

                return $matches[0];
            },
            $result
        );

        return $result;
    }
}
