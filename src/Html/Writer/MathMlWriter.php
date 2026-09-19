<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Dom\Element;
use Dom\HTMLDocument;
use Kovami\HtmlDocx\Math\LatexParser;

/**
 * Writes a formula as MathML, which browsers lay out on their own, with the
 * LaTeX it came from as an annotation so an editor or the way back can use
 * the source.
 */
final readonly class MathMlWriter
{
    private const string NAMESPACE = 'http://www.w3.org/1998/Math/MathML';

    /** Combining accents, as the spacing characters MathML draws over a base. */
    private const array ACCENTS = [
        "\u{0302}" => '^', "\u{0303}" => '~', "\u{0307}" => '˙', "\u{0308}" => '¨', "\u{0304}" => '¯',
        "\u{20D7}" => '→', "\u{20D6}" => '←', "\u{0306}" => '˘', "\u{030C}" => 'ˇ', "\u{0301}" => '´',
        "\u{0300}" => '`',
    ];

    public function __construct(private HTMLDocument $dom) {}

    public function write(string $latex, Element $parent): void
    {
        $semantics = $this->element('semantics', $this->element('math', $parent));
        $this->row(LatexParser::parse($latex), $semantics);

        $annotation = $this->element('annotation', $semantics);
        $annotation->setAttribute('encoding', 'application/x-tex');
        $annotation->append($latex);
    }

    private function element(string $name, Element $parent): Element
    {
        $element = $this->dom->createElementNS(self::NAMESPACE, $name);
        $parent->append($element);

        return $element;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function row(array $nodes, Element $parent): void
    {
        $row = $this->element('mrow', $parent);
        $text = '';

        // The parser reads character by character; `3.14` is still one number.
        foreach ($nodes as $node) {
            if ($node['type'] === 'run') {
                $text .= LatexParser::string($node, 'text');

                continue;
            }

            $this->run($text, $row);
            $text = '';
            $this->node($node, $row);
        }

        $this->run($text, $row);
    }

    /**
     * An element whose children are one row each.
     *
     * @param  list<list<array<string, mixed>>>  $parts
     */
    private function wrap(string $name, array $parts, Element $parent): Element
    {
        $element = $this->element($name, $parent);

        foreach ($parts as $part) {
            $this->row($part, $element);
        }

        return $element;
    }

    private function token(string $name, string $text, Element $parent): Element
    {
        $element = $this->element($name, $parent);
        $element->append($text);

        return $element;
    }

    /**
     * @param  array<string, mixed>  $node  written into $parent, always an `mrow`
     */
    private function node(array $node, Element $parent): void
    {
        $children = LatexParser::children($node);

        match ($node['type']) {
            'run' => $this->run(LatexParser::string($node, 'text'), $parent),
            'text' => $this->token('mtext', LatexParser::string($node, 'text'), $parent),
            'upright' => $this->upright(LatexParser::string($node, 'text'), $parent),
            'group' => $this->row($children, $parent),
            'frac' => $this->wrap('mfrac', [LatexParser::list($node, 'num'), LatexParser::list($node, 'den')], $parent),
            'scripts' => $this->scripts(LatexParser::list($node, 'base'), LatexParser::list($node, 'sub'), LatexParser::list($node, 'sup'), $parent),
            'rad' => LatexParser::list($node, 'degree') === []
                ? $this->wrap('msqrt', [$children], $parent)
                : $this->wrap('mroot', [$children, LatexParser::list($node, 'degree')], $parent),
            'nary' => $this->nary($node, $parent),
            'delim' => $this->delimited($node, $parent),
            'func' => $this->function($node, $parent),
            'accent' => $this->over($children, self::ACCENTS[LatexParser::string($node, 'char')] ?? LatexParser::string($node, 'char'), $parent),
            'bar' => LatexParser::string($node, 'position') === 'bot'
                ? $this->wrap('munder', [$children, [['type' => 'run', 'text' => '_']]], $parent)
                : $this->over($children, '¯', $parent),
            'box' => $this->wrap('menclose', [$children], $parent)->setAttribute('notation', 'box'),
            'limit' => $this->wrap(($node['over'] ?? false) === true ? 'mover' : 'munder', [$children, LatexParser::list($node, 'limit')], $parent),
            'matrix' => $this->table(LatexParser::rows($node), $parent),
            'eqArr' => $this->equationArray($node, $parent),
            default => null,
        };
    }

    /** Numbers, identifiers and operators, each as the token MathML has for it. */
    private function run(string $text, Element $parent): void
    {
        preg_match_all('/\d+(?:\.\d+)?|\p{L}|\S/u', $text, $matches);

        foreach ($matches[0] as $token) {
            $this->token(match (true) {
                ctype_digit($token[0]) => 'mn',
                preg_match('/^\p{L}$/u', $token) === 1 => 'mi',
                default => 'mo',
            }, $token, $parent);
        }
    }

    /** A roman name; a single letter would be italic without saying so. */
    private function upright(string $text, Element $parent): void
    {
        $identifier = $this->token('mi', $text, $parent);

        if (mb_strlen($text) === 1) {
            $identifier->setAttribute('mathvariant', 'normal');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $sub
     * @param  list<array<string, mixed>>  $sup
     */
    private function scripts(array $base, array $sub, array $sup, Element $parent, string $under = 'msub', string $over = 'msup', string $both = 'msubsup'): void
    {
        match (true) {
            $sub !== [] && $sup !== [] => $this->wrap($both, [$base, $sub, $sup], $parent),
            $sub !== [] => $this->wrap($under, [$base, $sub], $parent),
            $sup !== [] => $this->wrap($over, [$base, $sup], $parent),
            default => $this->row($base, $parent),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $children
     */
    private function over(array $children, string $accent, Element $parent): void
    {
        $this->wrap('mover', [$children, [['type' => 'run', 'text' => $accent]]], $parent)->setAttribute('accent', 'true');
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nary(array $node, Element $parent): void
    {
        $operator = [['type' => 'run', 'text' => LatexParser::string($node, 'char')]];
        $this->scripts($operator, LatexParser::list($node, 'sub'), LatexParser::list($node, 'sup'), $parent, 'munder', 'mover', 'munderover');

        foreach (LatexParser::children($node) as $child) {
            $this->node($child, $parent);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function delimited(array $node, Element $parent): void
    {
        /** @var list<list<array<string, mixed>>> $parts */
        $parts = is_array($node['parts'] ?? null) ? $node['parts'] : [];
        $begin = LatexParser::string($node, 'begin');
        $end = LatexParser::string($node, 'end');

        if ($begin !== '') {
            $this->token('mo', $begin, $parent);
        }

        foreach ($parts as $part) {
            $this->row($part, $parent);
        }

        if ($end !== '') {
            $this->token('mo', $end, $parent);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function function(array $node, Element $parent): void
    {
        foreach ([...LatexParser::list($node, 'name'), ['type' => 'run', 'text' => "\u{2061}"], ...LatexParser::children($node)] as $child) {
            $this->node($child, $parent);
        }
    }

    /**
     * Aligned equations, one row each.
     *
     * @param  array<string, mixed>  $node
     */
    private function equationArray(array $node, Element $parent): void
    {
        /** @var list<list<array<string, mixed>>> $rows */
        $rows = is_array($node['rows'] ?? null) ? $node['rows'] : [];

        $this->table(array_map(static fn(array $row): array => [$row], $rows), $parent)->setAttribute('columnalign', 'left');
    }

    /**
     * @param  list<list<list<array<string, mixed>>>>  $rows
     */
    private function table(array $rows, Element $parent): Element
    {
        $table = $this->element('mtable', $parent);

        foreach ($rows as $row) {
            $tr = $this->element('mtr', $table);

            foreach ($row as $cell) {
                $this->row($cell, $this->element('mtd', $tr));
            }
        }

        return $table;
    }
}
