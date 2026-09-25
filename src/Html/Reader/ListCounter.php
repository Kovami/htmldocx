<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\Length;
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

    private bool $symbol = false;

    private ?int $markerTab = null;

    /** The value the current definition numbers next. */
    private ?int $continues = null;

    private function __construct(
        private readonly NumberingRegistry $numbering,
        public readonly int $level,
        private readonly bool $ordered,
        private readonly int $step,
        private int $next,
        private readonly int $indentLeft,
        public readonly int $hanging,
        private readonly string $suffix = 'tab',
    ) {}

    /**
     * @param  string  $suffix  what follows the marker (ListLevel::$suffix): a space when the list sets its markers inside the first line
     */
    public static function for(Element $list, NumberingRegistry $numbering, int $level, int $indentLeft, int $hanging, string $suffix = 'tab'): self
    {
        $ordered = $list->localName === 'ol';
        $reversed = $ordered && $list->hasAttribute('reversed');
        $start = $ordered ? self::integer($list->getAttribute('start')) : null;

        if ($start === null && $reversed) {
            $start = count(array_filter(
                HtmlDocument::elementChildren($list),
                static fn(Element $child): bool => $child->localName === 'li',
            ));
        }

        return new self($numbering, $level, $ordered, $reversed ? -1 : 1, $start ?? 1, $indentLeft, $hanging, $suffix);
    }

    public function markerFor(Element $item, ComputedStyle $style): ListMarker
    {
        $value = ($this->ordered && $item->localName === 'li' ? self::integer($item->getAttribute('value')) : null) ?? $this->next;
        $type = $style->listStyleType;
        $this->next = $value + $this->step;

        return new ListMarker(fn(bool $symbol): NumberingReference => $this->reference($value, $type, $symbol, $style->fontSizePt));
    }

    /**
     * Numbers the item once its first paragraph takes the marker.
     *
     * @param  bool  $symbol  whether the paragraph carries HtmlWriter's padding for Word's Symbol bullet
     */
    private function reference(int $value, string $type, bool $symbol, float $fontSizePt): NumberingReference
    {
        $symbol = $symbol && $type === 'disc';
        // A browser draws a disc, circle or square inside the line as a shape and starts the text
        // 1.3125em + 0.64pt after it (measured in Chromium, whatever the font); Word's space after
        // its bullet is far narrower, so a tab of the level's own stands in for it.
        $markerTab = $this->suffix === 'space' && in_array($type, ['disc', 'circle', 'square'], true)
            ? $this->indentLeft + Length::pointsToTwips(1.3125 * $fontSizePt + 0.64)
            : null;

        if ($this->numId === null || $value !== $this->continues || $type !== $this->numberedStyleType || $symbol !== $this->symbol
            || $markerTab !== $this->markerTab || $this->step < 0 || NumberingRegistry::isLiteral($type)) {
            $this->numId = $this->numbering->register($type, $this->level, $value, $this->indentLeft, $this->hanging, $markerTab === null ? $this->suffix : 'tab', $symbol, $markerTab);
            $this->numberedStyleType = $type;
            $this->symbol = $symbol;
            $this->markerTab = $markerTab;
        }

        $this->continues = $value + $this->step;

        return new NumberingReference($this->numId, $this->level);
    }

    private static function integer(?string $value): ?int
    {
        return $value !== null && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1 ? (int) $value : null;
    }
}
