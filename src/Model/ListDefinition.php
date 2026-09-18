<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/**
 * One HTML list. Every list gets its own definition so numbering restarts
 * exactly where HTML restarts it.
 */
final readonly class ListDefinition
{
    /**
     * @param  list<ListLevel>  $levels  all nine levels, ordered
     */
    public function __construct(
        public int $numId,
        public array $levels,
        public int $startLevel,
    ) {}
}
