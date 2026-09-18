<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * Parses a declaration block (`color: red; margin: 0 4px !important`) into
 * longhand declarations. Shorthands are expanded here, once, so the cascade
 * and everything downstream only ever deal with longhand properties.
 */
final class DeclarationParser
{
    private const array SIDES = ['top', 'right', 'bottom', 'left'];

    private const array BORDER_STYLES = [
        'none', 'hidden', 'solid', 'dotted', 'dashed', 'double', 'groove', 'ridge', 'inset', 'outset',
    ];

    private const array LIST_STYLE_TYPES = [
        'disc', 'circle', 'square', 'decimal', 'decimal-leading-zero', 'lower-roman', 'upper-roman',
        'lower-alpha', 'upper-alpha', 'lower-latin', 'upper-latin', 'lower-greek', 'none',
    ];

    /**
     * @return array<string, Declaration> keyed by longhand property; later declarations win
     */
    public static function parse(string $block): array
    {
        $result = [];

        foreach (Tokens::splitTopLevel($block, ';') as $raw) {
            $colon = strpos($raw, ':');

            if ($colon === false) {
                continue;
            }

            $property = strtolower(trim(substr($raw, 0, $colon)));
            $value = trim(substr($raw, $colon + 1));
            $important = false;

            if (preg_match('/!\s*important\s*$/i', $value)) {
                $important = true;
                $value = trim((string) preg_replace('/!\s*important\s*$/i', '', $value));
            }

            if ($property === '' || $value === '' || str_starts_with($property, '--')) {
                continue;
            }

            foreach (self::expand($property, $value) as $longhand => $longhandValue) {
                unset($result[$longhand]);
                $result[$longhand] = new Declaration($longhand, $longhandValue, $important);
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public static function expand(string $property, string $value): array
    {
        return match ($property) {
            'margin', 'padding' => self::expandBox($property, '', $value),
            'margin-inline', 'padding-inline' => self::expandPair(substr($property, 0, -7), ['left', 'right'], $value),
            'margin-block', 'padding-block' => self::expandPair(substr($property, 0, -6), ['top', 'bottom'], $value),
            'margin-inline-start', 'padding-inline-start' => [str_replace('inline-start', 'left', $property) => $value],
            'margin-inline-end', 'padding-inline-end' => [str_replace('inline-end', 'right', $property) => $value],
            'margin-block-start', 'padding-block-start' => [str_replace('block-start', 'top', $property) => $value],
            'margin-block-end', 'padding-block-end' => [str_replace('block-end', 'bottom', $property) => $value],
            'border' => self::expandBorder(self::SIDES, $value),
            'border-top', 'border-right', 'border-bottom', 'border-left' => self::expandBorder([substr($property, 7)], $value),
            'border-width', 'border-style', 'border-color' => self::expandBox('border', '-' . substr($property, 7), $value),
            'background' => self::expandBackground($value),
            'text-decoration' => self::expandTextDecoration($value),
            'list-style' => self::expandListStyle($value),
            'font' => self::expandFont($value),
            default => [$property => $value],
        };
    }

    /**
     * @return array<string, string>
     */
    private static function expandBox(string $prefix, string $suffix, string $value): array
    {
        $values = Tokens::splitWhitespace($value);

        [$top, $right, $bottom, $left] = match (count($values)) {
            1 => [$values[0], $values[0], $values[0], $values[0]],
            2 => [$values[0], $values[1], $values[0], $values[1]],
            3 => [$values[0], $values[1], $values[2], $values[1]],
            default => [$values[0], $values[1], $values[2], $values[3]],
        };

        return [
            "{$prefix}-top{$suffix}" => $top,
            "{$prefix}-right{$suffix}" => $right,
            "{$prefix}-bottom{$suffix}" => $bottom,
            "{$prefix}-left{$suffix}" => $left,
        ];
    }

    /**
     * @param  array{0: string, 1: string}  $sides
     * @return array<string, string>
     */
    private static function expandPair(string $prefix, array $sides, string $value): array
    {
        $values = Tokens::splitWhitespace($value);

        return [
            "{$prefix}-{$sides[0]}" => $values[0],
            "{$prefix}-{$sides[1]}" => $values[1] ?? $values[0],
        ];
    }

    /**
     * @param  list<string>  $sides
     * @return array<string, string>
     */
    private static function expandBorder(array $sides, string $value): array
    {
        $width = 'medium';
        $style = 'none';
        $color = 'currentcolor';

        foreach (Tokens::splitWhitespace($value) as $token) {
            $lower = strtolower($token);

            if (in_array($lower, self::BORDER_STYLES, true)) {
                $style = $lower;
            } elseif (in_array($lower, ['thin', 'medium', 'thick'], true) || Length::parse($lower) !== null) {
                $width = $lower;
            } else {
                $color = $token;
            }
        }

        $result = [];

        foreach ($sides as $side) {
            $result["border-{$side}-width"] = $width;
            $result["border-{$side}-style"] = $style;
            $result["border-{$side}-color"] = $color;
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function expandBackground(string $value): array
    {
        $color = 'transparent';

        foreach (Tokens::splitWhitespace($value) as $token) {
            if (strtolower($token) === 'transparent' || Color::toHex($token) !== null) {
                $color = $token;
            }
        }

        return ['background-color' => $color];
    }

    /**
     * @return array<string, string>
     */
    private static function expandTextDecoration(string $value): array
    {
        $lines = [];
        $result = [];

        foreach (Tokens::splitWhitespace($value) as $token) {
            $lower = strtolower($token);

            if (in_array($lower, ['underline', 'overline', 'line-through', 'none'], true)) {
                $lines[] = $lower;
            } elseif (in_array($lower, ['solid', 'double', 'dotted', 'dashed', 'wavy'], true)) {
                $result['text-decoration-style'] = $lower;
            } else {
                $result['text-decoration-color'] = $token;
            }
        }

        $result['text-decoration-line'] = $lines === [] ? 'none' : implode(' ', $lines);

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private static function expandListStyle(string $value): array
    {
        foreach (Tokens::splitWhitespace($value) as $token) {
            if (in_array(strtolower($token), self::LIST_STYLE_TYPES, true)) {
                return ['list-style-type' => strtolower($token)];
            }
        }

        return [];
    }

    /**
     * `font: [style] [variant] [weight] size[/line-height] family[, family]*`
     *
     * @return array<string, string>
     */
    private static function expandFont(string $value): array
    {
        $pattern = '/^((?:(?:italic|oblique|normal|small-caps|bold|bolder|lighter|[1-9]00)\s+)*)'
            . '((?:[\d.]+(?:px|pt|em|rem|%|pc|in|cm|mm))|xx-small|x-small|small|medium|large|x-large|xx-large|xxx-large|smaller|larger)'
            . '(?:\s*\/\s*([^\s]+))?\s+(.+)$/i';

        if (! preg_match($pattern, trim($value), $m)) {
            return [];
        }

        $result = [
            'font-style' => 'normal',
            'font-variant' => 'normal',
            'font-weight' => 'normal',
            'font-size' => $m[2],
            'line-height' => $m[3] !== '' ? $m[3] : 'normal',
            'font-family' => $m[4],
        ];

        foreach (Tokens::splitWhitespace($m[1]) as $token) {
            $lower = strtolower($token);

            match (true) {
                in_array($lower, ['italic', 'oblique'], true) => $result['font-style'] = $lower,
                $lower === 'small-caps' => $result['font-variant'] = $lower,
                $lower !== 'normal' => $result['font-weight'] = $lower,
                default => null,
            };
        }

        return $result;
    }
}
