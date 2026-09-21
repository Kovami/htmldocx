<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Closure;
use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Css\StyleResolver;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\BorderSet;
use Kovami\HtmlDocx\Model\CellMargins;
use Kovami\HtmlDocx\Model\CellProperties;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\Table;
use Kovami\HtmlDocx\Model\TableCell;
use Kovami\HtmlDocx\Model\TableProperties;
use Kovami\HtmlDocx\Model\TableRow;

/**
 * Applies the HTML table model (row groups, colspan/rowspan slot
 * allocation, column widths) and produces a rectangular Word grid: spanned
 * columns become gridSpan, spanned rows become vMerge restart/continue
 * cells, and ragged rows are padded with empty cells.
 */
final class TableBuilder
{
    private const int MIN_COLUMN_TWIPS = 360;

    public function __construct(
        private readonly StyleResolver $resolver,
        private readonly PropertyMapper $mapper,
    ) {}

    /**
     * @param  Closure(Element, ComputedStyle, BlockContext): list<Block>  $renderContent
     * @return list<Block> caption paragraphs followed by the table
     */
    public function build(Element $table, ComputedStyle $style, BlockContext $context, Closure $renderContent): array
    {
        $blocks = [];
        $groups = ['thead' => [], 'tbody' => [], 'tfoot' => []];
        $columnStyles = [];

        foreach (HtmlDocument::elementChildren($table) as $child) {
            $tag = $child->localName;
            $childStyle = $this->resolver->resolve($child, $style);

            if ($tag === 'caption') {
                if ($childStyle->display !== 'none') {
                    array_push($blocks, ...$renderContent($child, $childStyle, $context->with(['styleId' => StyleCatalog::CAPTION_STYLE_ID])));
                }
            } elseif ($tag === 'colgroup') {
                $this->collectColumns($child, $childStyle, $columnStyles);
            } elseif ($tag === 'col') {
                $this->addColumn($child, $childStyle, $columnStyles);
            } elseif (isset($groups[$tag]) && $childStyle->display !== 'none') {
                foreach (HtmlDocument::elementChildren($child) as $row) {
                    if ($row->localName === 'tr') {
                        $groups[$tag][] = $this->row($row, $childStyle, $tag === 'thead');
                    }
                }
            } elseif ($tag === 'tr') {
                $groups['tbody'][] = $this->row($child, $style, false);
            }
        }

        $rows = array_values(array_filter(
            [...$groups['thead'], ...$groups['tbody'], ...$groups['tfoot']],
            static fn(array $row): bool => $row['style']->display !== 'none',
        ));

        [$cells, $slots, $columnCount] = $this->allocateSlots($rows);

        if ($columnCount === 0) {
            return $blocks;
        }

        $margin = static fn(string $property): int => Length::pointsToTwips(max(0, $style->lengthPt($property, $context->availableWidth / Length::TWIPS_PER_POINT) ?? 0));
        $marginTop = $margin('margin-top');
        $captionFirst = ($blocks[0] ?? null) instanceof Paragraph;

        if ($captionFirst && $marginTop > 0) {
            $blocks[0]->properties->spacingBefore = max($blocks[0]->properties->spacingBefore ?? 0, $marginTop);
        }

        $natural = $this->naturalWidths($columnCount, $cells);
        $tableWidth = $this->tableWidth($style, $context, $columnStyles === [] && ! $this->anyCellWidth($cells) ? array_sum($natural) : null);
        $widths = $this->columnWidths($columnCount, $tableWidth, $columnStyles, $cells, $natural);
        $tableRows = [];

        foreach ($rows as $r => $row) {
            $tableCells = [];
            $c = 0;

            while ($c < $columnCount) {
                $index = $slots[$r][$c] ?? null;
                $cell = $index === null ? null : $cells[$index];

                if ($cell === null || $cell['col'] !== $c) {
                    $tableCells[] = new TableCell(new CellProperties($widths[$c]), [new Paragraph()]);
                    $c++;

                    continue;
                }

                $span = 1;

                while ($span < $cell['colspan'] && ($slots[$r][$c + $span] ?? null) === $index) {
                    $span++;
                }

                $width = array_sum(array_slice($widths, $c, $span));
                $isOrigin = $cell['row'] === $r;

                $tableCells[] = new TableCell(
                    $this->cellProperties($cell, $row, $width, $span, $isOrigin),
                    $isOrigin ? $this->cellContent($cell, $width, $renderContent) : [new Paragraph()],
                );

                $c += $span;
            }

            $height = $row['style']->lengthPt('height');

            $tableRows[] = new TableRow(
                $tableCells,
                $row['header'],
                $height !== null && $height > 0 ? Length::pointsToTwips($height) : null,
            );
        }

        $blocks[] = new Table(
            new TableProperties(
                width: $tableWidth,
                alignment: match (true) {
                    $style->isAuto('margin-left') && $style->isAuto('margin-right') => 'center',
                    $style->isAuto('margin-left') => 'right',
                    default => null,
                },
                indentLeft: $context->indentLeft + Length::pointsToTwips(max(0, $style->lengthPt('margin-left') ?? 0)),
                borders: new BorderSet(
                    top: $this->mapper->border($style->border('top')),
                    left: $this->mapper->border($style->border('left')),
                    bottom: $this->mapper->border($style->border('bottom')),
                    right: $this->mapper->border($style->border('right')),
                ),
                shading: $style->backgroundColor(),
                bidi: $style->direction === 'rtl',
            ),
            $widths,
            $tableRows,
            $captionFirst ? 0 : $marginTop,
            $margin('margin-bottom'),
        );

        return $blocks;
    }

