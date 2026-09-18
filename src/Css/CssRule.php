<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

final readonly class CssRule
{
    /**
     * @param  array<string, Declaration>  $declarations
     */
    public function __construct(
        public Selector $selector,
        public array $declarations,
        public Origin $origin,
        public int $sourceOrder,
    ) {}

    public function comparePrecedence(self $other): int
    {
        return [$this->origin->value, $this->selector->specificity, $this->sourceOrder]
            <=> [$other->origin->value, $other->selector->specificity, $other->sourceOrder];
    }
}
