<?php

declare(strict_types=1);

namespace League\HTMLToMarkdown\Converter;

use League\HTMLToMarkdown\ElementInterface;

class PreformattedConverter implements ConverterInterface
{
    public function convert(ElementInterface $element): string
    {
        $preContent = \html_entity_decode($element->getChildrenAsString(), ENT_QUOTES, 'UTF-8');

        /*
         * Checking for the code tag.
         * Usually pre tags are used along with code tags. This conditional will check for already converted code tags,
         * which use backticks, and if those backticks are at the beginning and at the end of the string it means
         * there's no more information to convert.
         */

        $firstBacktick = \strpos(\trim($preContent), '`');
        $lastBacktick  = \strrpos(\trim($preContent), '`');
        if ($firstBacktick === 0 && $lastBacktick === \strlen(\trim($preContent)) - 1) {
            return $preContent . "\n\n";
        }

        // If the execution reaches this point it means it's just a pre tag, with no code tag nested

        // Empty lines are a special case
        if ($preContent === '') {
            return "```\n```\n\n";
        }

        // Normalizing new lines
        $preContent = \preg_replace('/\r\n|\r|\n/', "\n", $preContent);
        \assert(\is_string($preContent));

        // Ensure there's a newline at the end
        if (\strrpos($preContent, "\n") !== \strlen($preContent) - \strlen("\n")) {
            $preContent .= "\n";
        }

        // Find the longest sequence of backticks to dynamically size the wrapper
        \preg_match_all('/`+/', $preContent, $matches);
        $maxBackticks = 2; // Ensure at least 3 backticks
        foreach ($matches[0] as $match) {
            $len = \strlen($match);
            if ($len > $maxBackticks) {
                $maxBackticks = $len;
            }
        }

        $wrapper = \str_repeat('`', $maxBackticks + 1);
        return $wrapper . "\n" . $preContent . $wrapper . "\n\n";
    }

    /**
     * @return string[]
     */
    public function getSupportedTags(): array
    {
        return ['pre'];
    }
}
