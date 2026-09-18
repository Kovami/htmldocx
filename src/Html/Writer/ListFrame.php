<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;

/** One open `ul`/`ol` while blocks are written, and the counter it is at. */
final class ListFrame
{
    public ?Element $item = null;

    public ?ComputedStyle $itemStyle = null;

    /**
     * @param  int  $level  numbering level, 0-based
     * @param  string|null  $marker  CSS `list-style-type`, or null when every item spells its own marker out
     * @param  int  $numId  numbering definition the last item came from
     * @param  int  $next  counter value the next item continues at
     * @param  int  $indent  where the items' text starts, in twips from the page margin
     */
    public function __construct(
        public readonly int $level,
        public readonly string $tag,
        public readonly ?string $marker,
        public int $numId,
        public int $next,
        public readonly int $indent,
        public readonly Element $list,
        public readonly ComputedStyle $style,
    ) {}

    /** Records the item just written and moves the counter past it. */
    public function advance(int $numId, int $ordinal, Element $item, ComputedStyle $style): void
    {
        $this->numId = $numId;
        $this->next = $ordinal + 1;
        $this->attach($item, $style);
    }

    /** Takes an item as the current one without counting it, for the empty item a nested list hangs from. */
    public function attach(Element $item, ComputedStyle $style): void
    {
        $this->item = $item;
        $this->itemStyle = $style;
    }
}
