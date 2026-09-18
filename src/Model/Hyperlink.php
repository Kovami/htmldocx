<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** Links either to an external URL or to a bookmark inside the document. */
final readonly class Hyperlink implements Inline
{
    /**
     * @param  list<Inline>  $children  any inline content except hyperlinks
     */
    public function __construct(
        public ?string $url,
        public ?string $anchor,
        public array $children,
    ) {}
}
