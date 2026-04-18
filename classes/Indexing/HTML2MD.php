<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

use League\HTMLToMarkdown\HtmlConverter;
use League\HTMLToMarkdown\Converter\TableConverter;

/**
 * HTML to Markdown converter.
 */
class HTML2MD {
    private const ANCHOR_MARKER_PREFIX = 'GEWEB_ID_ANCHOR~~';
    private const MARKER_SUFFIX = '~~';
    private const TABLE_TOKEN_PREFIX = 'GEWEBTABLETOKEN';
    private const TABLE_TOKEN_SUFFIX = 'END';
    private const INLINE_BREAK_TOKEN = 'GEWEBINLINEBREAKTOKEN';
    /**
     * @var array<string,string>
     */
    private array $preservedSuperscriptLinks = [];
    /**
     * @var array<string,string>
     */
    private array $preservedHeadingLinks = [];
    /**
     * @var array<string,string>
     */
    private array $preservedTables = [];

    /**
     * Convert WordPress post to Markdown.
     *
     * @param int $postId
     * @return string|null
     */
    public function convert(int $postId): ?string {
        $this->preservedSuperscriptLinks = [];
        $this->preservedHeadingLinks = [];
        $this->preservedTables = [];
        $post = get_post($postId);
        if (!$post) {
            return null;
        }

        $imageOcrService = new ImageOcrService();
        if ($post instanceof \WP_Post && $post->post_type === 'attachment' && $imageOcrService->isOcrEligibleAttachment($postId)) {
            $attachmentMarkdown = $imageOcrService->buildAttachmentMarkdown($postId);
            if (is_string($attachmentMarkdown) && trim($attachmentMarkdown) !== '') {
                return $attachmentMarkdown;
            }
        }

        $content = apply_filters('the_content', $post->post_content);
        $content = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $content);
        $content = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', '', $content);
        $content = $imageOcrService->replaceMarkedUploadsImagesWithOcrText($content);
        $content = preg_replace('/<img\b[^>]*src\s*=\s*["\']data:image\/[^"\']*["\'][^>]*>/i', '', $content);
        $content = $this->normalizeSourceHtml($content);
        $content = $this->preserveTables($content);
        $content = preg_replace('/<figure\b[^>]*>/i', '', $content);
        $content = preg_replace('/<\/figure>/i', '', $content);
        $content = $this->preserveHeadingLinks($content);
        $content = $this->preserveElementIds($content);
        $content = $this->preserveSuperscriptLinks($content);

        $converter = new HtmlConverter();
        $converter->getEnvironment()->addConverter(new TableConverter());
        $mdContent = $converter->convert($content);
        $mdContent = $this->restoreTableTokens($mdContent);
        $mdContent = $this->restoreHeadingLinkTokens($mdContent);
        $mdContent = $this->restoreSuperscriptLinkTokens($mdContent);
        $mdContent = $this->restoreAnchorMarkers($mdContent);
        $mdContent = preg_replace('/!\[[^\]]*]\(data:image\/[^)]+\)/i', '', $mdContent) ?? $mdContent;
        $mdContent = $this->normalizeMarkdownOutput($mdContent);

        $url = get_permalink($postId);
        $title = get_the_title($postId);

        $frontmatter = "---\n";
        $frontmatter .= "url: {$url}\n";
        $frontmatter .= "title: {$title}\n";
        if ($post instanceof \WP_Post && $post->post_type === 'page') {
            $frontmatter .= "page_id: {$postId}\n";
        } elseif ($post instanceof \WP_Post && $post->post_type === 'attachment' && wp_attachment_is_image($postId)) {
            $frontmatter .= "image_id: {$postId}\n";
        } else {
            $frontmatter .= "post_id: {$postId}\n";
        }
        $frontmatter .= "---\n\n";
        $frontmatter .= "# {$title}\n\n";
        $frontmatter .= $mdContent;

