<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class BorderSet
{
    public function __construct(
        public ?Border $top = null,
        public ?Border $left = null,
        public ?Border $bottom = null,
        public ?Border $right = null,
        public ?Border $insideHorizontal = null,
        public ?Border $insideVertical = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->top === null && $this->left === null && $this->bottom === null
            && $this->right === null && $this->insideHorizontal === null && $this->insideVertical === null;
    }

    /** Sides set on $overrides replace the corresponding sides of this set. */
    public function mergedWith(self $overrides): self
    {
        return new self(
            $overrides->top ?? $this->top,
            $overrides->left ?? $this->left,
            $overrides->bottom ?? $this->bottom,
            $overrides->right ?? $this->right,
            $overrides->insideHorizontal ?? $this->insideHorizontal,
            $overrides->insideVertical ?? $this->insideVertical,
        );
    }
}
