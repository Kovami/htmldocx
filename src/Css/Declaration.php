<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

final readonly class Declaration
{
    public function __construct(
        public string $property,
        public string $value,
        public bool $important = false,
    ) {}
}
