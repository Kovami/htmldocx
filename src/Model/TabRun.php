<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class TabRun implements Inline
{
    public function __construct(
        public RunProperties $properties = new RunProperties(),
    ) {}
}
