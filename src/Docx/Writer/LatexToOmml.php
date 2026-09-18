<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use Kovami\HtmlDocx\Docx\Reader\OmmlToLatex;
use Kovami\HtmlDocx\Model\RunProperties;

/**
 * Writes LaTeX (the dialect KaTeX renders, which is what rich-text editors
 * store) as Office Math, so a formula reaches Word as an equation it can
 * lay out and edit rather than as its source text.
 *
 * It is the inverse of {@see OmmlToLatex}, and
 * covers what that one produces: fractions, scripts, radicals, n-ary
 * operators, delimiters, functions, accents, bars, boxes, matrices and
 * equation arrays. Anything else becomes the text it is written with.
 */
final class LatexToOmml
{
    /** Commands naming one character, as Word stores it. */
    private const array SYMBOLS = [
        'alpha' => 'α', 'beta' => 'β', 'gamma' => 'γ', 'delta' => 'δ', 'epsilon' => 'ε', 'varepsilon' => 'ε',
        'zeta' => 'ζ', 'eta' => 'η', 'theta' => 'θ', 'vartheta' => 'ϑ', 'iota' => 'ι', 'kappa' => 'κ',
        'lambda' => 'λ', 'mu' => 'μ', 'nu' => 'ν', 'xi' => 'ξ', 'pi' => 'π', 'varpi' => 'ϖ', 'rho' => 'ρ',
        'varrho' => 'ϱ', 'sigma' => 'σ', 'varsigma' => 'ς', 'tau' => 'τ', 'upsilon' => 'υ', 'phi' => 'φ',
        'varphi' => 'ϕ', 'chi' => 'χ', 'psi' => 'ψ', 'omega' => 'ω', 'Gamma' => 'Γ', 'Delta' => 'Δ',
        'Theta' => 'Θ', 'Lambda' => 'Λ', 'Xi' => 'Ξ', 'Pi' => 'Π', 'Sigma' => 'Σ', 'Upsilon' => 'Υ',
        'Phi' => 'Φ', 'Psi' => 'Ψ', 'Omega' => 'Ω',
        'infty' => '∞', 'le' => '≤', 'leq' => '≤', 'ge' => '≥', 'geq' => '≥', 'ne' => '≠', 'neq' => '≠',
        'approx' => '≈', 'equiv' => '≡', 'propto' => '∝', 'pm' => '±', 'mp' => '∓', 'times' => '×',
        'div' => '÷', 'cdot' => '⋅', 'circ' => '∘', 'ast' => '∗', 'ldots' => '…', 'dots' => '…',
        'cdots' => '⋯', 'vdots' => '⋮', 'ddots' => '⋱', 'in' => '∈', 'notin' => '∉', 'ni' => '∋',
        'subset' => '⊂', 'supset' => '⊃', 'subseteq' => '⊆', 'supseteq' => '⊇', 'cup' => '∪', 'cap' => '∩',
        'emptyset' => '∅', 'forall' => '∀', 'exists' => '∃', 'nexists' => '∄', 'neg' => '¬', 'wedge' => '∧',
        'vee' => '∨', 'partial' => '∂', 'nabla' => '∇', 'angle' => '∠', 'perp' => '⊥', 'parallel' => '∥',
        'to' => '→', 'rightarrow' => '→', 'leftarrow' => '←', 'leftrightarrow' => '↔', 'Rightarrow' => '⇒',
        'Leftarrow' => '⇐', 'Leftrightarrow' => '⇔', 'uparrow' => '↑', 'downarrow' => '↓', 'mapsto' => '↦',
        'hbar' => 'ℏ', 'ell' => 'ℓ', 'surd' => '√', 'sim' => '∼', 'cong' => '≅', 'll' => '≪', 'gg' => '≫',
        'oplus' => '⊕', 'otimes' => '⊗', 'dagger' => '†',
    ];

    /** Sets whose blackboard-bold letters Word stores as single characters. */
    private const array BLACKBOARD = ['R' => 'ℝ', 'N' => 'ℕ', 'Z' => 'ℤ', 'Q' => 'ℚ', 'C' => 'ℂ'];

    /** Operators that take limits below and above. */
    private const array NARY = [
        'sum' => '∑', 'prod' => '∏', 'coprod' => '∐', 'int' => '∫', 'iint' => '∬', 'iiint' => '∭',
        'oint' => '∮', 'bigcup' => '⋃', 'bigcap' => '⋂', 'bigvee' => '⋁', 'bigwedge' => '⋀',
    ];

