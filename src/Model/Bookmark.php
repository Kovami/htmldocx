<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** A zero-length bookmark: a named position hyperlinks can jump to. */
final readonly class Bookmark implements Inline
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
