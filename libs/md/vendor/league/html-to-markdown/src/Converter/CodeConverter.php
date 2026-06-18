<?php

declare(strict_types=1);

namespace League\HTMLToMarkdown\Converter;

use League\HTMLToMarkdown\ElementInterface;

class CodeConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $language = '';

        // Checking for language class on the code block
        $classes = $element->getAttribute('class');

        if ($classes) {
            // Since tags can have more than one class, we need to find the one that starts with 'language-'
            $classes = \explode(' ', $classes);
            foreach ($classes as $class) {
                if (\strpos($class, 'language-') === 0) {
                    // Found one, save it as the selected language and stop looping over the classes.
                    $language = \substr($class, 9);
                    break;
                }
            }
        }

        $markdown = '';
        $code     = \html_entity_decode($element->getChildrenAsString(), ENT_QUOTES, 'UTF-8');

        // Checking if it's a code block or span
        if ($this->shouldBeBlock($element, $code)) {
            // Code block detected, newlines will be added in parent
            $markdown .= '```' . $language . "\n" . $code . "\n" . '```';
        } else {
            // One line of code, replacing new lines with spaces
            $code = \preg_replace('/\r\n|\r|\n/', ' ', $code);
            \assert(\is_string($code));

            // Handle backticks inside the inline code to prevent breaking the markdown
            \preg_match_all('/`+/', $code, $matches);
            $maxBackticks = 0;
            foreach ($matches[0] as $match) {
                $len = \strlen($match);
                if ($len > $maxBackticks) {
                    $maxBackticks = $len;
                }
            }

            $wrapper = \str_repeat('`', $maxBackticks + 1);
            if (\strpos($code, '`') === 0 || \substr($code, -1) === '`') {
                $code = ' ' . $code . ' ';
            }

            $markdown .= $wrapper . $code . $wrapper;
        }

        return $markdown;
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['code'];
    }

    private function shouldBeBlock(ElementInterface $element, string $code): bool
    {
        $parent = $element->getParent();
        if ($parent !== null && $parent->getTagName() === 'pre') {
            return true;
        }

        return \preg_match('/[^\s]` `/', $code) === 1;
    }
}
