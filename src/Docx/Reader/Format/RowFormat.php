<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader\Format;

/** Row formatting (`w:trPr`). */
final readonly class RowFormat
{
    /**
     * @param  int|null  $height  twips
     * @param  string|null  $heightRule  atLeast, exact or auto
     */
    public function __construct(
        public ?int $height = null,
        public ?string $heightRule = null,
        public ?bool $header = null,
        public ?int $gridBefore = null,
        public ?int $gridAfter = null,
        public ?bool $deleted = null,
    ) {}
}
