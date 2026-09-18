<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Dom\Element;
use Dom\XMLDocument;
use Kovami\HtmlDocx\Docx\Reader\Format\FormatParser;
use Kovami\HtmlDocx\Docx\Reader\Format\ParagraphFormat;
use Kovami\HtmlDocx\Docx\Reader\Format\RunFormat;
use Kovami\HtmlDocx\Docx\Reader\Format\TableStyle;
use Kovami\HtmlDocx\Docx\Reader\Format\TableStyleCondition;

/**
 * word/styles.xml: document defaults and named styles, with `w:basedOn`
 * chains resolved (cycles and unknown parents are ignored).
 */
final class StyleSheet
{
    /** @var array<string, array{type: string, name: string, basedOn: ?string, element: Element}> */
    private array $styles = [];

    /** @var array<string, string> type => default style id */
    private array $defaults = [];

    /** @var array<string, ParagraphFormat> */
    private array $paragraphCache = [];

    /** @var array<string, RunFormat> */
    private array $runCache = [];

    /** @var array<string, TableStyle> */
    private array $tableCache = [];

    public readonly RunFormat $defaultRun;

    public readonly ParagraphFormat $defaultParagraph;

    public function __construct(private readonly FormatParser $parser, ?XMLDocument $document = null)
    {
        $root = $document?->documentElement;
        $docDefaults = Xml::child($root, 'docDefaults');

        $this->defaultRun = $parser->run(Xml::child(Xml::child($docDefaults, 'rPrDefault'), 'rPr'));
        $this->defaultParagraph = $parser->paragraph(Xml::child(Xml::child($docDefaults, 'pPrDefault'), 'pPr'));

        foreach (Xml::children($root, 'style') as $style) {
            $id = Xml::attr($style, 'styleId');
            $type = Xml::attr($style, 'type') ?? 'paragraph';

            if ($id === null || isset($this->styles[$id])) {
                continue;
            }

            $this->styles[$id] = [
                'type' => $type,
                'name' => (string) Xml::val($style, 'name'),
                'basedOn' => Xml::val($style, 'basedOn'),
                'element' => $style,
            ];

            if (Xml::attr($style, 'default') !== null && in_array(strtolower((string) Xml::attr($style, 'default')), ['1', 'true', 'on'], true)) {
                $this->defaults[$type] ??= $id;
            }
        }
    }

    public function defaultStyleId(string $type): ?string
    {
        return $this->defaults[$type] ?? null;
    }

    public function name(?string $id): ?string
    {
        return $id === null ? null : ($this->styles[$id]['name'] ?? null);
    }

    /** The id itself when it names a style of $type, otherwise the default style of that type. */
    public function effectiveId(?string $id, string $type): ?string
    {
        return $id !== null && ($this->styles[$id]['type'] ?? null) === $type ? $id : $this->defaultStyleId($type);
    }

    /** Paragraph formatting of a paragraph style, including its ancestors. */
    public function paragraphStyle(?string $id): ParagraphFormat
    {
        $id = $this->effectiveId($id, 'paragraph');

        if ($id === null) {
            return new ParagraphFormat;
        }

        return $this->paragraphCache[$id] ??= array_reduce(
            $this->chain($id),
            fn (ParagraphFormat $format, Element $style): ParagraphFormat => $format->over($this->parser->paragraph(Xml::child($style, 'pPr'))),
            new ParagraphFormat,
        );
    }

    /** Character formatting of a paragraph or character style, including its ancestors. */
    public function runStyle(?string $id, string $type): RunFormat
    {
        $id = $this->effectiveId($id, $type);

        if ($id === null) {
            return new RunFormat;
        }

        return $this->runCache[$type.':'.$id] ??= array_reduce(
            $this->chain($id),
            fn (RunFormat $format, Element $style): RunFormat => $format->over($this->parser->run(Xml::child($style, 'rPr'))),
            new RunFormat,
        );
    }

    public function tableStyle(?string $id): TableStyle
    {
        $id = $this->effectiveId($id, 'table');

        if ($id === null) {
            return new TableStyle;
        }

        return $this->tableCache[$id] ??= array_reduce(
            $this->chain($id),
            fn (TableStyle $format, Element $style): TableStyle => $format->over($this->readTableStyle($style)),
            new TableStyle,
        );
    }

    /** The `w:numId` a numbering style points at (target of `w:numStyleLink`). */
    public function numberingStyleNumId(string $id): ?string
    {
        if (($this->styles[$id]['type'] ?? null) !== 'numbering') {
            return null;
        }

        return array_reduce(
            $this->chain($id),
            fn (?string $numId, Element $style): ?string => $this->parser->paragraph(Xml::child($style, 'pPr'))->numId ?? $numId,
            null,
        );
    }

    private function readTableStyle(Element $style): TableStyle
    {
        $conditions = [];

        foreach (Xml::children($style, 'tblStylePr') as $condition) {
            $type = (string) Xml::attr($condition, 'type');

            if (in_array($type, TableStyle::CONDITION_ORDER, true)) {
                $conditions[$type] = $this->condition($condition);
            }
        }

        return new TableStyle(
            table: $this->parser->table(Xml::child($style, 'tblPr')),
            whole: $this->condition($style),
            conditions: $conditions,
        );
    }

    private function condition(Element $element): TableStyleCondition
    {
        return new TableStyleCondition(
            table: $this->parser->table(Xml::child($element, 'tblPr')),
            cell: $this->parser->cell(Xml::child($element, 'tcPr')),
            paragraph: $this->parser->paragraph(Xml::child($element, 'pPr')),
            run: $this->parser->run(Xml::child($element, 'rPr')),
        );
    }

    /**
     * The style and its ancestors, root first.
     *
     * @return list<Element>
     */
    private function chain(string $id): array
    {
        $chain = [];
        $seen = [];

        while ($id !== null && isset($this->styles[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($chain, $this->styles[$id]['element']);
            $id = $this->styles[$id]['basedOn'];
        }

        return $chain;
    }
}
