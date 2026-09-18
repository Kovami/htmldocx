<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final class Paragraph implements Block
{
    /**
     * @param  list<Inline>  $children
     */
    public function __construct(
        public ParagraphProperties $properties = new ParagraphProperties(),
        public array $children = [],
    ) {}
}