    /**
     * @return array{element: Element, style: ComputedStyle, background: string|null, header: bool}
     */
    private function row(Element $row, ComputedStyle $groupStyle, bool $isHeader): array
    {
        $style = $this->resolver->resolve($row, $groupStyle);

        return [
            'element' => $row,
            'style' => $style,
            'background' => $style->backgroundColor() ?? $groupStyle->backgroundColor(),
            'header' => $isHeader,
        ];
    }

    /**
     * @param  list<ComputedStyle>  $columnStyles
     */
    private function collectColumns(Element $group, ComputedStyle $groupStyle, array &$columnStyles): void
    {
        $hasColumns = false;

        foreach (HtmlDocument::elementChildren($group) as $column) {
            if ($column->localName === 'col') {
                $hasColumns = true;
                $this->addColumn($column, $this->resolver->resolve($column, $groupStyle), $columnStyles);
            }
        }

        if (! $hasColumns) {
            $this->addColumn($group, $groupStyle, $columnStyles);
        }
    }

    /**
     * @param  list<ComputedStyle>  $columnStyles
     */
    private function addColumn(Element $column, ComputedStyle $style, array &$columnStyles): void
    {
        $span = max(1, min(1000, (int) ($column->getAttribute('span') ?: 1)));

        for ($i = 0; $i < $span; $i++) {
            $columnStyles[] = $style;
        }
    }

    /**
     * @param  list<array{element: Element, style: ComputedStyle, background: string|null, header: bool}>  $rows
     * @return array{0: list<array{element: Element, style: ComputedStyle, row: int, col: int, colspan: int, rowspan: int}>, 1: array<int, array<int, int>>, 2: int}
     */
    private function allocateSlots(array $rows): array
    {
        $cells = [];
        $slots = [];
        $columnCount = 0;
        $rowCount = count($rows);

        foreach ($rows as $r => $row) {
            $c = 0;

            foreach (HtmlDocument::elementChildren($row['element']) as $cellElement) {
                if ($cellElement->localName !== 'td' && $cellElement->localName !== 'th') {
                    continue;
                }

                $cellStyle = $this->resolver->resolve($cellElement, $row['style']);

                while (isset($slots[$r][$c])) {
                    $c++;
                }

                $colspan = max(1, min(1000, (int) ($cellElement->getAttribute('colspan') ?: 1)));
                $rowspanAttribute = trim((string) $cellElement->getAttribute('rowspan'));
                $rowspan = $rowspanAttribute === '0'
                    ? $rowCount - $r
                    : max(1, min($rowCount - $r, (int) ($rowspanAttribute ?: 1)));

                $index = count($cells);
                $cells[] = [
                    'element' => $cellElement,
                    'style' => $cellStyle,
                    'row' => $r,
                    'col' => $c,
                    'colspan' => $colspan,
                    'rowspan' => $rowspan,
                ];

                for ($dr = 0; $dr < $rowspan; $dr++) {
                    for ($dc = 0; $dc < $colspan; $dc++) {
                        $slots[$r + $dr][$c + $dc] = $index;
                    }
                }

                $c += $colspan;
                $columnCount = max($columnCount, $c);
            }
        }

        return [$cells, $slots, $columnCount];
    }

