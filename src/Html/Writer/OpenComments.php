<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

/**
 * The comments whose ranges are open at the point being written. A range
 * may run over several paragraphs, so this outlives any one of them.
 */
final class OpenComments
{
    /** @var list<int> in the order they opened */
    private array $open = [];

    /** @var array<int, true> open ranges that already cover some content */
    private array $covered = [];

    public function start(int $id): void
    {
        if (! in_array($id, $this->open, true)) {
            $this->open[] = $id;
        }
    }

    /** Closes a range; false when it covered nothing. */
    public function end(int $id): bool
    {
        $covered = isset($this->covered[$id]);
        $this->open = array_values(array_diff($this->open, [$id]));
        unset($this->covered[$id]);

        return $covered;
    }

    /** The `data-comment` value of content written now: open ids, space-separated. */
    public function key(): string
    {
        return implode(' ', $this->open);
    }

    public function cover(): void
    {
        foreach ($this->open as $id) {
            $this->covered[$id] = true;
        }
    }
}
