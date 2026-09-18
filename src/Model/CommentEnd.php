<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** Where the text a comment is about ends. */
final readonly class CommentEnd implements Inline
{
    public function __construct(public int $id) {}
}
