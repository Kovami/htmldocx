<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Kovami\HtmlDocx\Exceptions\HtmlDocxException;

/**
 * The lists open around the paragraph being written, outermost first.
 * Word gives every numbered paragraph a level and a definition; HTML needs
 * the nesting those imply, so lists are opened and closed as the level
 * changes and continued while the marker stays the same.
 */
final class ListStack
{
    /** @var list<ListFrame> */
    private array $frames = [];

    public function isEmpty(): bool
    {
        return $this->frames === [];
    }

    /** The level of the innermost open list, or -1 when none is open. */
    public function depth(): int
    {
        return $this->isEmpty() ? -1 : $this->top()->level;
    }

    public function top(): ListFrame
    {
        $frame = $this->frames[count($this->frames) - 1] ?? null;

        if ($frame === null) {
            throw HtmlDocxException::htmlWriterFailure('no list is open');
        }

        return $frame;
    }

    /** Closes the lists that cannot hold an item of this level and marker. */
    public function closeTo(int $level, string $tag, ?string $marker): void
    {
        while (! $this->isEmpty()) {
            $top = $this->top();

            if ($top->level > $level || ($top->level === $level && ($top->tag !== $tag || $top->marker !== $marker))) {
                array_pop($this->frames);

                continue;
            }

            break;
        }
    }

    public function open(ListFrame $frame): void
    {
        $this->frames[] = $frame;
    }

    public function clear(): void
    {
        $this->frames = [];
    }
}
