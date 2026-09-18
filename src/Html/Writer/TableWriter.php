<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Closure;
use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\CellProperties;
use Kovami\HtmlDocx\Model\Table;
use Kovami\HtmlDocx\Model\TableCell;

/**
 * Writes tables in SunEditor's shape: a sizing class, column widths in a
 * `colgroup`, header rows in `thead`, vertical merges as `rowspan`, and cell
 * content as `div` lines.
 */
final readonly class TableWriter
{
    /** Word's default cell margins (Normal Table style), in twips. */
    private const array WORD_CELL_MARGINS = [0, 108, 0, 108];

    /**
     * @param  Closure(list<Block>, Element, ComputedStyle, string, int): void  $writeBlocks
     */
    public function __construct(
        private WriterContext $context,
        private Closure $writeBlocks,
    ) {}

    /**
     * @param  int  $marginTop  twips; Word tables have no spacing of their own, so the model carries it
     * @param  int  $marginBottom  twips
     */
    public function write(Table $table, Element $parent, ComputedStyle $parentStyle, int $availableWidth, int $marginTop = 0, int $marginBottom = 0): ComputedStyle
    {
        $element = $this->context->element('table', $parent);
        $percent = $availableWidth > 0 ? $table->properties->width / $availableWidth * 100 : 100;
        $fullWidth = $percent >= 99.5;
        $element->setAttribute('class', $fullWidth ? 'se-table-size-100' : 'se-table-size-auto');

        $tableStyle = $this->context->style($element, $parentStyle, function (ComputedStyle $baseline) use ($table, $fullWidth, $percent, $marginTop, $marginBottom): array {
            $css = [];

            if (! $fullWidth) {
                $css['width'] = CssFormatter::number(min(100, $percent)).'%';
            }

            foreach (['margin-top' => $marginTop, 'margin-bottom' => $marginBottom] as $property => $twips) {
                $value = $this->context->css->length($twips, $baseline->lengthPt($property));

                if ($value !== null) {
                    $css[$property] = $value;
                }
            }

            if ($table->properties->alignment === 'center') {
                $css['margin-left'] = 'auto';
                $css['margin-right'] = 'auto';
            } elseif ($table->properties->alignment === 'right') {
                $css['margin-left'] = 'auto';
            } elseif ($table->properties->indentLeft > 0) {
                $css['margin-left'] = $this->context->css->twips($table->properties->indentLeft);
            }

            if ($table->properties->shading !== null) {
                $css['background-color'] = CssFormatter::color($table->properties->shading);
            }

            if ($table->properties->bidi) {
                $css['direction'] = 'rtl';
            }

            return $css;
        });

        $total = max(1, array_sum($table->gridColumns));
        $colgroup = $this->context->element('colgroup', $element);

        foreach ($table->gridColumns as $width) {
            $this->context->element('col', $colgroup)->setAttribute('style', 'width: '.CssFormatter::number($width / $total * 100).'%;');
        }

        $rowSpans = self::rowSpans($table);
        $section = null;
        $sectionIsHead = null;

        foreach ($table->rows as $r => $row) {
            $isHead = $row->isHeader && ($sectionIsHead ?? true);

            if ($section === null || $sectionIsHead !== $isHead) {
                $section = $this->context->element($isHead ? 'thead' : 'tbody', $element);
                $sectionIsHead = $isHead;
            }

            $sectionStyle = $this->context->resolver->resolve($section, $tableStyle);
            $tr = $this->context->element('tr', $section);
            $rowStyle = $this->context->style($tr, $sectionStyle, fn (): array => $row->minHeight === null ? [] : ['height' => $this->context->css->twips($row->minHeight)]);

            $column = 0;

            foreach ($row->cells as $cell) {
                if ($cell->properties->verticalMerge !== CellProperties::MERGE_CONTINUE) {
                    $this->cell($cell, $tr, $rowStyle, $isHead ? 'th' : 'td', $rowSpans[$r][$column] ?? 1);
                }

                $column += $cell->properties->gridSpan;
            }
        }

        return $tableStyle;
    }

    private function cell(TableCell $cell, Element $row, ComputedStyle $rowStyle, string $tag, int $rowSpan): void
    {
        $properties = $cell->properties;
        $element = $this->context->element($tag, $row);

        if ($properties->gridSpan > 1) {
            $element->setAttribute('colspan', (string) $properties->gridSpan);
        }

        if ($rowSpan > 1) {
            $element->setAttribute('rowspan', (string) $rowSpan);
        }

        $style = $this->context->style($element, $rowStyle, function (ComputedStyle $baseline) use ($properties): array {
            $css = $this->context->css->sides($properties->borders, $baseline);

            $background = $baseline->backgroundColor();

            if ($properties->shading !== null ? strcasecmp($properties->shading, (string) $background) !== 0 : $background !== null) {
                $css['background-color'] = $properties->shading === null ? 'transparent' : CssFormatter::color($properties->shading);
            }

            $align = match ($properties->verticalAlign) {
                'center' => 'middle',
                'bottom' => 'bottom',
                default => 'top',
            };
            $baselineAlign = $baseline->value('vertical-align') ?? 'baseline';

            if ($align !== $baselineAlign && ! ($this->adoptsWordDefaults() && $properties->verticalAlign === null)) {
                $css['vertical-align'] = $align;
            }

            $margins = $properties->margins === null ? self::WORD_CELL_MARGINS
                : [$properties->margins->top, $properties->margins->right, $properties->margins->bottom, $properties->margins->left];

            if (! ($this->adoptsWordDefaults() && $margins === self::WORD_CELL_MARGINS)) {
                $paddings = array_map(
                    fn (int $twips): string => $this->context->css->twips($twips),
                    $margins,
                );
                $baselinePaddings = array_map(
                    fn (string $side): string => $this->context->css->points($baseline->lengthPt("padding-{$side}") ?? 0),
                    ['top', 'right', 'bottom', 'left'],
                );

                if ($paddings !== $baselinePaddings) {
                    $css['padding'] = implode(' ', $paddings);
                }
            }

            if ($properties->noWrap) {
                $css['white-space'] = 'nowrap';
            }

            return $css;
        });

        $contentWidth = max(Length::TWIPS_PER_POINT, $properties->width - ($properties->margins === null ? 216 : $properties->margins->left + $properties->margins->right));
        ($this->writeBlocks)($cell->blocks, $element, $style, 'div', $contentWidth);
    }

    private function adoptsWordDefaults(): bool
    {
        return ! $this->context->options->keepDocumentDefaults;
    }

    /**
     * `rowspan` for each cell that starts a vertical merge, keyed by row and grid column.
     *
     * @return array<int, array<int, int>>
     */
    private static function rowSpans(Table $table): array
    {
        $starts = [];

        foreach ($table->rows as $r => $row) {
            $column = 0;

            foreach ($row->cells as $cell) {
                $starts[$r][$column] = $cell;
                $column += $cell->properties->gridSpan;
            }
        }

        $spans = [];

        foreach ($starts as $r => $cells) {
            foreach ($cells as $column => $cell) {
                if ($cell->properties->verticalMerge !== CellProperties::MERGE_RESTART) {
                    continue;
                }

                $span = 1;

                while (($starts[$r + $span][$column] ?? null)?->properties->verticalMerge === CellProperties::MERGE_CONTINUE) {
                    $span++;
                }

                $spans[$r][$column] = $span;
            }
        }

        return $spans;
    }
}
