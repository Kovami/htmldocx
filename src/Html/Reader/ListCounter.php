<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Model\NumberingReference;

/**
 * The list-item counter of one `<ul>`/`<ol>`. Word numbers a definition's
 * paragraphs itself, so items share a definition while that numbering
 * matches HTML's; an item that breaks the sequence (`value`, `reversed`,
 * its own list-style-type, or a marker Word cannot count) starts a new one.
 */
final class ListCounter
{
    private ?int $numId = null;

    private ?string $numberedStyleType = null;

    private function __construct(
        private readonly NumberingRegistry $numbering,
        public readonly int $level,
        private readonly bool $ordered,
        private readonly int $step,
        private int $next,
        private readonly int $indentLeft,
        private readonly int $hanging,
    ) {}

    public static function for(Element $list, NumberingRegistry $numbering, int $level, int $indentLeft, int $hanging): self
    {
        $ordered = $list->localName === 'ol';
        $reversed = $ordered && $list->hasAttribute('reversed');
        $start = $ordered ? self::integer($list->getAttribute('start')) : null;

        if ($start === null && $reversed) {
            $start = count(array_filter(
                iterator_to_array($list->children),
                static fn(Element $child): bool => $child->localName === 'li',
            ));
        }

        return new self($numbering, $level, $ordered, $reversed ? -1 : 1, $start ?? 1, $indentLeft, $hanging);
    }

    public function markerFor(Element $item, ComputedStyle $style): ListMarker
    {
        $value = ($this->ordered && $item->localName === 'li' ? self::integer($item->getAttribute('value')) : null) ?? $this->next;
        $type = $style->listStyleType;

        if ($this->numId === null || $value !== $this->next || $type !== $this->numberedStyleType
            || $this->step < 0 || NumberingRegistry::isLiteral($type)) {
            $this->numId = $this->numbering->register($type, $this->level, $value, $this->indentLeft, $this->hanging);
            $this->numberedStyleType = $type;
        }

        $this->next = $value + $this->step;

        return new ListMarker(new NumberingReference($this->numId, $this->level));
    }

    private static function integer(?string $value): ?int
    {
        return $value !== null && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1 ? (int) $value : null;
    }
}