    /**
     * @param  int|null  $content  how wide the content would lay the table out, when nothing sizes its columns
     */
    private function tableWidth(ComputedStyle $style, BlockContext $context, ?int $content): int
    {
        $width = $style->lengthPt('width', $context->availableWidth / Length::TWIPS_PER_POINT);

        if ($width === null || $width <= 0) {
            // A browser makes a table without a width as wide as its content, up to the page.
            return $content === null ? $context->availableWidth : min($context->availableWidth, $content);
        }

        return min($context->availableWidth, Length::pointsToTwips($width));
    }

    /**
     * @param  list<ComputedStyle>  $columnStyles
     * @param  list<array{element: Element, style: ComputedStyle, row: int, col: int, colspan: int, rowspan: int}>  $cells
     * @param  list<int>  $natural  each column's content width, see naturalWidths()
     * @return list<int>
     */
    private function columnWidths(int $columnCount, int $tableWidth, array $columnStyles, array $cells, array $natural): array
    {
        $tableWidthPt = $tableWidth / Length::TWIPS_PER_POINT;
        $widths = array_fill(0, $columnCount, null);

        foreach (array_slice($columnStyles, 0, $columnCount) as $i => $columnStyle) {
            $widths[$i] = $this->positiveTwips($columnStyle->lengthPt('width', $tableWidthPt));
        }

        foreach ($cells as $cell) {
            if ($cell['colspan'] === 1 && $widths[$cell['col']] === null) {
                $widths[$cell['col']] = $this->positiveTwips($cell['style']->lengthPt('width', $tableWidthPt));
            }
        }

        // Columns nothing sizes share what is left as a browser shares it:
        // in proportion to how wide their content is.
        $left = max(0, $tableWidth - array_sum(array_filter($widths)));
        $unsized = array_sum(array_map(static fn(?int $w, int $n): int => $w === null ? $n : 0, $widths, $natural));
        $widths = array_map(
            static fn(?int $w, int $n): int => $w ?? max(self::MIN_COLUMN_TWIPS, intdiv($left * $n, max(1, $unsized))),
            $widths,
            $natural,
        );
        $total = array_sum($widths);

        // Scale to the table width; the last column absorbs the rounding.
        $scaled = [];
        $assigned = 0;

        foreach ($widths as $i => $w) {
            $scaled[] = $i === $columnCount - 1
                ? max(1, $tableWidth - $assigned)
                : max(1, (int) floor($w * $tableWidth / $total));
            $assigned += $scaled[array_key_last($scaled)];
        }

        return $scaled;
    }