    private const array ACCENTS = [
        'hat' => "\u{0302}", 'widehat' => "\u{0302}", 'tilde' => "\u{0303}", 'widetilde' => "\u{0303}",
        'dot' => "\u{0307}", 'ddot' => "\u{0308}", 'bar' => "\u{0304}", 'vec' => "\u{20D7}",
        'overleftarrow' => "\u{20D6}", 'breve' => "\u{0306}", 'check' => "\u{030C}", 'acute' => "\u{0301}",
        'grave' => "\u{0300}",
    ];

    private const array FUNCTIONS = [
        'sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'arcsin', 'arccos', 'arctan', 'sinh', 'cosh', 'tanh', 'coth',
        'log', 'ln', 'lg', 'exp', 'lim', 'max', 'min', 'sup', 'inf', 'det', 'gcd', 'deg', 'arg', 'dim', 'ker', 'hom', 'Pr',
    ];

    /** Delimiters `\left` and `\right` accept, as the character Word stores. */
    private const array DELIMITERS = [
        '\{' => '{', '\}' => '}', '\|' => '‖', '\langle' => '⟨', '\rangle' => '⟩', '\lfloor' => '⌊',
        '\rfloor' => '⌋', '\lceil' => '⌈', '\rceil' => '⌉', '\vert' => '|', '\Vert' => '‖', '.' => '',
    ];

    /** Environments that wrap their matrix in delimiters. */
    private const array MATRIX_DELIMITERS = [
        'pmatrix' => ['(', ')'], 'bmatrix' => ['[', ']'], 'Bmatrix' => ['{', '}'],
        'vmatrix' => ['|', '|'], 'Vmatrix' => ['‖', '‖'],
    ];

    /** @var list<string> */
    private array $tokens = [];

    private int $position = 0;

    private function __construct(
        private readonly XmlBuilder $xml,
        private readonly RunProperties $properties,
    ) {}

    /** Writes the contents of one `m:oMath` element. */
    public static function write(XmlBuilder $xml, string $latex, RunProperties $properties): void
    {
        $writer = new self($xml, $properties);
        $writer->tokens = self::tokenize($latex);
        $writer->emit($writer->parseList());
    }

    /**
     * @return list<string>
     */
    private static function tokenize(string $latex): array
    {
        // Whitespace is a token of its own: math ignores it, `\text{…}` does not.
        preg_match_all('/\\\\\\\\|\\\\[A-Za-z]+|\\\\.|[{}^_&\[\]]|\s+|\S/u', $latex, $matches);

        /** @var list<string> */
        return $matches[0];
    }

