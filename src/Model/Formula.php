<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** A mathematical formula in LaTeX notation (the dialect KaTeX renders). */
final readonly class Formula implements Inline
{
    public function __construct(
        public string $latex,
        public bool $display = false,
        public RunProperties $properties = new RunProperties(),
    ) {}
}