    /**
     * How wide each column's content is laid out on one line (CSS's
     * max-content), padding and borders included, from its single-column cells.
     *
     * @param  list<array{element: Element, style: ComputedStyle, row: int, col: int, colspan: int, rowspan: int}>  $cells
     * @return list<int>
     */
    private function naturalWidths(int $columnCount, array $cells): array
    {
        $widths = array_fill(0, $columnCount, self::MIN_COLUMN_TWIPS);
        $natural = [];

        foreach ($cells as $index => $cell) {
            $style = $cell['style'];
            $longest = 0.0;

            // Lines break at <br> and between blocks; the rest is one line.
            $text = html_entity_decode(strip_tags((string) preg_replace('~<br\b[^>]*>|</(?:p|div|li|h[1-6])>~i', "\n", $cell['element']->innerHTML)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                $longest = max($longest, self::ems(trim($line)) * ($style->bold ? 1.05 : 1.0));
            }

            $frame = 0.0;

            foreach (['left', 'right'] as $side) {
                $frame += max(0, $style->lengthPt("padding-{$side}") ?? 0) + ($style->border($side)->widthPt ?? 0);
            }

            $natural[$index] = Length::pointsToTwips($longest * $style->fontSizePt + $frame);

            if ($cell['colspan'] === 1) {
                $widths[$cell['col']] = max($widths[$cell['col']], $natural[$index]);
            }
        }

        // A cell spanning columns widens them by what its content needs beyond
        // theirs, shared in proportion to their widths, as CSS's auto layout does.
        foreach ($cells as $index => $cell) {
            $span = array_slice($widths, $cell['col'], $cell['colspan'], true);
            $excess = $natural[$index] - array_sum($span);

            if ($cell['colspan'] === 1 || $excess <= 0) {
                continue;
            }

            foreach ($span as $column => $width) {
                $widths[$column] += intdiv($excess * $width, max(1, array_sum($span)));
            }
        }

        return array_values($widths);
    }

    /**
     * About how wide a line of text is in a common text face, in ems.
     * ponytail: rough widths by kind of character, not the font's own; measure glyphs if tables come out uneven.
     */
    private static function ems(string $line): float
    {
        $ems = 0.0;

        foreach (mb_str_split($line) as $char) {
            $ems += match (true) {
                str_contains(" .,:;'!|il\u{00A0}", $char) => 0.25,
                str_contains('fjrt()[]-/', $char) => 0.33,
                str_contains('mwMW', $char) => 0.8,
                preg_match('/\p{Lu}/u', $char) === 1 => 0.6,
                default => 0.5,
            };
        }

        return $ems;
    }

    /**
     * @param  list<array{element: Element, style: ComputedStyle, row: int, col: int, colspan: int, rowspan: int}>  $cells
     */
    private function anyCellWidth(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (($cell['style']->lengthPt('width', 100) ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function declared(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value === '' || $value === 'inherit' ? null : $value;
    }

    private function positiveTwips(?float $points): ?int
    {
        return $points !== null && $points > 0 ? Length::pointsToTwips($points) : null;
    }

    /**
     * @param  array{element: Element, style: ComputedStyle, row: int, col: int, colspan: int, rowspan: int}  $cell
     * @param  array{element: Element, style: ComputedStyle, background: string|null, header: bool}  $row
     */
    private function cellProperties(array $cell, array $row, int $width, int $span, bool $isOrigin): CellProperties
    {
        $style = $cell['style'];
        $widthPt = $width / Length::TWIPS_PER_POINT;
        $padding = fn(string $side): int => Length::pointsToTwips(max(0, $style->lengthPt("padding-{$side}", $widthPt) ?? 0));

        return new CellProperties(
            width: $width,
            gridSpan: $span,
            verticalMerge: match (true) {
                ! $isOrigin => CellProperties::MERGE_CONTINUE,
                $cell['rowspan'] > 1 => CellProperties::MERGE_RESTART,
                default => null,
            },
            shading: $style->backgroundColor() ?? $row['background'],
            // A cell inherits its row's: a browser's rows are middle-aligned.
            verticalAlign: match (self::declared($style->value('vertical-align')) ?? self::declared($row['style']->value('vertical-align'))) {
                'top' => 'top',
                'middle' => 'center',
                'bottom' => 'bottom',
                default => null,
            },
            borders: new BorderSet(
                top: $this->mapper->border($style->border('top')),
                left: $this->mapper->border($style->border('left')),
                bottom: $this->mapper->border($style->border('bottom')),
                right: $this->mapper->border($style->border('right')),
            ),
            margins: new CellMargins($padding('top'), $padding('left'), $padding('bottom'), $padding('right')),
            noWrap: $style->whiteSpace === 'nowrap',
        );
    }

    /**
     * @param  array{element: Element, style: ComputedStyle, row: int, col: int, colspan: int, rowspan: int}  $cell
     * @param  Closure(Element, ComputedStyle, BlockContext): list<Block>  $renderContent
     * @return list<Block>
     */
    private function cellContent(array $cell, int $width, Closure $renderContent): array
    {
        $style = $cell['style'];
        $widthPt = $width / Length::TWIPS_PER_POINT;
        $horizontalPadding = max(0, $style->lengthPt('padding-left', $widthPt) ?? 0) + max(0, $style->lengthPt('padding-right', $widthPt) ?? 0);

        $blocks = $renderContent(
            $cell['element'],
            $style,
            new BlockContext(max(Length::TWIPS_PER_POINT, $width - Length::pointsToTwips($horizontalPadding))),
        );

        return BlockNormalizer::normalize($blocks, $this->mapper->run($style));
    }
}