    /**
     * Nodes until the end of the input or a token that closes the list.
     *
     * @return list<array<string, mixed>>
     */
    private function parseList(?string $stop = null): array
    {
        $nodes = [];

        while ($this->skipSpace() < count($this->tokens)) {
            $token = $this->tokens[$this->position];

            if ($token === $stop || $token === '}' || $token === '\\right' || $token === '\\end' || $token === '&' || $token === '\\\\') {
                break;
            }

            $node = $this->parseAtom();

            if ($node !== null) {
                $nodes[] = $this->parseScripts($node);
            }
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function parseScripts(array $base): array
    {
        $sub = null;
        $sup = null;

        while ($this->skipSpace() >= 0 && in_array($this->tokens[$this->position] ?? '', ['^', '_'], true)) {
            $script = $this->tokens[$this->position++];
            $argument = $this->parseArgument();

            if ($script === '^') {
                $sup = $argument;
            } else {
                $sub = $argument;
            }
        }

        if ($sub === null && $sup === null) {
            return $base;
        }

        return ['type' => 'scripts', 'base' => [$base], 'sub' => $sub, 'sup' => $sup];
    }

    /**
     * One argument: a braced group, or the single atom that follows.
     *
     * @return list<array<string, mixed>>
     */
    private function parseArgument(): array
    {
        $this->skipSpace();

        if (($this->tokens[$this->position] ?? '') === '{') {
            $this->position++;
            $nodes = $this->parseList();
            $this->expect('}');

            return $nodes;
        }

        $atom = $this->parseAtom();

        return $atom === null ? [] : [$atom];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseAtom(): ?array
    {
        $this->skipSpace();
        $token = $this->tokens[$this->position++] ?? null;

        if ($token === null) {
            return null;
        }

        if ($token === '{') {
            $nodes = $this->parseList();
            $this->expect('}');

            return ['type' => 'group', 'children' => $nodes];
        }

        if (! str_starts_with($token, '\\')) {
            return ['type' => 'run', 'text' => $token];
        }

        $command = substr($token, 1);

        return match (true) {
            $command === 'frac' || $command === 'dfrac' || $command === 'tfrac' => [
                'type' => 'frac', 'num' => $this->parseArgument(), 'den' => $this->parseArgument(),
            ],
            $command === 'sqrt' => $this->parseRadical(),
            $command === 'left' => $this->parseDelimited(),
            $command === 'begin' => $this->parseEnvironment(),
            $command === 'text' || $command === 'textrm' || $command === 'mbox' => ['type' => 'text', 'text' => $this->rawArgument()],
            $command === 'mathrm' || $command === 'operatorname' => ['type' => 'upright', 'text' => $this->rawArgument()],
            $command === 'mathbb' => ['type' => 'run', 'text' => self::BLACKBOARD[$this->rawArgument()] ?? $this->rawArgument()],
            $command === 'boxed' => ['type' => 'box', 'children' => $this->parseArgument()],
            $command === 'overline' => ['type' => 'bar', 'position' => 'top', 'children' => $this->parseArgument()],
            $command === 'underline' => ['type' => 'bar', 'position' => 'bot', 'children' => $this->parseArgument()],
            $command === 'overset' => ['type' => 'limit', 'over' => true, 'limit' => $this->parseArgument(), 'children' => $this->parseArgument()],
            $command === 'underset' => ['type' => 'limit', 'over' => false, 'limit' => $this->parseArgument(), 'children' => $this->parseArgument()],
            isset(self::ACCENTS[$command]) => ['type' => 'accent', 'char' => self::ACCENTS[$command], 'children' => $this->parseArgument()],
            isset(self::NARY[$command]) => $this->parseNary(self::NARY[$command]),
            in_array($command, self::FUNCTIONS, true) => $this->parseFunction($command),
            isset(self::SYMBOLS[$command]) => ['type' => 'run', 'text' => self::SYMBOLS[$command]],
            $command === 'backslash' => ['type' => 'run', 'text' => '\\'],
            $command === 'quad' || $command === 'qquad' || $command === ',' || $command === ';' || $command === ' ' => ['type' => 'run', 'text' => ' '],
            // An escape (`\{`, `\%`) stands for the character itself; a command
            // nothing here knows becomes its name, which is all Word could show.
            default => ['type' => 'run', 'text' => $command],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRadical(): array
    {
        $degree = [];

        if (($this->tokens[$this->position] ?? '') === '[') {
            $this->position++;
            $degree = $this->parseList(']');
            $this->expect(']');
        }

        return ['type' => 'rad', 'degree' => $degree, 'children' => $this->parseArgument()];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDelimited(): array
    {
        $begin = self::delimiter($this->tokens[$this->position++] ?? '(');
        $children = $this->parseList();
        $end = ')';

        if (($this->tokens[$this->position] ?? '') === '\\right') {
            $this->position++;
            $end = self::delimiter($this->tokens[$this->position++] ?? ')');
        }

        return ['type' => 'delim', 'begin' => $begin, 'end' => $end, 'parts' => [$children]];
    }

    /**
     * `\begin{matrix}` and its relatives, and `\begin{aligned}`.
     *
     * @return array<string, mixed>
     */
    private function parseEnvironment(): array
    {
        $name = $this->rawArgument();
        $rows = [[]];
        $cells = [[]];

        while ($this->skipSpace() < count($this->tokens)) {
            $token = $this->tokens[$this->position] ?? '';

            if ($token === '\\end') {
                $this->position++;
                $this->rawArgument();

                break;
            }

            if ($token === '&') {
                $this->position++;
                $cells[] = [];

                continue;
            }

            if ($token === '\\\\') {
                $this->position++;
                $rows[count($rows) - 1] = $cells;
                $rows[] = [];
                $cells = [[]];

                continue;
            }

            $node = $this->parseAtom();

            if ($node !== null) {
                $cells[count($cells) - 1][] = $this->parseScripts($node);
            }
        }

        $rows[count($rows) - 1] = $cells;
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row !== []));

        if ($name === 'aligned' || $name === 'align' || $name === 'cases') {
            return ['type' => 'eqArr', 'rows' => array_map(static fn (array $row): array => array_merge(...$row), $rows)];
        }

        $matrix = ['type' => 'matrix', 'rows' => $rows];
        $delimiters = self::MATRIX_DELIMITERS[$name] ?? null;

        return $delimiters === null
            ? $matrix
            : ['type' => 'delim', 'begin' => $delimiters[0], 'end' => $delimiters[1], 'parts' => [[$matrix]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseNary(string $operator): array
    {
        $sub = [];
        $sup = [];

        while ($this->skipSpace() >= 0 && in_array($this->tokens[$this->position] ?? '', ['^', '_'], true)) {
            $script = $this->tokens[$this->position++];
            $argument = $this->parseArgument();

            if ($script === '^') {
                $sup = $argument;
            } else {
                $sub = $argument;
            }
        }

        $operand = $this->parseAtom();

        return [
            'type' => 'nary',
            'char' => $operator,
            'sub' => $sub,
            'sup' => $sup,
            'children' => $operand === null ? [] : [$this->parseScripts($operand)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFunction(string $name): array
    {
        $limits = $this->parseScripts(['type' => 'upright', 'text' => $name]);
        $operand = $this->parseAtom();

        return [
            'type' => 'func',
            'name' => [$limits],
            'children' => $operand === null ? [] : [$this->parseScripts($operand)],
        ];
    }

    /** The text of the next argument, with no interpretation. */
    private function rawArgument(): string
    {
        if (($this->tokens[$this->position] ?? '') !== '{') {
            return $this->tokens[$this->position++] ?? '';
        }

        $this->position++;
        $text = '';

        while ($this->position < count($this->tokens) && $this->tokens[$this->position] !== '}') {
            $token = $this->tokens[$this->position++];
            $text .= str_starts_with($token, '\\') && strlen($token) === 2 ? substr($token, 1) : $token;
        }

        $this->expect('}');

        return $text;
    }

    /** Moves past whitespace tokens and returns where the parser now stands. */
    private function skipSpace(): int
    {
        while (($this->tokens[$this->position] ?? null) !== null && trim($this->tokens[$this->position]) === '') {
            $this->position++;
        }

        return $this->position;
    }

    private function expect(string $token): void
    {
        if (($this->tokens[$this->position] ?? '') === $token) {
            $this->position++;
        }
    }

    private static function delimiter(string $token): string
    {
        return self::DELIMITERS[$token] ?? $token;
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
            'group' => $this->emit(self::children($node)),
            'text' => $this->run(self::string($node, 'text'), 'nor'),
            'upright' => $this->run(self::string($node, 'text'), 'upright'),
            'frac' => $this->wrap('m:f', ['m:num' => self::list($node, 'num'), 'm:den' => self::list($node, 'den')]),
            'scripts' => $this->scripts($node),
            'rad' => $this->radical($node),
            'nary' => $this->nary($node),
            'delim' => $this->delimited($node),
            'func' => $this->wrap('m:func', ['m:fName' => self::list($node, 'name'), 'm:e' => self::children($node)]),
            'accent' => $this->wrapWith('m:acc', 'm:chr', self::string($node, 'char'), self::children($node)),
            'bar' => $this->wrapWith('m:bar', 'm:pos', self::string($node, 'position'), self::children($node)),
            'box' => $this->wrap('m:borderBox', ['m:e' => self::children($node)]),
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
        $sub = self::list($node, 'sub');
        $sup = self::list($node, 'sup');

        $element = match (true) {
            $sub !== [] && $sup !== [] => 'm:sSubSup',
            $sub !== [] => 'm:sSub',
            default => 'm:sSup',
        };

        $parts = ['m:e' => self::list($node, 'base')];

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
        $degree = self::list($node, 'degree');

        $this->xml->open('m:rad')->open('m:radPr')
            ->leaf('m:degHide', ['m:val' => $degree === [] ? '1' : '0'])
            ->close();

        $this->part('m:deg', $degree);
        $this->part('m:e', self::children($node));
        $this->xml->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function nary(array $node): void
    {
        $sub = self::list($node, 'sub');
        $sup = self::list($node, 'sup');

        $this->xml->open('m:nary')->open('m:naryPr')
            ->leaf('m:chr', ['m:val' => self::string($node, 'char')])
            ->leaf('m:limLoc', ['m:val' => 'undOvr'])
            ->leaf('m:subHide', ['m:val' => $sub === [] ? '1' : '0'])
            ->leaf('m:supHide', ['m:val' => $sup === [] ? '1' : '0'])
            ->close();

        $this->part('m:sub', $sub);
        $this->part('m:sup', $sup);
        $this->part('m:e', self::children($node));
        $this->xml->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function delimited(array $node): void
    {
        $this->xml->open('m:d')->open('m:dPr')
            ->leaf('m:begChr', ['m:val' => self::string($node, 'begin')])
            ->leaf('m:endChr', ['m:val' => self::string($node, 'end')])
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
        $this->part('m:e', self::children($node));
        $this->part('m:lim', self::list($node, 'limit'));
        $this->xml->close();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function matrix(array $node): void
    {
        $this->xml->open('m:m');

        foreach (self::rows($node) as $row) {
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
            ->open($element.'Pr')->leaf($property, ['m:val' => $value])->close();

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

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function children(array $node): array
    {
        return self::list($node, 'children');
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function list(array $node, string $key): array
    {
        $value = $node[$key] ?? null;

        /** @var list<array<string, mixed>> */
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<list<list<array<string, mixed>>>>
     */
    private static function rows(array $node): array
    {
        /** @var list<list<list<array<string, mixed>>>> */
        return is_array($node['rows'] ?? null) ? array_values($node['rows']) : [];
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function string(array $node, string $key): string
    {
        $value = $node[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
