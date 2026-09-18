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

    public function __construct(
        public readonly NumberingReference $reference,
    ) {}
}
