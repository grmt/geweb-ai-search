<?php
namespace Geweb\AISearch;

defined('ABSPATH') || exit;

use League\HTMLToMarkdown\HtmlConverter;

class HTML2MDDomMarkdownSupport {
    private const DOM_DOCUMENT_CLASS = '\DOMDocument';
    private const INLINE_BREAK_TOKEN = 'GEWEBINLINEBREAKTOKEN';

    public static function convertHtmlListBlockToMarkdown(string $html): string {
        $document = self::loadBodyDocument($html);
        if (!$document instanceof \DOMDocument) {
            return '';
        }

        $rootList = self::findFirstBodyList($document);
        return $rootList instanceof \DOMElement ? trim(self::extractListMarkdown($rootList, 0)) : '';
    }

    public static function convertSingleHtmlTableToMarkdown(string $tableHtml): string {
        $table = self::findFirstTable($tableHtml);
        if (!$table instanceof \DOMElement) {
            return '';
        }

        $rows = self::extractTableRows($table);
        if ($rows === []) {
            return '';
        }

        return self::renderTableRows($rows);
    }

    public static function convertInlineHtmlFragmentToMarkdown(string $html): string {
        if ($html === '') {
            return '';
        }

        $html = preg_replace('/<br\b[^>]*>/i', self::INLINE_BREAK_TOKEN, $html) ?? $html;

        if (!class_exists(self::DOM_DOCUMENT_CLASS)) {
            $text = wp_strip_all_tags($html, true);
            $text = str_replace(self::INLINE_BREAK_TOKEN, "\n", $text);
            return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        }

        $converter = new HtmlConverter();
        $markdown = $converter->convert('<div>' . $html . '</div>');
        $markdown = trim($markdown);
        $markdown = preg_replace('/(?:^<div>|<\/div>$)/', '', $markdown) ?? $markdown;
        $markdown = str_replace(self::INLINE_BREAK_TOKEN, "  \n", $markdown);
        $markdown = preg_replace("/[ \t]*\n[ \t]*/", "\n", $markdown) ?? $markdown;
        $markdown = preg_replace(HTML2MD::REGEX_MULTIPLE_BLANK_LINES, "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private static function loadBodyDocument(string $html): ?\DOMDocument {
        if (!class_exists(self::DOM_DOCUMENT_CLASS)) {
            return null;
        }

        $document = new \DOMDocument();
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        return $loaded === true ? $document : null;
    }

    private static function findFirstBodyList(\DOMDocument $document): ?\DOMElement {
        foreach ($document->getElementsByTagName('body') as $body) {
            foreach ($body->childNodes as $childNode) {
                if ($childNode instanceof \DOMElement && in_array(strtolower($childNode->tagName), ['ol', 'ul'], true)) {
                    return $childNode;
                }
            }
        }

        return null;
    }

    private static function extractListMarkdown(\DOMElement $listElement, int $depth): string {
        $isOrdered = strtolower($listElement->tagName) === 'ol';
        $lines = [];
        $index = 1;

        foreach ($listElement->childNodes as $childNode) {
            if (!$childNode instanceof \DOMElement || strtolower($childNode->tagName) !== 'li') {
                continue;
            }

            $lines = array_merge($lines, self::extractListItemLines($childNode, $depth, $isOrdered, $index));
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int,string>
     */
    private static function extractListItemLines(\DOMElement $itemElement, int $depth, bool $isOrdered, int &$index): array {
        $inlineHtml = '';
        $nestedLists = [];

        foreach ($itemElement->childNodes as $itemChild) {
            if ($itemChild instanceof \DOMElement && in_array(strtolower($itemChild->tagName), ['ol', 'ul'], true)) {
                $nestedLists[] = self::extractListMarkdown($itemChild, $depth + 1);
                continue;
            }

            $inlineHtml .= self::getNodeOuterHtml($itemChild);
        }

        $lines = [];
        $itemMarkdown = trim(self::convertInlineHtmlFragmentToMarkdown($inlineHtml));
        if ($itemMarkdown !== '') {
            $indent = str_repeat('  ', $depth);
            $prefix = $isOrdered ? ($index++) . '. ' : '- ';
            $lines[] = $indent . $prefix . str_replace("\n", "\n" . $indent . '  ', $itemMarkdown);
        }

        foreach ($nestedLists as $nestedList) {
            $nestedList = trim($nestedList);
            if ($nestedList !== '') {
                $lines[] = $nestedList;
            }
        }

        return $lines;
    }

    private static function findFirstTable(string $tableHtml): ?\DOMElement {
        $document = self::loadBodyDocument($tableHtml);
        if (!$document instanceof \DOMDocument) {
            return null;
        }

        $table = $document->getElementsByTagName('table')->item(0);
        return $table instanceof \DOMElement ? $table : null;
    }

    /**
     * @return array<int,array{cells:array<int,string>,is_header:bool}>
     */
    private static function extractTableRows(\DOMElement $table): array {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $rowElement) {
            if ($rowElement instanceof \DOMElement) {
                $row = self::extractTableRow($rowElement);
                if ($row['cells'] !== []) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows !== [] && !array_filter($rows, static fn(array $row): bool => $row['is_header'])) {
            $rows[0]['is_header'] = true;
        }

        return $rows;
    }

    /**
     * @return array{cells:array<int,string>,is_header:bool}
     */
    private static function extractTableRow(\DOMElement $rowElement): array {
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

            $rowHasHeader = $rowHasHeader || $tagName === 'th';
            $cellMarkdown = trim(self::extractTableCellMarkdown($cellNode));
            $cellMarkdown = preg_replace('/\s*\n\s*/', ' <br> ', $cellMarkdown) ?? $cellMarkdown;
            $cellMarkdown = preg_replace('/\s{2,}/', ' ', $cellMarkdown) ?? $cellMarkdown;
            $cells[] = str_replace('|', '\|', $cellMarkdown);
        }

        return [
            'cells' => $cells,
            'is_header' => $rowHasHeader,
        ];
    }

    /**
     * @param array<int,array{cells:array<int,string>,is_header:bool}> $rows
     */
    private static function renderTableRows(array $rows): string {
        $headerCellCount = count($rows[0]['cells']);
        foreach ($rows as $row) {
            if ($row['is_header']) {
                $headerCellCount = count($row['cells']);
                break;
            }
        }

        $markdownRows = [];
        $headerInserted = false;
        foreach ($rows as $index => $row) {
            $markdownRows[] = '| ' . implode(' | ', $row['cells']) . ' |';
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

    private static function extractTableCellMarkdown(\DOMElement $cell): string {
        $parts = [];
        foreach ($cell->childNodes as $childNode) {
            $parts[] = self::extractTableNodeMarkdown($childNode);
        }

        $markdown = implode('', $parts);
        $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $markdown = preg_replace('/[ \t]+\n/', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/\n{2,}/', "\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private static function extractTableNodeMarkdown(\DOMNode $node): string {
        $markdown = '';
        if ($node instanceof \DOMText) {
            $markdown = $node->wholeText;
        } elseif ($node instanceof \DOMElement) {
            $markdown = self::formatTableElementMarkdown($node);
        }

        return $markdown;
    }

    private static function formatTableElementMarkdown(\DOMElement $node): string {
        $tagName = strtolower($node->tagName);
        $innerText = self::getInnerMarkdown($node);
        $markdown = $innerText;

        if ($tagName === 'br') {
            $markdown = "\n";
        } elseif ($tagName === 'a') {
            $href = trim((string) $node->getAttribute('href'));
            $label = trim($innerText);
            $markdown = ($href === '' || $label === '') ? $innerText : '[' . $label . '](' . $href . ')';
        } elseif ($tagName === 'strong' || $tagName === 'b') {
            $content = trim($innerText);
            $markdown = $content === '' ? '' : '**' . $content . '**';
        } elseif ($tagName === 'em' || $tagName === 'i') {
            $content = trim($innerText);
            $markdown = $content === '' ? '' : '*' . $content . '*';
        } elseif ($tagName === 'sup') {
            $markdown = '<sup>' . trim($innerText) . '</sup>';
        } elseif ($tagName === 'p' || $tagName === 'div') {
            $markdown = trim($innerText);
        }

        return $markdown;
    }

    private static function getInnerMarkdown(\DOMElement $node): string {
        $innerParts = [];
        foreach ($node->childNodes as $childNode) {
            $innerParts[] = self::extractTableNodeMarkdown($childNode);
        }

        return implode('', $innerParts);
    }

    private static function getNodeOuterHtml(\DOMNode $node): string {
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
}
