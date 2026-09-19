<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\Table;

/**
 * Final structural fixes on a sequence of blocks:
 * - separates consecutive tables, which Word would otherwise merge into one;
 * - moves table margins onto the neighbouring paragraphs, as tables have no spacing;
 * - guarantees a trailing paragraph, which table cells require.
 */
final class BlockNormalizer
{
    /**
     * @param  list<Block>  $blocks
     * @param  RunProperties|null  $trailingMark  formatting of an added trailing paragraph; null makes it minimal
     * @return list<Block>
     */
    public static function normalize(array $blocks, ?RunProperties $trailingMark = null): array
    {
        $result = [];
        $previous = null;

        foreach ($blocks as $block) {
            if ($block instanceof Table && $previous instanceof Table) {
                $separator = self::emptyParagraph(null);
                $separator->properties->spacingBefore = max($previous->marginBottom, $block->marginTop);
                $result[] = $separator;
            } elseif ($block instanceof Table && $previous instanceof Paragraph && $block->marginTop > 0) {
                $previous->properties->spacingAfter = max($previous->properties->spacingAfter ?? 0, $block->marginTop);
            } elseif ($block instanceof Paragraph && $previous instanceof Table && $previous->marginBottom > 0) {
                $block->properties->spacingBefore = max($block->properties->spacingBefore ?? 0, $previous->marginBottom);
            }

            $result[] = $block;
            $previous = $block;
        }

        if ($previous instanceof Table) {
            $trailing = self::emptyParagraph(null);
            $trailing->properties->spacingBefore = $previous->marginBottom;
            $result[] = $trailing;
        } elseif ($previous === null) {
            $result[] = self::emptyParagraph($trailingMark);
        }

        return $result;
    }

    private static function emptyParagraph(?RunProperties $mark): Paragraph
    {
        if ($mark !== null) {
            return new Paragraph(new ParagraphProperties(markRunProperties: $mark));
        }

        return new Paragraph(new ParagraphProperties(
            spacingBefore: 0,
            spacingAfter: 0,
            lineSpacing: 240,
            lineRule: 'auto',
            markRunProperties: new RunProperties(size: 2),
        ));
    }
}
