<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class StyleDefinition
{
    /**
     * @param  RunProperties  $run  fully resolved character formatting of the style
     */
    public function __construct(
        public string $id,
        public string $name,
        public ParagraphProperties $paragraph,
        public RunProperties $run,
        public ?string $basedOn = 'Normal',
        public bool $isDefault = false,
    ) {}
}
