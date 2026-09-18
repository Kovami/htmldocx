<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

use Dom\Element;
use Dom\HTMLDocument;
use Throwable;

/**
 * A single complex selector. Matching is delegated to the native HTML5 DOM
 * (`Dom\Element::matches`), so combinators, attribute selectors and
 * structural pseudo-classes work exactly as in a browser. This class only
 * adds what the DOM does not expose: validation and specificity.
 */
final readonly class Selector
{
    /**
     * @param  array{0: int, 1: int, 2: int}  $specificity  [ids, classes/attributes/pseudo-classes, types]
     */
    private function __construct(
        public string $text,
        public array $specificity,
    ) {}

    /**
     * Returns null for selectors that can never match document content
     * (pseudo-elements) or that the DOM engine rejects.
     */
    public static function parse(string $text): ?self
    {
        $text = trim($text);

        if ($text === '' || preg_match('/::|:(?:before|after|first-line|first-letter)\b/i', $text)) {
            return null;
        }

        try {
            self::probe()->matches($text);
        } catch (Throwable) {
            return null;
        }

        return new self($text, self::specificityOf($text));
    }

    public function matches(Element $element): bool
    {
        try {
            return $element->matches($this->text);
        } catch (Throwable) {
            return false;
        }
    }

    public function compareSpecificity(self $other): int
    {
        return $this->specificity <=> $other->specificity;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function specificityOf(string $selector): array
    {
        $s = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '""', $selector);
        $s = (string) preg_replace('/:where\((?:[^()]|\([^()]*\))*\)/i', ' ', $s);
        $s = (string) preg_replace('/:(?:not|is|has)\(/i', ' (', $s);

        $attributes = preg_match_all('/\[[^\]]*\]/', $s);
        $s = (string) preg_replace('/\[[^\]]*\]/', ' ', $s);

        $ids = preg_match_all('/#[\w-]+/', $s);
        $classes = preg_match_all('/\.[\w-]+/', $s);
        $pseudoClasses = preg_match_all('/:[\w-]+(?:\([^()]*\))?/', $s);
        $s = (string) preg_replace('/[#.][\w-]+|:[\w-]+(?:\([^()]*\))?/', ' ', $s);

        $types = preg_match_all('/(?:^|[\s>+~(,])[a-zA-Z][\w-]*/', $s);

        return [(int) $ids, (int) $classes + (int) $attributes + (int) $pseudoClasses, (int) $types];
    }

    private static function probe(): Element
    {
        static $probe = null;

        return $probe ??= HTMLDocument::createFromString('<p></p>', LIBXML_NOERROR)->body->firstElementChild;
    }
}
