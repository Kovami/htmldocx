<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Model\BorderSet;

/**
 * What the enclosing block boxes contribute to a paragraph: the horizontal
 * space left after margins/borders/padding, box borders and background
 * (Word joins identical borders of consecutive paragraphs into one box),
 * the paragraph style, and list state.
 */
final readonly class BlockContext
{
    /**
     * @param  int  $availableWidth  twips
     * @param  int  $indentLeft  twips from the page margin
     * @param  int  $indentRight  twips from the page margin
     */
    public function __construct(
        public int $availableWidth,
        public int $indentLeft = 0,
        public int $indentRight = 0,
        public BorderSet $borders = new BorderSet(),
        public ?string $shading = null,
        public ?string $styleId = null,
        public ?ListCounter $list = null,
        public int $listDepth = 0,
        public ?ListMarker $marker = null,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
