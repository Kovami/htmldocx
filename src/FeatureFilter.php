<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx;

use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Bookmark;
use Kovami\HtmlDocx\Model\CommentEnd;
use Kovami\HtmlDocx\Model\CommentStart;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\Formula;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\NoteReference;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\Table;

/**
 * Drops what the options switch off from a document just read, whole and
 * with its text: pictures, tables, list items, links, formulas, notes,
 * comments, headers and footers. Both directions write from the model, so
 * a feature switched off is gone from either.
 *
 * @internal
 */
final readonly class FeatureFilter
{
    private function __construct(private Options $options) {}

    public static function apply(Document $document, Options $options): Document
    {
        if ($options->includeImages && $options->includeTables && $options->includeLists && $options->includeLinks
            && $options->includeFormulas && $options->includeNotes && $options->includeComments && $options->includeHeadersFooters) {
            return $document;
        }

        $filter = new self($options);

        return self::copy($document, [
            'blocks' => $filter->blocks($document->blocks),
            'notes' => $options->includeNotes ? array_map(static fn(object $note): object => self::copy($note, ['blocks' => $filter->blocks($note->blocks)]), $document->notes) : [],
            'headersFooters' => $options->includeHeadersFooters ? array_map(static fn(object $part): object => self::copy($part, ['blocks' => $filter->blocks($part->blocks)]), $document->headersFooters) : [],
            'comments' => $options->includeComments ? $document->comments : [],
        ]);
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<Block>
     */
    private function blocks(array $blocks): array
    {
        $kept = [];

        foreach ($blocks as $block) {
            if ($block instanceof Table) {
                if ($this->options->includeTables) {
                    $kept[] = self::copy($block, ['rows' => array_map(fn(object $row): object => self::copy($row, [
                        'cells' => array_map(fn(object $cell): object => self::copy($cell, ['blocks' => $this->cell($cell->blocks)]), $row->cells),
                    ]), $block->rows)]);
                }

                continue;
            }

            if (! $block instanceof Paragraph) {
                $kept[] = $block;

                continue;
            }

            if (! $this->options->includeLists && $block->properties->numbering !== null) {
                continue;
            }

            $children = $this->inlines($block->children);

            // A paragraph that held only what was dropped goes with it.
            if ($children !== $block->children && self::blank($children)) {
                continue;
            }

            $kept[] = $children === $block->children ? $block : new Paragraph($block->properties, $children);
        }

        return $kept;
    }

    /**
     * A cell ends in a paragraph, and Word needs one in every cell.
     *
     * @param  list<Block>  $blocks
     * @return list<Block>
     */
    private function cell(array $blocks): array
    {
        $kept = $this->blocks($blocks);

        return end($kept) instanceof Paragraph ? $kept : [...$kept, new Paragraph()];
    }

    /**
     * @param  list<Inline>  $inlines
     * @return list<Inline>
     */
    private function inlines(array $inlines): array
    {
        $kept = [];

        foreach ($inlines as $inline) {
            $dropped = match (true) {
                $inline instanceof ImageRun => ! $this->options->includeImages,
                $inline instanceof Hyperlink => ! $this->options->includeLinks,
                $inline instanceof Formula => ! $this->options->includeFormulas,
                $inline instanceof NoteReference => ! $this->options->includeNotes,
                $inline instanceof CommentStart, $inline instanceof CommentEnd => ! $this->options->includeComments,
                default => false,
            };

            if ($dropped) {
                continue;
            }

            if ($inline instanceof Hyperlink) {
                $children = $this->inlines($inline->children);
                $inline = $children === $inline->children ? $inline : self::copy($inline, ['children' => $children]);
            }

            $kept[] = $inline;
        }

        return $kept;
    }

    /** @param  list<Inline>  $inlines */
    private static function blank(array $inlines): bool
    {
        return array_filter($inlines, static fn(Inline $inline): bool => ! $inline instanceof Bookmark) === [];
    }

    /**
     * A model object with some of its constructor-promoted properties replaced.
     *
     * @template T of object
     *
     * @param  T  $object
     * @param  array<string, mixed>  $changes
     * @return T
     */
    private static function copy(object $object, array $changes): object
    {
        return new ($object::class)(...[...get_object_vars($object), ...$changes]);
    }
}
