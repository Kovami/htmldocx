<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Kovami\HtmlDocx\Css\Tokens;
use Kovami\HtmlDocx\Model\ListDefinition;
use Kovami\HtmlDocx\Model\ListLevel;

final class NumberingRegistry
{
    /** Counter styles Word has no number format for, as the symbols of a CSS alphabetic system. */
    private const array LITERAL_ALPHABETS = [
        'lower-greek' => ['α', 'β', 'γ', 'δ', 'ε', 'ζ', 'η', 'θ', 'ι', 'κ', 'λ', 'μ', 'ν', 'ξ', 'ο', 'π', 'ρ', 'σ', 'τ', 'υ', 'φ', 'χ', 'ψ', 'ω'],
    ];

    /** @var list<ListDefinition> */
    private array $definitions = [];

    /**
     * @param  int  $start  the counter value of the first item; literal styles render exactly this value
     */
    public function register(string $listStyleType, int $level, int $start, int $indentLeft, int $hanging): int
    {
        $numId = count($this->definitions) + 1;
        $levels = [];

        for ($i = 0; $i <= 8; $i++) {
            [$format, $text] = $i === $level
                ? self::format($listStyleType, $i, $start)
                : self::format('decimal', $i);

            $levels[] = $i === $level
                ? new ListLevel($i, $format, $text, max(0, $start), $indentLeft, $hanging)
                : new ListLevel($i, $format, $text, 1, 720 * ($i + 1), 360);
        }

        $this->definitions[] = new ListDefinition($numId, $levels, $level);

        return $numId;
    }

    /**
     * @return list<ListDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * Whether the style's marker text is fixed per definition, so every item
     * needs a definition of its own.
     */
    public static function isLiteral(string $listStyleType): bool
    {
        return isset(self::LITERAL_ALPHABETS[$listStyleType]) || self::marker($listStyleType) !== null;
    }

    /**
     * The marker text of a `list-style-type` written as a CSS string, e.g.
     * `list-style-type: "1.2. "`; null for the counter-style keywords.
     */
    public static function marker(string $listStyleType): ?string
    {
        if ($listStyleType === '' || ($listStyleType[0] !== '"' && $listStyleType[0] !== "'")) {
            return null;
        }

        return rtrim(Tokens::unquote($listStyleType), ' ');
    }

    /**
     * @return array{0: string, 1: string} ST_NumberFormat value and level text
     */
    public static function format(string $listStyleType, int $level, int $value = 1): array
    {
        $placeholder = '%' . ($level + 1) . '.';
        $marker = self::marker($listStyleType);

        if ($marker !== null) {
            return ['none', $marker];
        }

        if (self::isLiteral($listStyleType)) {
            return $value < 1
                ? ['decimal', $placeholder]
                : ['none', self::alphabetic($value, self::LITERAL_ALPHABETS[$listStyleType]) . '.'];
        }

        return match ($listStyleType) {
            'disc' => ['bullet', "\u{2022}"],
            'circle' => ['bullet', "\u{25E6}"],
            'square' => ['bullet', "\u{25AA}"],
            'none' => ['none', ''],
            'decimal-leading-zero' => ['decimalZero', $placeholder],
            'lower-alpha', 'lower-latin' => ['lowerLetter', $placeholder],
            'upper-alpha', 'upper-latin' => ['upperLetter', $placeholder],
            'lower-roman' => ['lowerRoman', $placeholder],
            'upper-roman' => ['upperRoman', $placeholder],
            default => ['decimal', $placeholder],
        };
    }

    /**
     * @param  non-empty-list<string>  $symbols
     */
    private static function alphabetic(int $value, array $symbols): string
    {
        $text = '';

        while ($value > 0) {
            $value--;
            $text = $symbols[$value % count($symbols)] . $text;
            $value = intdiv($value, count($symbols));
        }

        return $text;
    }
}
