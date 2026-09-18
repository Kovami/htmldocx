<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Css\ComputedStyle;

/**
 * Inline formatting context of one block container: the paragraph being
 * accumulated, the style anonymous paragraphs take, and the active link.
 */
final class InlineFlow
{
    public ?InlineBuffer $buffer = null;

    public ?LinkTarget $link = null;

    public function __construct(
        public readonly ComputedStyle $style,
        public readonly BlockContext $context,
    ) {}

    public function buffer(): InlineBuffer
    {
        return $this->buffer ??= new InlineBuffer;
    }
}
