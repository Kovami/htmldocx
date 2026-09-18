<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** Where the text a comment is about begins; ranges may cross paragraphs. */
final readonly class CommentStart implements Inline
{
    public function __construct(public int $id) {}
}
