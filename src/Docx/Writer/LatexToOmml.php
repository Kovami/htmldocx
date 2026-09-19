<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use Kovami\HtmlDocx\Docx\Reader\OmmlToLatex;
use Kovami\HtmlDocx\Math\LatexParser;
use Kovami\HtmlDocx\Model\RunProperties;

/**
 * Writes LaTeX (the dialect KaTeX renders, which is what rich-text editors
 * store) as Office Math, so a formula reaches Word as an equation it can
 * lay out and edit rather than as its source text.
 *
 * It is the inverse of {@see OmmlToLatex}, and covers what that one
 * produces; {@see LatexParser} reads the LaTeX.
 */
final class LatexToOmml
{
    private function __construct(
        private readonly XmlBuilder $xml,
        private readonly RunProperties $properties,
    ) {}

    /** Writes the contents of one `m:oMath` element. */
    public static function write(XmlBuilder $xml, string $latex, RunProperties $properties): void
    {
        (new self($xml, $properties))->emit(LatexParser::parse($latex));
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function emit(array $nodes): void
    {
        $text = '';

        foreach ($nodes as $node) {
            if ($node['type'] === 'run') {
                $text .= $node['text'];

                continue;
            }

            $this->flush($text);
            $this->node($node);
        }

        $this->flush($text);
    }

    /** Neighbouring characters share one run, the way Word writes them. */
    private function flush(string &$text): void
    {
        if ($text !== '') {
            $this->run($text);
        }

        $text = '';
    }

    /**
     * @param  string|null  $style  "nor" for ordinary text, "upright" for a roman name, null for math italic
     */
    private function run(string $text, ?string $style = null): void
    {
        $this->xml->open('m:r');

        if ($style !== null) {
            $this->xml->open('m:rPr');
            $style === 'nor' ? $this->xml->leaf('m:nor') : $this->xml->leaf('m:sty', ['m:val' => 'p']);
            $this->xml->close();
        }

        PropertiesWriter::run($this->xml, $this->properties);
        $this->xml->text('m:t', $text, ['xml:space' => 'preserve'])->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function node(array $node): void
    {
        match ($node['type']) {
            'group' => $this->emit(LatexParser::children($node)),
            'text' => $this->run(LatexParser::string($node, 'text'), 'nor'),
            'upright' => $this->run(LatexParser::string($node, 'text'), 'upright'),
            'frac' => $this->wrap('m:f', ['m:num' => LatexParser::list($node, 'num'), 'm:den' => LatexParser::list($node, 'den')]),
            'scripts' => $this->scripts($node),
            'rad' => $this->radical($node),
            'nary' => $this->nary($node),
            'delim' => $this->delimited($node),
            'func' => $this->wrap('m:func', ['m:fName' => LatexParser::list($node, 'name'), 'm:e' => LatexParser::children($node)]),
            'accent' => $this->wrapWith('m:acc', 'm:chr', LatexParser::string($node, 'char'), LatexParser::children($node)),
            'bar' => $this->wrapWith('m:bar', 'm:pos', LatexParser::string($node, 'position'), LatexParser::children($node)),
            'box' => $this->wrap('m:borderBox', ['m:e' => LatexParser::children($node)]),
            'limit' => $this->limit($node),
            'matrix' => $this->matrix($node),
            'eqArr' => $this->equationArray($node),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function scripts(array $node): void
    {
        $sub = LatexParser::list($node, 'sub');
        $sup = LatexParser::list($node, 'sup');

        $element = match (true) {
            $sub !== [] && $sup !== [] => 'm:sSubSup',
            $sub !== [] => 'm:sSub',
            default => 'm:sSup',
        };

        $parts = ['m:e' => LatexParser::list($node, 'base')];

        if ($sub !== []) {
            $parts['m:sub'] = $sub;
        }

        if ($sup !== [] || $sub === []) {
            $parts['m:sup'] = $sup;
        }

        $this->wrap($element, $parts);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function radical(array $node): void
    {
        $degree = LatexParser::list($node, 'degree');

        $this->xml->open('m:rad')->open('m:radPr')
            ->leaf('m:degHide', ['m:val' => $degree === [] ? '1' : '0'])
            ->close();

        $this->part('m:deg', $degree);
        $this->part('m:e', LatexParser::children($node));
        $this->xml->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nary(array $node): void
    {
        $sub = LatexParser::list($node, 'sub');
        $sup = LatexParser::list($node, 'sup');

        $this->xml->open('m:nary')->open('m:naryPr')
            ->leaf('m:chr', ['m:val' => LatexParser::string($node, 'char')])
            ->leaf('m:limLoc', ['m:val' => 'undOvr'])
            ->leaf('m:subHide', ['m:val' => $sub === [] ? '1' : '0'])
            ->leaf('m:supHide', ['m:val' => $sup === [] ? '1' : '0'])
            ->close();

        $this->part('m:sub', $sub);
        $this->part('m:sup', $sup);
        $this->part('m:e', LatexParser::children($node));
        $this->xml->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function delimited(array $node): void
    {
        $this->xml->open('m:d')->open('m:dPr')
            ->leaf('m:begChr', ['m:val' => LatexParser::string($node, 'begin')])
            ->leaf('m:endChr', ['m:val' => LatexParser::string($node, 'end')])
            ->close();

        /** @var list<list<array<string, mixed>>> $parts */
        $parts = is_array($node['parts'] ?? null) ? $node['parts'] : [];

        foreach ($parts as $part) {
            $this->part('m:e', $part);
        }

        $this->xml->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function limit(array $node): void
    {
        $over = ($node['over'] ?? false) === true;

        $this->xml->open($over ? 'm:limUpp' : 'm:limLow');
        $this->part('m:e', LatexParser::children($node));
        $this->part('m:lim', LatexParser::list($node, 'limit'));
        $this->xml->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function matrix(array $node): void
    {
        $this->xml->open('m:m');

        foreach (LatexParser::rows($node) as $row) {
            $this->xml->open('m:mr');

            foreach ($row as $cell) {
                $this->part('m:e', $cell);
            }

            $this->xml->close();
        }

        $this->xml->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function equationArray(array $node): void
    {
        $this->xml->open('m:eqArr');

        /** @var list<list<array<string, mixed>>> $rows */
        $rows = is_array($node['rows'] ?? null) ? $node['rows'] : [];

        foreach ($rows as $row) {
            $this->part('m:e', $row);
        }

        $this->xml->close();
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $parts
     */
    private function wrap(string $element, array $parts): void
    {
        $this->xml->open($element);

        foreach ($parts as $name => $children) {
            $this->part($name, $children);
        }

        $this->xml->close();
    }

    /**
     * An element whose single property names a character or a position.
     *
     * @param  list<array<string, mixed>>  $children
     */
    private function wrapWith(string $element, string $property, string $value, array $children): void
    {
        $this->xml->open($element)
            ->open($element . 'Pr')->leaf($property, ['m:val' => $value])->close();

        $this->part('m:e', $children);
        $this->xml->close();
    }

    /**
     * @param  list<array<string, mixed>>  $children
     */
    private function part(string $element, array $children): void
    {
        $this->xml->open($element);
        $this->emit($children);
        $this->xml->close();
    }
}
