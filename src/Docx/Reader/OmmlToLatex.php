<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Dom\Element;
use Kovami\HtmlDocx\Docx\Namespaces;

/**
 * Converts Office Math (OMML, `m:oMath`) into LaTeX that KaTeX renders:
 * fractions, scripts, radicals, n-ary operators, delimiters, functions,
 * accents, bars, limits, group characters, boxes, matrices and equation
 * arrays. Unknown constructs degrade to their text content.
 */
final class OmmlToLatex
{
    private const array SYMBOLS = [
        'α' => '\alpha', 'β' => '\beta', 'γ' => '\gamma', 'δ' => '\delta', 'ε' => '\epsilon', 'ϵ' => '\epsilon', 'ζ' => '\zeta',
        'η' => '\eta', 'θ' => '\theta', 'ϑ' => '\vartheta', 'ι' => '\iota', 'κ' => '\kappa', 'λ' => '\lambda', 'μ' => '\mu',
        'ν' => '\nu', 'ξ' => '\xi', 'π' => '\pi', 'ϖ' => '\varpi', 'ρ' => '\rho', 'ϱ' => '\varrho', 'σ' => '\sigma',
        'ς' => '\varsigma', 'τ' => '\tau', 'υ' => '\upsilon', 'φ' => '\phi', 'ϕ' => '\phi', 'χ' => '\chi', 'ψ' => '\psi',
        'ω' => '\omega', 'Γ' => '\Gamma', 'Δ' => '\Delta', 'Θ' => '\Theta', 'Λ' => '\Lambda', 'Ξ' => '\Xi', 'Π' => '\Pi',
        'Σ' => '\Sigma', 'Υ' => '\Upsilon', 'Φ' => '\Phi', 'Ψ' => '\Psi', 'Ω' => '\Omega',
        '∞' => '\infty', '≤' => '\le', '≥' => '\ge', '≠' => '\ne', '≈' => '\approx', '≡' => '\equiv', '∝' => '\propto',
        '±' => '\pm', '∓' => '\mp', '×' => '\times', '÷' => '\div', '⋅' => '\cdot', '·' => '\cdot', '∘' => '\circ',
        '∗' => '\ast', '−' => '-', '…' => '\ldots', '⋯' => '\cdots', '⋮' => '\vdots', '⋱' => '\ddots',
        '∈' => '\in', '∉' => '\notin', '∋' => '\ni', '⊂' => '\subset', '⊃' => '\supset', '⊆' => '\subseteq', '⊇' => '\supseteq',
        '∪' => '\cup', '∩' => '\cap', '∅' => '\emptyset', '∀' => '\forall', '∃' => '\exists', '∄' => '\nexists', '¬' => '\neg',
        '∧' => '\wedge', '∨' => '\vee', '∂' => '\partial', '∇' => '\nabla', '∠' => '\angle', '⊥' => '\perp', '∥' => '\parallel',
        '→' => '\to', '←' => '\leftarrow', '↔' => '\leftrightarrow', '⇒' => '\Rightarrow', '⇐' => '\Leftarrow', '⇔' => '\Leftrightarrow',
        '↑' => '\uparrow', '↓' => '\downarrow', '↦' => '\mapsto', '°' => '^\circ', '′' => "'", '″' => "''",
        'ℝ' => '\mathbb{R}', 'ℕ' => '\mathbb{N}', 'ℤ' => '\mathbb{Z}', 'ℚ' => '\mathbb{Q}', 'ℂ' => '\mathbb{C}',
        'ℏ' => '\hbar', 'ℓ' => '\ell', '√' => '\surd', '∼' => '\sim', '≅' => '\cong', '≪' => '\ll', '≫' => '\gg',
        '⊕' => '\oplus', '⊗' => '\otimes', '†' => '\dagger', '∑' => '\sum', '∏' => '\prod', '∐' => '\coprod', '∫' => '\int',
        '∬' => '\iint', '∭' => '\iiint', '∮' => '\oint', '⋃' => '\bigcup', '⋂' => '\bigcap', '⋁' => '\bigvee', '⋀' => '\bigwedge',
    ];

    private const array FUNCTIONS = [
        'sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'arcsin', 'arccos', 'arctan', 'sinh', 'cosh', 'tanh', 'coth',
        'log', 'ln', 'lg', 'exp', 'lim', 'max', 'min', 'sup', 'inf', 'det', 'gcd', 'deg', 'arg', 'dim', 'ker', 'hom', 'Pr',
    ];

    private const array ACCENTS = [
        "\u{0302}" => '\hat', '^' => '\hat', "\u{0303}" => '\tilde', '~' => '\tilde', "\u{0307}" => '\dot', "\u{0308}" => '\ddot',
        "\u{0304}" => '\bar', "\u{0305}" => '\overline', "\u{20D7}" => '\vec', "\u{20D6}" => '\overleftarrow', "\u{0306}" => '\breve',
        "\u{030C}" => '\check', "\u{0301}" => '\acute', "\u{0300}" => '\grave',
    ];

