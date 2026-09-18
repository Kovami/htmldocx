<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * Splitting helpers that respect quoted strings and parentheses, so values
 * like `url("a;b")`, `rgb(1, 2, 3)` or `:is(h1, h2)` stay intact.
 */
final class Tokens
{
    /**
     * @return list<string> trimmed, non-empty parts
     */
    public static function splitTopLevel(string $input, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($input);

        for ($i = 0; $i < $length; $i++) {
            $char = $input[$i];

            if ($quote !== null) {
                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $input[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif (($char === ')' || $char === ']') && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && $char === $delimiter) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return array_values(array_filter(array_map(trim(...), $parts), static fn(string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
    public static function splitWhitespace(string $input): array
    {
        return self::splitTopLevel((string) preg_replace('/\s+/', ' ', trim($input)), ' ');
    }

    public static function unquote(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return self::unescape(substr($value, 1, -1));
        }

        return $value;
    }

    /** Resolves CSS escapes: `\41` and `\"` alike. */
    public static function unescape(string $value): string
    {
        if (! str_contains($value, '\\')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\\\\(?:([0-9a-fA-F]{1,6})[ \n\t]?|(.))/su',
            static fn(array $match): string => $match[1] === ''
                ? $match[2]
                : (string) mb_chr((int) hexdec($match[1]), 'UTF-8'),
            $value,
        );
    }
}
