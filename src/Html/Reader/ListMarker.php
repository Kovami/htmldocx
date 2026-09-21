<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Model\NumberingReference;

/**
 * The bullet/number of one `<li>`. It is shared by every block inside the
 * item and consumed by the first paragraph actually emitted, so an item
 * like `<li><p>a</p><p>b</p></li>` gets exactly one marker.
 */
final class ListMarker
{
    public bool $consumed = false;

    /**
     * @param  \Closure(bool): NumberingReference  $number  numbers the item, told whether its bullet is Word's Symbol one
     */
    public function __construct(
        private readonly \Closure $number,
    ) {}

    public function reference(bool $symbol): NumberingReference
    {
        $this->consumed = true;

        return ($this->number)($symbol);
    }
}