        return $frontmatter;
    }

    private function normalizeSourceHtml(string $content): string {
        if ($content === '') {
            return '';
        }

        $content = preg_replace('/<\/?mark\b[^>]*>/i', '', $content) ?? $content;

        $content = preg_replace_callback(
            '/<(p|div|li|h[1-6])(\b[^>]*)>(.*?)<\/\1>/is',
            function (array $matches): string {
                $tagName = isset($matches[1]) ? (string) $matches[1] : '';
                $attributes = isset($matches[2]) ? (string) $matches[2] : '';
                $innerHtml = isset($matches[3]) ? (string) $matches[3] : '';
                if ($tagName === '') {
                    return (string) ($matches[0] ?? '');
                }

                $innerHtml = preg_replace('/^(?:\s*<br\b[^>]*>\s*)+/i', '', $innerHtml) ?? $innerHtml;
                $innerHtml = preg_replace('/(?:\s*<br\b[^>]*>\s*)+$/i', '', $innerHtml) ?? $innerHtml;

                return '<' . $tagName . $attributes . '>' . $innerHtml . '</' . $tagName . '>';
            },
            $content
        ) ?? $content;

        return $content;
    }

    private function normalizeMarkdownOutput(string $markdown): string {
        $normalized = str_replace(["\r\n", "\r"], "\n", $markdown);
        $normalized = $this->restoreAnchorMarkers($normalized);
        $normalized = $this->normalizeHeadingMarkup($normalized);
        $normalized = $this->convertRemainingHtmlParagraphs($normalized);
        $normalized = $this->convertRemainingHtmlListsToMarkdown($normalized);
        $normalized = $this->convertRemainingHtmlTablesToMarkdown($normalized);
        $normalized = preg_replace('/<hr\b[^>]*\/?>/i', "\n\n---\n\n", $normalized) ?? $normalized;
        $normalized = $this->normalizeEmailThreadMetadata($normalized);
        $normalized = $this->cleanupRemainingHtml($normalized);
        $normalized = preg_replace('/(!\[[^\]]*]\([^)]+\))(?=\|)/', "$1\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/\n*(<a id="[^"]+"><\/a>)\n*/', "\n\n$1\n\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/(<a\b[^>]*>.*?<\/a>)(?=\|)/is', "$1\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/\n{3,}/', "\n\n", $normalized) ?? $normalized;

        // Use CR/LF line endings for better markdown renderer compatibility
        return str_replace("\n", "\r\n", $normalized);
    }

    private function normalizeEmailThreadMetadata(string $markdown): string {
        $labels = 'From|Sent|To|Cc|CC|Bcc|Subject|Van|Verzonden|Aan|Onderwerp';

        $normalized = preg_replace('/\*\*((' . $labels . '):)\*\*/u', '$1', $markdown) ?? $markdown;

        return preg_replace_callback(
            '/(?:^|\n)(?:(?:' . $labels . '):[^\n]*(?:  \n|\n)){2,}/um',
            function (array $matches): string {
                $block = isset($matches[0]) ? (string) $matches[0] : '';
                if ($block === '') {
                    return '';
                }

                $block = str_replace("  \n", "\n", $block);
                $block = preg_replace('/\n{3,}/', "\n\n", $block) ?? $block;

                return "\n\n" . trim($block) . "\n\n";
            },
            $normalized
        ) ?? $normalized;
    }

    private function normalizeHeadingMarkup(string $markdown): string {
        return preg_replace_callback(
            '/^(#{1,6}\s.*)$/m',
            function (array $matches): string {
                $line = (string) ($matches[1] ?? '');
                if ($line === '') {
                    return $line;
                }

                $line = preg_replace('/<\/h[1-6]>\s*$/i', '', $line) ?? $line;
                $line = preg_replace_callback(
                    '/<a\b[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)<\/a>/is',
                    function (array $linkMatches): string {
                        $href = isset($linkMatches[2]) ? trim((string) $linkMatches[2]) : '';
                        $label = isset($linkMatches[3]) ? trim(wp_strip_all_tags((string) $linkMatches[3])) : '';
                        if ($href === '' || $label === '') {
                            return (string) ($linkMatches[0] ?? '');
                        }

                        return '[' . $label . '](' . $href . ')';
                    },
                    $line
                ) ?? $line;

                return trim($line);
            },
            $markdown
        ) ?? $markdown;
    }

    private function convertRemainingHtmlParagraphs(string $markdown): string {
        return preg_replace_callback(
            '/<p\b[^>]*>(.*?)<\/p>/is',
            function (array $matches): string {
                $innerHtml = isset($matches[1]) ? (string) $matches[1] : '';
                $text = trim($this->convertInlineHtmlFragmentToMarkdown($innerHtml));
                if ($text === '') {
                    return '';
                }

                return "\n" . $text . "\n";
            },
            $markdown
        ) ?? $markdown;
    }

    private function convertRemainingHtmlTablesToMarkdown(string $markdown): string {
        return preg_replace_callback(
            '/<table\b[^>]*>.*?<\/table>/is',
            function (array $matches): string {
                $tableHtml = isset($matches[0]) ? (string) $matches[0] : '';
                if ($tableHtml === '') {
                    return '';
                }

                $converted = $this->convertSingleHtmlTableToMarkdown($tableHtml);
                return $converted !== '' ? "\n" . $converted . "\n" : $tableHtml;
            },
            $markdown
        ) ?? $markdown;
    }

    private function convertRemainingHtmlListsToMarkdown(string $markdown): string {
        return preg_replace_callback(
            '/<(ol|ul)\b[^>]*>.*?<\/\1>/is',
            function (array $matches): string {
                $listHtml = isset($matches[0]) ? (string) $matches[0] : '';
                if ($listHtml === '') {
                    return '';
                }

                $converted = $this->convertHtmlListBlockToMarkdown($listHtml);
                return $converted !== '' ? "\n" . $converted . "\n" : $listHtml;
            },
            $markdown
        ) ?? $markdown;
    }

    private function convertHtmlListBlockToMarkdown(string $html): string {
        if (!class_exists('\DOMDocument')) {
            return '';
        }

        $document = new \DOMDocument();
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if ($loaded !== true) {
            return '';
        }

        $rootList = null;
        foreach ($document->getElementsByTagName('body') as $body) {
            foreach ($body->childNodes as $childNode) {
                if ($childNode instanceof \DOMElement && in_array(strtolower($childNode->tagName), ['ol', 'ul'], true)) {
                    $rootList = $childNode;
                    break 2;
                }
            }
        }

        if (!$rootList instanceof \DOMElement) {
            return '';
        }

        return trim($this->extractListMarkdown($rootList, 0));
    }

    private function extractListMarkdown(\DOMElement $listElement, int $depth): string {
        $isOrdered = strtolower($listElement->tagName) === 'ol';
        $lines = [];
        $index = 1;

        foreach ($listElement->childNodes as $childNode) {
            if (!$childNode instanceof \DOMElement || strtolower($childNode->tagName) !== 'li') {
                continue;
            }

            $inlineHtml = '';
            $nestedLists = [];

            foreach ($childNode->childNodes as $itemChild) {
                if ($itemChild instanceof \DOMElement && in_array(strtolower($itemChild->tagName), ['ol', 'ul'], true)) {
                    $nestedLists[] = $this->extractListMarkdown($itemChild, $depth + 1);
                    continue;
                }

                $inlineHtml .= $this->getNodeOuterHtml($itemChild);
            }

            $itemMarkdown = trim($this->convertInlineHtmlFragmentToMarkdown($inlineHtml));
            $indent = str_repeat('  ', $depth);
            $prefix = $isOrdered ? ($index++) . '. ' : '- ';

            if ($itemMarkdown !== '') {
                $itemMarkdown = preg_replace('/\n/', "\n" . $indent . '  ', $itemMarkdown) ?? $itemMarkdown;
                $lines[] = $indent . $prefix . $itemMarkdown;
            }

            foreach ($nestedLists as $nestedList) {
                $nestedList = trim($nestedList);
                if ($nestedList !== '') {
                    $lines[] = $nestedList;
                }
            }
        }

        return implode("\n", $lines);
    }

    private function cleanupRemainingHtml(string $markdown): string {
        $normalized = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/<br\b[^>]*>/i', "  \n", $normalized) ?? $normalized;
        $normalized = preg_replace('/<\/?(?:p|div|section|article)\b[^>]*>/i', "\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/<\/?(?:ol|ul)\b[^>]*>/i', "\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/<li\b[^>]*>/i', "\n- ", $normalized) ?? $normalized;
        $normalized = preg_replace('/<\/li>/i', "\n", $normalized) ?? $normalized;
        $normalized = preg_replace('/<(strong|b)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $normalized) ?? $normalized;
        $normalized = preg_replace('/<(em|i)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $normalized) ?? $normalized;
        $normalized = preg_replace('/<\/?span\b[^>]*>/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/<\/?(?!a\b)[a-zA-Z][a-zA-Z0-9:-]*\b[^>]*>/i', '', $normalized) ?? $normalized;

        return $normalized;
    }

    private function preserveTables(string $content): string {
        $content = preg_replace_callback(
            '/<figure\b[^>]*>\s*(<table\b[^>]*>.*?<\/table>)\s*<\/figure>/is',
            function (array $matches): string {
                $tableHtml = isset($matches[1]) ? (string) $matches[1] : '';
                $markdown = $this->convertSingleHtmlTableToMarkdown($tableHtml);
                if ($markdown === '') {
                    return (string) ($matches[0] ?? '');
                }

                $token = self::TABLE_TOKEN_PREFIX . count($this->preservedTables) . self::TABLE_TOKEN_SUFFIX;
                $this->preservedTables[$token] = "\n\n" . $markdown . "\n\n";
                return $token;
            },
            $content
        ) ?? $content;

        return preg_replace_callback(
            '/<table\b[^>]*>.*?<\/table>/is',
            function (array $matches): string {
                $tableHtml = isset($matches[0]) ? (string) $matches[0] : '';
                $markdown = $this->convertSingleHtmlTableToMarkdown($tableHtml);
                if ($markdown === '') {
                    return $tableHtml;
                }

                $token = self::TABLE_TOKEN_PREFIX . count($this->preservedTables) . self::TABLE_TOKEN_SUFFIX;
                $this->preservedTables[$token] = "\n\n" . $markdown . "\n\n";
                return $token;
            },
            $content
        ) ?? $content;
    }

    private function convertSingleHtmlTableToMarkdown(string $tableHtml): string {
        if (!class_exists('\DOMDocument')) {
            return '';
        }

        $document = new \DOMDocument();
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><body>' . $tableHtml . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        if ($loaded !== true) {
            return '';
        }

        $tables = $document->getElementsByTagName('table');
        if ($tables->length < 1) {
            return '';
        }

        $table = $tables->item(0);
        if (!$table instanceof \DOMElement) {
            return '';
        }

        $rows = [];
        $headerCellCount = 0;

        foreach ($table->getElementsByTagName('tr') as $rowElement) {
            if (!$rowElement instanceof \DOMElement) {
                continue;
            }

            $cells = [];
            $rowHasHeader = false;

            foreach ($rowElement->childNodes as $cellNode) {
                if (!$cellNode instanceof \DOMElement) {
                    continue;
                }

                $tagName = strtolower($cellNode->tagName);
                if ($tagName !== 'th' && $tagName !== 'td') {
                    continue;
                }

                if ($tagName === 'th') {
                    $rowHasHeader = true;
                }

                $cellMarkdown = trim($this->extractTableCellMarkdown($cellNode));
                $cellMarkdown = preg_replace('/\s*\n\s*/', ' <br> ', $cellMarkdown) ?? $cellMarkdown;
                $cellMarkdown = preg_replace('/\s{2,}/', ' ', $cellMarkdown) ?? $cellMarkdown;
                $cellMarkdown = str_replace('|', '\|', $cellMarkdown);
                $cells[] = $cellMarkdown;
            }

            if ($cells === []) {
                continue;
            }

            $rows[] = [
                'cells' => $cells,
                'is_header' => $rowHasHeader,
            ];

            if ($rowHasHeader && $headerCellCount === 0) {
                $headerCellCount = count($cells);
            }
        }

        if ($rows === []) {
            return '';
        }

        if ($headerCellCount === 0) {
            $headerCellCount = count($rows[0]['cells']);
            $rows[0]['is_header'] = true;
        }

        $markdownRows = [];
        $headerInserted = false;

        foreach ($rows as $index => $row) {
            $rowLine = '| ' . implode(' | ', $row['cells']) . ' |';
            $markdownRows[] = $rowLine;

            if (!$headerInserted && ($row['is_header'] || $index === 0)) {
                $markdownRows[] = '| ' . implode(' | ', array_fill(0, $headerCellCount, '---')) . ' |';
                $headerInserted = true;
            }
        }

        $markdownTable = implode("\n", $markdownRows);
        $markdownTable = preg_replace('/^[ \t]*\|/m', '|', $markdownTable) ?? $markdownTable;
        $markdownTable = preg_replace('/\|[ \t]*$/m', '|', $markdownTable) ?? $markdownTable;

        return trim($markdownTable);
    }

    private function extractTableCellMarkdown(\DOMElement $cell): string {
        $parts = [];
        foreach ($cell->childNodes as $childNode) {
            $parts[] = $this->extractTableNodeMarkdown($childNode);
        }

        $markdown = implode('', $parts);
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $markdown = preg_replace('/[ \t]+\n/', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/\n{2,}/', "\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function extractTableNodeMarkdown(\DOMNode $node): string {
        if ($node instanceof \DOMText) {
            return $node->wholeText;
        }

        if (!$node instanceof \DOMElement) {
            return '';
        }

        $tagName = strtolower($node->tagName);
        $innerParts = [];
        foreach ($node->childNodes as $childNode) {
            $innerParts[] = $this->extractTableNodeMarkdown($childNode);
        }
        $innerText = implode('', $innerParts);

        switch ($tagName) {
            case 'br':
                return "\n";
            case 'a':
                $href = trim((string) $node->getAttribute('href'));
                $label = trim($innerText);
                if ($href === '' || $label === '') {
                    return $innerText;
                }

                return '[' . $label . '](' . $href . ')';
            case 'strong':
            case 'b':
                $content = trim($innerText);
                return $content === '' ? '' : '**' . $content . '**';
            case 'em':
            case 'i':
                $content = trim($innerText);
                return $content === '' ? '' : '*' . $content . '*';
            case 'sup':
                return '<sup>' . trim($innerText) . '</sup>';
            case 'p':
            case 'div':
                return trim($innerText);
            default:
                return $innerText;
        }
    }

    private function convertInlineHtmlFragmentToMarkdown(string $html): string {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<br\b[^>]*>/i', self::INLINE_BREAK_TOKEN, $html) ?? $html;

        if (!class_exists('\DOMDocument')) {
            $text = wp_strip_all_tags($html, true);
            $text = str_replace(self::INLINE_BREAK_TOKEN, "\n", $text);
            return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        }

        $converter = new HtmlConverter();
        $markdown = $converter->convert('<div>' . $html . '</div>');
        $markdown = preg_replace('/^\s+|\s+$/', '', $markdown) ?? $markdown;
        $markdown = preg_replace('/^<div>|<\/div>$/', '', $markdown) ?? $markdown;
        $markdown = str_replace(self::INLINE_BREAK_TOKEN, "  \n", $markdown);
        $markdown = preg_replace("/[ \t]*\n[ \t]*/", "\n", $markdown) ?? $markdown;
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function getNodeOuterHtml(\DOMNode $node): string {
        if ($node instanceof \DOMText) {
            return $node->wholeText;
        }

        $document = $node->ownerDocument;
        if (!$document instanceof \DOMDocument) {
            return '';
        }

        $html = $document->saveHTML($node);
        return is_string($html) ? $html : '';
    }

    private function preserveSuperscriptLinks(string $content): string {
        return preg_replace_callback(
            '/<a\b[^>]*href=("|\')[^"\']+\1[^>]*>.*?<sup\b[^>]*>.*?<\/sup>.*?<\/a>/is',
            function (array $matches): string {
                $html = isset($matches[0]) ? (string) $matches[0] : '';
                if ($html === '') {
                    return '';
                }

                $token = 'GEWEBHTMLLINKTOKEN' . count($this->preservedSuperscriptLinks) . 'END';
                $this->preservedSuperscriptLinks[$token] = $html;

                return $token;
            },
            $content
        ) ?? $content;
    }

    private function preserveHeadingLinks(string $content): string {
        return preg_replace_callback(
            '/<h([1-6])\b([^>]*)>(.*?)<\/h\1>/is',
            function (array $matches): string {
                $level = isset($matches[1]) ? (string) $matches[1] : '';
                $attributes = isset($matches[2]) ? (string) $matches[2] : '';
                $innerHtml = isset($matches[3]) ? (string) $matches[3] : '';
                if ($level === '') {
                    return (string) ($matches[0] ?? '');
                }

                $replacedInnerHtml = preg_replace_callback(
                    '/<a\b[^>]*href=("|\')([^"\']+)\1[^>]*>(.*?)<\/a>/is',
                    function (array $linkMatches): string {
                        $href = isset($linkMatches[2]) ? trim((string) $linkMatches[2]) : '';
                        $labelHtml = isset($linkMatches[3]) ? (string) $linkMatches[3] : '';
                        $label = trim($this->convertInlineHtmlFragmentToMarkdown($labelHtml));
                        if ($href === '' || $label === '') {
                            return (string) ($linkMatches[0] ?? '');
                        }

                        $token = 'GEWEBHEADINGLINKTOKEN' . count($this->preservedHeadingLinks) . 'END';
                        $this->preservedHeadingLinks[$token] = '[' . $label . '](' . $href . ')';

                        return $token;
                    },
                    $innerHtml
                ) ?? $innerHtml;

                return '<h' . $level . $attributes . '>' . $replacedInnerHtml . '</h' . $level . '>';
            },
            $content
        ) ?? $content;
    }

    private function preserveElementIds(string $content): string {
        return preg_replace_callback(
            '/<([a-zA-Z][a-zA-Z0-9:-]*)([^>]*)\sid=("|\')([^"\']+)\3([^>]*)>/',
            function (array $matches): string {
                $tagName = isset($matches[1]) ? (string) $matches[1] : '';
                $beforeId = isset($matches[2]) ? (string) $matches[2] : '';
                $idValue = isset($matches[4]) ? trim((string) $matches[4]) : '';
                $afterId = isset($matches[5]) ? (string) $matches[5] : '';

                if ($tagName === '' || $idValue === '') {
                    return (string) ($matches[0] ?? '');
                }

                $marker = self::ANCHOR_MARKER_PREFIX . $this->encodeAnchorId($idValue) . self::MARKER_SUFFIX;
                return $marker . '<' . $tagName . $beforeId . $afterId . '>';
            },
            $content
        ) ?? $content;
    }

    private function restoreAnchorMarkers(string $markdown): string {
        $pattern = '/GEWEB\\\\?_ID\\\\?_ANCHOR(?:~~|\\\\~\\\\~|__|\\\\_\\\\_)((?:[A-Za-z0-9\-]|_|\\\\_)+)(?:~~|\\\\~\\\\~|__|\\\\_\\\\_)/';

        return preg_replace_callback(
            $pattern,
            function (array $matches): string {
                $encodedId = isset($matches[1]) ? str_replace('\\_', '_', (string) $matches[1]) : '';
                $decodedId = $this->decodeAnchorId($encodedId);
                if ($decodedId === '') {
                    return '';
                }

                return '<a id="' . esc_attr($decodedId) . '"></a>';
            },
            $markdown
        ) ?? $markdown;
    }

    private function restoreSuperscriptLinkTokens(string $markdown): string {
        if ($markdown === '' || empty($this->preservedSuperscriptLinks)) {
            return $markdown;
        }

        return str_replace(
            array_keys($this->preservedSuperscriptLinks),
            array_values($this->preservedSuperscriptLinks),
            $markdown
        );
    }

    private function restoreHeadingLinkTokens(string $markdown): string {
        if ($markdown === '' || empty($this->preservedHeadingLinks)) {
            return $markdown;
        }

        return str_replace(
            array_keys($this->preservedHeadingLinks),
            array_values($this->preservedHeadingLinks),
            $markdown
        );
    }

    private function restoreTableTokens(string $markdown): string {
        if ($markdown === '' || empty($this->preservedTables)) {
            return $markdown;
        }

        $normalizedMarkdown = preg_replace_callback(
            '/' . self::TABLE_TOKEN_PREFIX . '(\d+)' . self::TABLE_TOKEN_SUFFIX . '/',
            function (array $matches): string {
                $token = self::TABLE_TOKEN_PREFIX . (string) ($matches[1] ?? '') . self::TABLE_TOKEN_SUFFIX;
                return $this->preservedTables[$token] ?? $token;
            },
            $markdown
        ) ?? $markdown;

        if ($normalizedMarkdown !== $markdown) {
            return $normalizedMarkdown;
        }

        return str_replace(
            array_keys($this->preservedTables),
            array_values($this->preservedTables),
            $markdown
        );
    }

    private function encodeAnchorId(string $id): string {
        return rtrim(strtr(base64_encode($id), '+/', '-_'), '=');
    }

    private function decodeAnchorId(string $encodedId): string {
        $normalized = strtr($encodedId, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        return is_string($decoded) ? $decoded : '';
    }
}
