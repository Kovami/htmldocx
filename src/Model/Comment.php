<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

use DateTimeImmutable;

/**
 * A reviewer's comment. The text it is about lies between the
 * {@see CommentStart} and {@see CommentEnd} carrying its id.
 */
final readonly class Comment
{
    /**
     * @param  list<Block>  $blocks
     * @param  int|null  $parentId  the comment this one replies to
     * @param  bool  $resolved  marked as done
     */
    public function __construct(
        public int $id,
        public array $blocks,
        public ?string $author = null,
        public ?string $initials = null,
        public ?DateTimeImmutable $date = null,
        public ?int $parentId = null,
        public bool $resolved = false,
    ) {}
}
