<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/**
 * Keeps the promise both writers rely on: every comment has exactly one
 * {@see CommentStart} followed by one {@see CommentEnd}, directly inside
 * paragraphs. Sources are rarely that tidy — a hidden paragraph takes a
 * boundary with it, an editor drops an empty span — so a missing end closes
 * the range where it starts, a missing start opens it where it ends, and a
 * comment with neither is anchored at the start of the first paragraph.
 *
 * Threads are tidied too: a reply to a comment that is gone, or a loop of
 * replies, starts a thread of its own, and a reply is resolved exactly when
 * its thread is — Word keeps that state on the first comment only.
 */
final class CommentRanges
{
    /**
     * @param  list<list<Block>>  $containers  the body first, then note bodies and the like
     * @param  list<Comment>  $comments
     * @return list<Comment> the comments that could be anchored
     */
    public static function balance(array $containers, array $comments): array
    {
        $known = [];

        foreach ($comments as $comment) {
            $known[$comment->id] = true;
        }

        /** @var array<int, Paragraph> $startedIn */
        $startedIn = [];
        $ended = [];
        $firstParagraph = null;

        foreach ($containers as $blocks) {
            foreach (self::paragraphs($blocks) as $paragraph) {
                $firstParagraph ??= $paragraph;
                $children = [];

                foreach ($paragraph->children as $child) {
                    if ($child instanceof CommentStart) {
                        if (! isset($known[$child->id]) || isset($startedIn[$child->id])) {
                            continue;
                        }

                        $startedIn[$child->id] = $paragraph;
                    } elseif ($child instanceof CommentEnd) {
                        if (! isset($known[$child->id]) || isset($ended[$child->id])) {
                            continue;
                        }

                        if (! isset($startedIn[$child->id])) {
                            $startedIn[$child->id] = $paragraph;
                            $children[] = new CommentStart($child->id);
                        }

                        $ended[$child->id] = true;
                    }

                    $children[] = $child;
                }

                $paragraph->children = $children;
            }
        }

        foreach ($startedIn as $id => $paragraph) {
            if (! isset($ended[$id])) {
                self::closeAtStart($paragraph, $id);
            }
        }

        $anchored = [];

        foreach ($comments as $comment) {
            if (! isset($startedIn[$comment->id])) {
                if ($firstParagraph === null) {
                    continue;
                }

                array_unshift($firstParagraph->children, new CommentStart($comment->id), new CommentEnd($comment->id));
            }

            $anchored[$comment->id] = $comment;
        }

        return self::threads($anchored);
    }

    /**
     * @param  array<int, Comment>  $comments  by id
     * @return list<Comment>
     */
    private static function threads(array $comments): array
    {
        $result = [];

        foreach ($comments as $comment) {
            $root = $comment;
            $visited = [$comment->id => true];

            while ($root->parentId !== null && isset($comments[$root->parentId]) && ! isset($visited[$root->parentId])) {
                $root = $comments[$root->parentId];
                $visited[$root->id] = true;
            }

            $parentId = $comment->parentId !== null && isset($comments[$comment->parentId]) && $root->parentId === null
                ? $comment->parentId
                : null;
            $resolved = $parentId === null ? $comment->resolved : $root->resolved;

            $result[] = $parentId === $comment->parentId && $resolved === $comment->resolved
                ? $comment
                : new Comment($comment->id, $comment->blocks, $comment->author, $comment->initials, $comment->date, $parentId, $resolved);
        }

        return $result;
    }

    /**
     * Paragraphs in reading order, looking into table cells.
     *
     * @param  list<Block>  $blocks
     * @return list<Paragraph>
     */
    public static function paragraphs(array $blocks): array
    {
        $paragraphs = [];

        foreach ($blocks as $block) {
            if ($block instanceof Paragraph) {
                $paragraphs[] = $block;
            } elseif ($block instanceof Table) {
                foreach ($block->rows as $row) {
                    foreach ($row->cells as $cell) {
                        array_push($paragraphs, ...self::paragraphs($cell->blocks));
                    }
                }
            }
        }

        return $paragraphs;
    }

    private static function closeAtStart(Paragraph $paragraph, int $id): void
    {
        $children = [];

        foreach ($paragraph->children as $child) {
            $children[] = $child;

            if ($child instanceof CommentStart && $child->id === $id) {
                $children[] = new CommentEnd($id);
            }
        }

        $paragraph->children = $children;
    }
}