    private const array DELIMITERS = [
        '(' => '(', ')' => ')', '[' => '[', ']' => ']', '{' => '\{', '}' => '\}', '|' => '|', '‖' => '\|',
        '⟨' => '\langle', '⟩' => '\rangle', '⌊' => '\lfloor', '⌋' => '\rfloor', '⌈' => '\lceil', '⌉' => '\rceil', '' => '.',
    ];

    public static function convert(Element $math): string
    {
        return trim((string) preg_replace('/\s+/', ' ', self::children($math)));
    }

    private static function children(?Element $element): string
    {
        $latex = '';

        foreach (Xml::children($element) as $child) {
            $latex .= self::node($child);
        }

        return $latex;
    }

    private static function node(Element $element): string
    {
        if ($element->namespaceURI === Namespaces::W) {
            return match ($element->localName) {
                'r' => self::text((string) Xml::child($element, 't')?->textContent, false),
                'ins', 'smartTag', 'customXml' => self::children($element),
                default => '',
            };
        }

        if ($element->namespaceURI !== Namespaces::M) {
            return '';
        }

        return match ($element->localName) {
            'oMathPara', 'oMath', 'e', 'num', 'den', 'sub', 'sup', 'deg', 'lim', 'fName', 'box', 'phant' => self::children($element),
            'r' => self::run($element),
            'f' => self::fraction($element),
            'sSup' => self::group($element, 'e') . '^' . self::braced($element, 'sup'),
            'sSub' => self::group($element, 'e') . '_' . self::braced($element, 'sub'),
            'sSubSup' => self::group($element, 'e') . '_' . self::braced($element, 'sub') . '^' . self::braced($element, 'sup'),
            'sPre' => '{}_' . self::braced($element, 'sub') . '^' . self::braced($element, 'sup') . self::group($element, 'e'),
            'rad' => self::radical($element),
            'nary' => self::nary($element),
            'd' => self::delimiter($element),
            'func' => self::func($element),
            'acc' => self::accent($element),
            'bar' => (self::property($element, 'barPr', 'pos') === 'bot' ? '\underline' : '\overline') . self::braced($element, 'e'),
            'limLow' => self::limit($element, '\underset'),
            'limUpp' => self::limit($element, '\overset'),
            'groupChr' => self::groupCharacter($element),
            'borderBox' => '\boxed' . self::braced($element, 'e'),
            'm' => self::matrix($element),
            'eqArr' => '\begin{aligned}' . implode(' \\\\ ', array_map(self::children(...), Xml::children($element, 'e', Namespaces::M))) . '\end{aligned}',
            default => str_ends_with((string) $element->localName, 'Pr') ? '' : self::children($element),
        };
    }

    private static function run(Element $run): string
    {
        $text = '';

        foreach (Xml::children($run, 't', Namespaces::M) as $t) {
            $text .= $t->textContent;
        }

        $properties = Xml::child($run, 'rPr', Namespaces::M);

        if (Xml::child($properties, 'nor', Namespaces::M) !== null) {
            return '\text{' . self::escapeText($text) . '}';
        }

        return self::text($text, self::property($run, 'rPr', 'sty') === 'p');
    }

    private static function text(string $text, bool $upright): string
    {
        $trimmed = trim($text);

        if (in_array($trimmed, self::FUNCTIONS, true)) {
            return '\\' . $trimmed . ' ';
        }

        if ($upright && preg_match('/^[A-Za-z]{2,}$/', $trimmed) === 1) {
            return '\mathrm{' . $trimmed . '}';
        }

        $latex = '';

        foreach (mb_str_split($text) as $character) {
            $latex .= match (true) {
                isset(self::SYMBOLS[$character]) => self::SYMBOLS[$character] . (preg_match('/[a-z]$/i', self::SYMBOLS[$character]) === 1 ? ' ' : ''),
                in_array($character, ['{', '}', '#', '$', '%', '&', '_'], true) => '\\' . $character,
                $character === '\\' => '\backslash ',
                $character === '^' => '\hat{}',
                $character === '~' => '\sim ',
                default => $character,
            };
        }

        return $latex;
    }

    private static function fraction(Element $fraction): string
    {
        $numerator = self::children(Xml::child($fraction, 'num', Namespaces::M));
        $denominator = self::children(Xml::child($fraction, 'den', Namespaces::M));

        return match (self::property($fraction, 'fPr', 'type')) {
            'lin' => "{$numerator}/{$denominator}",
            'noBar' => '\genfrac{}{}{0pt}{}{' . $numerator . '}{' . $denominator . '}',
            'skw' => '{}^{' . $numerator . '}/_{' . $denominator . '}',
            default => '\frac{' . $numerator . '}{' . $denominator . '}',
        };
    }

    private static function radical(Element $radical): string
    {
        $degree = self::children(Xml::child($radical, 'deg', Namespaces::M));
        $hidden = in_array(self::property($radical, 'radPr', 'degHide'), ['1', 'on', 'true'], true);

        return '\sqrt' . ($hidden || trim($degree) === '' ? '' : '[' . $degree . ']') . self::braced($radical, 'e');
    }

