<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Model\Block;

final class BlockSink
{
    /** @var list<Block> */
    public array $blocks = [];

    public function add(Block $block): void
    {
        $this->blocks[] = $block;
    }

    public function replace(int $index, Block $block): void
    {
        array_splice($this->blocks, $index, 1, [$block]);
    }

    public function count(): int
    {
        return count($this->blocks);
    }
}
