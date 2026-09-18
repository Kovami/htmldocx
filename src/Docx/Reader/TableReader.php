<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Closure;
use Dom\Element;
use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Docx\Reader\Format\CellFormat;
use Kovami\HtmlDocx\Docx\Reader\Format\FormatParser;
use Kovami\HtmlDocx\Docx\Reader\Format\RowFormat;
use Kovami\HtmlDocx\Docx\Reader\Format\TableFormat;
use Kovami\HtmlDocx\Docx\Reader\Format\TableStyle;
use Kovami\HtmlDocx\Docx\Reader\Format\TableStyleCondition;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Border;
use Kovami\HtmlDocx\Model\BorderSet;
use Kovami\HtmlDocx\Model\CellMargins;
use Kovami\HtmlDocx\Model\CellProperties;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\Table;
use Kovami\HtmlDocx\Model\TableCell;
use Kovami\HtmlDocx\Model\TableProperties;
use Kovami\HtmlDocx\Model\TableRow;

/**
 * Reads `w:tbl` into a rectangular model table: every row spans the whole
 * grid, and each cell carries the borders, shading, margins and alignment
 * that result from the table style (including its conditional formatting
 * for header rows, banding, first/last columns and corners), the table's
 * own properties and the cell's direct formatting.
 */
final readonly class TableReader
{
    private const array ROW_CONDITIONS = ['firstRow', 'lastRow', 'band1Horz', 'band2Horz'];

    private const array COLUMN_CONDITIONS = ['firstCol', 'lastCol', 'band1Vert', 'band2Vert'];

    /**
     * @param  Closure(Element, ?TableStyleCondition, ?int): list<Block>  $readBlocks
     */
    public function __construct(
        private ReaderContext $context,
        private Closure $readBlocks,
    ) {}

    public function read(Element $table, int $availableWidth): ?Table
    {
        $parser = $this->context->parser;
        $direct = $parser->table(Xml::child($table, 'tblPr'));
        $style = $this->context->styles->tableStyle($direct->styleId);
        $format = $style->table->over($direct);
        $look = self::look(Xml::child(Xml::child($table, 'tblPr'), 'tblLook'));

        $rows = $this->rows($table, $parser);

        if ($rows === []) {
            return null;
        }

        $grid = array_map(
            static fn(Element $column): int => max(0, Xml::twips(Xml::attr($column, 'w')) ?? 0),
            Xml::children(Xml::child($table, 'tblGrid'), 'gridCol'),
        );
        $columnCount = max(count($grid), ...array_map(static fn(array $row): int => $row['columns'], $rows));
        $grid = $this->gridWidths($grid, $columnCount, $rows, $format, $availableWidth);
        $tableWidth = array_sum($grid);

        $headerRows = 0;

        foreach ($rows as $row) {
            if ($row['format']->header !== true) {
                break;
            }

            $headerRows++;
        }

        $modelRows = [];
        $lastRow = count($rows) - 1;

        foreach ($rows as $r => $row) {
            $cells = [];
            $column = 0;

            $pad = function (int $span) use (&$cells, &$column, $grid): void {
                if ($span > 0) {
                    $cells[] = new TableCell(
                        new CellProperties(array_sum(array_slice($grid, $column, $span)), $span),
                        [new Paragraph()],
                    );
                    $column += $span;
                }
            };

            $pad($row['format']->gridBefore ?? 0);

            foreach ($row['cells'] as ['element' => $element, 'format' => $cellFormat, 'span' => $span]) {
                $span = min($span, $columnCount - $column);

                if ($span < 1) {
                    break;
                }

                $conditions = $this->conditions($style, $look, $r, $lastRow, $column, $column + $span - 1, $columnCount, $format, $headerRows);
                // w:tblPrEx: this row's exceptions to the table's own properties.
                $rowTable = $row['exceptions'] === null ? $format : $format->over($row['exceptions']);
                $cells[] = $this->cell($element, $cellFormat, $conditions, $style, $rowTable, $grid, $column, $span, $r, $lastRow, $columnCount);
                $column += $span;
            }

            $pad($columnCount - $column);

            $height = $row['format']->height;

            $modelRows[] = new TableRow(
                $cells,
                $r < $headerRows,
                $height !== null && $height > 0 && $row['format']->heightRule !== 'auto' ? $height : null,
            );
        }

        return new Table(
            new TableProperties(
                width: $tableWidth,
                alignment: $format->alignment === 'left' ? null : $format->alignment,
                indentLeft: $format->indent ?? 0,
                shading: $format->shading === null || $format->shading === 'auto' ? null : $format->shading,
                bidi: $format->bidi ?? false,
            ),
            $grid,
            $modelRows,
        );
    }

    /**
     * Rows with their cells; deleted rows are dropped, horizontally merged
     * legacy cells (`w:hMerge`) are folded into a grid span.
     *
     * @return list<array{format: RowFormat, columns: int, cells: list<array{element: Element, format: CellFormat, span: int}>, exceptions: ?TableFormat}>
     */
    private function rows(Element $table, FormatParser $parser): array
    {
        $rows = [];

        foreach (self::flatten($table, 'tr') as $tr) {
            $rowFormat = $parser->row(Xml::child($tr, 'trPr'));

            if ($rowFormat->deleted === true) {
                continue;
            }

            /** @var list<array{element: Element, format: CellFormat, span: int}> $cells */
            $cells = [];

            foreach (self::flatten($tr, 'tc') as $tc) {
                $format = $parser->cell(Xml::child($tc, 'tcPr'));
                $span = max(1, $format->gridSpan ?? 1);

                if ($format->horizontalMerge === 'continue' && $cells !== []) {
                    $last = array_pop($cells);
                    $cells[] = [...$last, 'span' => $last['span'] + $span];

                    continue;
                }

                $cells[] = ['element' => $tc, 'format' => $format, 'span' => $span];
            }

            $columns = ($rowFormat->gridBefore ?? 0) + array_sum(array_column($cells, 'span')) + ($rowFormat->gridAfter ?? 0);
            $exceptions = Xml::child($tr, 'tblPrEx');
            $rows[] = [
                'format' => $rowFormat,
                'columns' => $columns,
                'cells' => $cells,
                'exceptions' => $exceptions === null ? null : $parser->table($exceptions),
            ];
        }

        return $rows;
    }

    /**
     * Column widths in twips: the declared grid, completed from cell widths
     * where it is missing, and scaled to the table width when that is fixed.
     *
     * @param  list<int>  $grid
     * @param  list<array{format: RowFormat, columns: int, cells: list<array{element: Element, format: CellFormat, span: int}>, exceptions: ?TableFormat}>  $rows
     * @return list<int>
     */
    private function gridWidths(array $grid, int $columnCount, array $rows, TableFormat $format, int $availableWidth): array
    {
        $grid = array_pad($grid, $columnCount, 0);

        if (in_array(0, $grid, true)) {
            foreach ($rows as $row) {
                $column = $row['format']->gridBefore ?? 0;

                foreach ($row['cells'] as $cell) {
                    $width = $cell['format']->width;
                    $twips = match ($width[1] ?? 'auto') {
                        'dxa' => $width[0],
                        // Fiftieths of a percent of the space the table has.
                        'pct' => (int) round($availableWidth * $width[0] / 5000),
                        default => 0,
                    };

                    if ($cell['span'] === 1 && $twips > 0 && ($grid[$column] ?? 1) === 0) {
                        $grid[$column] = $twips;
                    }

                    $column += $cell['span'];
                }
            }
        }

        $known = array_filter($grid);
        $fallback = $known === [] ? intdiv($availableWidth, max(1, $columnCount)) : intdiv(array_sum($known), count($known));
        $grid = array_map(static fn(int $width): int => $width > 0 ? $width : $fallback, $grid);

        $target = match ($format->width[1] ?? 'auto') {
            'dxa' => $format->width[0] > 0 ? $format->width[0] : null,
            'pct' => (int) round($availableWidth * $format->width[0] / 5000),
            default => null,
        };

        $total = array_sum($grid);

        if ($target !== null && $total > 0 && abs($target - $total) > 20) {
            $grid = array_map(static fn(int $width): int => max(1, (int) round($width * $target / $total)), $grid);
        }

        return $grid;
    }

    /**
     * The style conditions that apply to a cell, lowest priority first.
     *
     * @param  array<string, bool>  $look
     * @return list<array{type: string, condition: TableStyleCondition}>
     */
    private function conditions(TableStyle $style, array $look, int $row, int $lastRow, int $firstColumn, int $lastColumn, int $columnCount, TableFormat $format, int $headerRows): array
    {
        $isFirstRow = $look['firstRow'] && $row < max(1, $headerRows);
        $isLastRow = $look['lastRow'] && $row === $lastRow;
        $isFirstColumn = $look['firstColumn'] && $firstColumn === 0;
        $isLastColumn = $look['lastColumn'] && $lastColumn === $columnCount - 1;

        $bandRow = $row - ($look['firstRow'] ? max(1, $headerRows) : 0);
        $bandColumn = $firstColumn - ($look['firstColumn'] ? 1 : 0);
        $rowBand = max(1, $format->rowBandSize ?? 1);
        $columnBand = max(1, $format->columnBandSize ?? 1);

        $applies = [
            'band1Vert' => ! $look['noVBand'] && ! $isFirstColumn && ! $isLastColumn && $bandColumn >= 0 && intdiv($bandColumn, $columnBand) % 2 === 0,
            'band2Vert' => ! $look['noVBand'] && ! $isFirstColumn && ! $isLastColumn && $bandColumn >= 0 && intdiv($bandColumn, $columnBand) % 2 === 1,
            'band1Horz' => ! $look['noHBand'] && ! $isFirstRow && ! $isLastRow && $bandRow >= 0 && intdiv($bandRow, $rowBand) % 2 === 0,
            'band2Horz' => ! $look['noHBand'] && ! $isFirstRow && ! $isLastRow && $bandRow >= 0 && intdiv($bandRow, $rowBand) % 2 === 1,
            'firstCol' => $isFirstColumn,
            'lastCol' => $isLastColumn,
            'firstRow' => $isFirstRow,
            'lastRow' => $isLastRow,
            'nwCell' => $isFirstRow && $isFirstColumn,
            'neCell' => $isFirstRow && $isLastColumn,
            'swCell' => $isLastRow && $isFirstColumn,
            'seCell' => $isLastRow && $isLastColumn,
        ];

        $result = [['type' => 'wholeTable', 'condition' => $style->whole->over($style->conditions['wholeTable'] ?? new TableStyleCondition())]];

        foreach (TableStyle::CONDITION_ORDER as $type) {
            if (($applies[$type] ?? false) && isset($style->conditions[$type])) {
                $result[] = ['type' => $type, 'condition' => $style->conditions[$type]];
            }
        }

        return $result;
    }

    /**
     * @param  list<array{type: string, condition: TableStyleCondition}>  $conditions
     * @param  list<int>  $grid
     */
    private function cell(Element $element, CellFormat $direct, array $conditions, TableStyle $style, TableFormat $table, array $grid, int $column, int $span, int $row, int $lastRow, int $columnCount): TableCell
    {
        $lastColumn = $column + $span - 1;
        $edges = [
            'top' => $row === 0,
            'bottom' => $row === $lastRow,
            'left' => $column === 0,
            'right' => $lastColumn === $columnCount - 1,
        ];

        $borders = self::regionBorders($table->borders, $edges);
        $cellFormat = new CellFormat();
        $merged = new TableStyleCondition();

        foreach ($conditions as ['type' => $type, 'condition' => $condition]) {
            $regionEdges = match (true) {
                in_array($type, self::ROW_CONDITIONS, true) => ['top' => true, 'bottom' => true, 'left' => $edges['left'], 'right' => $edges['right']],
                in_array($type, self::COLUMN_CONDITIONS, true) => ['top' => $edges['top'], 'bottom' => $edges['bottom'], 'left' => true, 'right' => true],
                $type === 'wholeTable' => $edges,
                default => ['top' => true, 'bottom' => true, 'left' => true, 'right' => true],
            };

            if ($type !== 'wholeTable') {
                $borders = [...$borders, ...self::regionBorders($condition->table->borders, $regionEdges)];
            }

            $borders = [...$borders, ...self::regionBorders($condition->cell->borders, $regionEdges)];
            $cellFormat = $cellFormat->over($condition->cell);
            $merged = $merged->over($condition);
        }

        $format = $cellFormat->over($direct);
        $borders = [...$borders, ...array_intersect_key($direct->borders, array_flip(['top', 'left', 'bottom', 'right']))];
        $margins = [...['top' => 0, 'left' => 108, 'bottom' => 0, 'right' => 108], ...$style->table->cellMargins, ...$table->cellMargins, ...$format->margins];
        $width = array_sum(array_slice($grid, $column, $span));
        $shading = $format->shading ?? $table->shading;

        $blocks = ($this->readBlocks)($element, $merged, max(360, $width - $margins['left'] - $margins['right']));

        if ($blocks === [] || ! $blocks[array_key_last($blocks)] instanceof Paragraph) {
            $blocks[] = new Paragraph();
        }

        return new TableCell(
            new CellProperties(
                width: $width,
                gridSpan: $span,
                verticalMerge: $direct->verticalMerge,
                shading: $shading === null || $shading === 'auto' ? null : $shading,
                verticalAlign: $format->verticalAlign,
                borders: new BorderSet(
                    top: self::visible($borders['top'] ?? null),
                    left: self::visible($borders['left'] ?? null),
                    bottom: self::visible($borders['bottom'] ?? null),
                    right: self::visible($borders['right'] ?? null),
                ),
                margins: new CellMargins($margins['top'], $margins['left'], $margins['bottom'], $margins['right']),
                noWrap: $format->noWrap ?? false,
            ),
            $blocks,
        );
    }

    /**
     * Maps a region's borders onto the sides of one of its cells: sides on
     * the region's outline take the outer border, the others the inside one.
     *
     * @param  array<string, Border>  $borders  top, left, bottom, right, insideH, insideV
     * @param  array<string, bool>  $regionEdges  whether each cell side lies on the region's outline
     * @return array<string, Border>
     */
    private static function regionBorders(array $borders, array $regionEdges): array
    {
        $result = [];

        foreach (['top' => 'insideH', 'bottom' => 'insideH', 'left' => 'insideV', 'right' => 'insideV'] as $side => $inside) {
            $border = $regionEdges[$side] ? ($borders[$side] ?? null) : ($borders[$inside] ?? null);

            if ($border !== null) {
                $result[$side] = $border;
            }
        }

        return $result;
    }

    private static function visible(?Border $border): ?Border
    {
        if ($border === null || $border->style === 'none') {
            return null;
        }

        return $border->color === 'auto' ? new Border($border->style, $border->size, '000000', $border->space) : $border;
    }

    /**
     * @return array<string, bool>
     */
    private static function look(?Element $look): array
    {
        $value = hexdec((string) (Xml::attr($look, 'val') ?? '04A0'));
        $flag = static function (string $name, int $bit) use ($look, $value): bool {
            $attribute = Xml::attr($look, $name);

            return $attribute !== null ? in_array(strtolower($attribute), ['1', 'true', 'on'], true) : ($value & $bit) !== 0;
        };

        return [
            'firstRow' => $flag('firstRow', 0x0020),
            'lastRow' => $flag('lastRow', 0x0040),
            'firstColumn' => $flag('firstColumn', 0x0080),
            'lastColumn' => $flag('lastColumn', 0x0100),
            'noHBand' => $flag('noHBand', 0x0200),
            'noVBand' => $flag('noVBand', 0x0400),
        ];
    }

    /**
     * Children named $localName, looking through content controls and custom XML.
     *
     * @return list<Element>
     */
    private static function flatten(Element $parent, string $localName): array
    {
        $result = [];

        foreach (Xml::children($parent) as $child) {
            if (Xml::is($child, $localName)) {
                $result[] = $child;
            } elseif (Xml::is($child, 'sdt')) {
                array_push($result, ...self::flatten(Xml::child($child, 'sdtContent') ?? $child, $localName));
            } elseif (Xml::is($child, 'customXml') || Xml::is($child, 'ins') || Xml::is($child, 'AlternateContent', Namespaces::MC)) {
                array_push($result, ...self::flatten($child, $localName));
            }
        }

        return $result;
    }
}