    private static function nary(Element $nary): string
    {
        $character = self::property($nary, 'naryPr', 'chr') ?? '∫';
        $operator = self::SYMBOLS[$character] ?? self::text($character, false);
        $latex = $operator;

        if (! in_array(self::property($nary, 'naryPr', 'subHide'), ['1', 'on', 'true'], true)) {
            $sub = self::children(Xml::child($nary, 'sub', Namespaces::M));
            $latex .= trim($sub) === '' ? '' : '_{' . $sub . '}';
        }

        if (! in_array(self::property($nary, 'naryPr', 'supHide'), ['1', 'on', 'true'], true)) {
            $sup = self::children(Xml::child($nary, 'sup', Namespaces::M));
            $latex .= trim($sup) === '' ? '' : '^{' . $sup . '}';
        }

        return $latex . ' ' . self::group($nary, 'e');
    }

    private static function delimiter(Element $delimiter): string
    {
        $properties = Xml::child($delimiter, 'dPr', Namespaces::M);
        $begin = self::optionalProperty($properties, 'begChr') ?? '(';
        $end = self::optionalProperty($properties, 'endChr') ?? ')';
        $separator = self::optionalProperty($properties, 'sepChr') ?? '|';
        $parts = array_map(self::children(...), Xml::children($delimiter, 'e', Namespaces::M));

        return '\left' . (self::DELIMITERS[$begin] ?? $begin) . ' '
            . implode(' ' . ($separator === '|' ? '\mid' : self::text($separator, false)) . ' ', $parts)
            . ' \right' . (self::DELIMITERS[$end] ?? $end);
    }

    private static function func(Element $function): string
    {
        $name = trim(self::children(Xml::child($function, 'fName', Namespaces::M)));

        if (preg_match('/^[A-Za-z]+$/', $name) === 1) {
            $name = in_array($name, self::FUNCTIONS, true) ? '\\' . $name : '\operatorname{' . $name . '}';
        }

        return $name . ' ' . self::group($function, 'e');
    }

    private static function accent(Element $accent): string
    {
        $character = self::property($accent, 'accPr', 'chr') ?? "\u{0302}";

        return (self::ACCENTS[$character] ?? '\hat') . self::braced($accent, 'e');
    }

    private static function limit(Element $limit, string $command): string
    {
        $base = trim(self::children(Xml::child($limit, 'e', Namespaces::M)));
        $bound = self::children(Xml::child($limit, 'lim', Namespaces::M));

        if ($command === '\underset' && in_array($base, ['\lim', '\max', '\min', '\sup', '\inf'], true)) {
            return $base . '_{' . $bound . '}';
        }

        return $command . '{' . $bound . '}{' . $base . '}';
    }

    private static function groupCharacter(Element $group): string
    {
        $character = self::property($group, 'groupChrPr', 'chr') ?? '⏟';
        $top = self::property($group, 'groupChrPr', 'pos') === 'top';

        $command = match (true) {
            $character === '⏞' || ($top && $character === '⏟') => '\overbrace',
            $character === '←' => '\overleftarrow',
            $character === '→' => '\overrightarrow',
            default => $top ? '\overbrace' : '\underbrace',
        };

        return $command . self::braced($group, 'e');
    }

    private static function matrix(Element $matrix): string
    {
        $rows = [];

        foreach (Xml::children($matrix, 'mr', Namespaces::M) as $row) {
            $rows[] = implode(' & ', array_map(self::children(...), Xml::children($row, 'e', Namespaces::M)));
        }

        return '\begin{matrix}' . implode(' \\\\ ', $rows) . '\end{matrix}';
    }

    private static function group(Element $parent, string $child): string
    {
        $content = self::children(Xml::child($parent, $child, Namespaces::M));

        return mb_strlen(trim($content)) <= 1 || preg_match('/^\\\\[A-Za-z]+\s*$/', trim($content)) === 1 ? $content : '{' . $content . '}';
    }

    private static function braced(Element $parent, string $child): string
    {
        return '{' . self::children(Xml::child($parent, $child, Namespaces::M)) . '}';
    }

    private static function property(Element $element, string $container, string $name): ?string
    {
        return self::optionalProperty(Xml::child($element, $container, Namespaces::M), $name);
    }

    private static function optionalProperty(?Element $properties, string $name): ?string
    {
        $property = Xml::child($properties, $name, Namespaces::M);

        return $property === null ? null : ($property->getAttributeNS(Namespaces::M, 'val') ?? '');
    }

    private static function escapeText(string $text): string
    {
        return strtr($text, ['\\' => '\textbackslash{}', '{' => '\{', '}' => '\}', '#' => '\#', '$' => '\$', '%' => '\%', '&' => '\&', '_' => '\_', '^' => '\^{}', '~' => '\~{}']);
    }
}
