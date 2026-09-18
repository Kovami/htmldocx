<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class TextRun implements Inline
{
    public function __construct(
        public string $text,
        public RunProperties $properties = new RunProperties(),
    ) {}
}
