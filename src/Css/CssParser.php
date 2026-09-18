<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * Stylesheet parser. Understands rulesets with arbitrary selector lists,
 * `!important`, comments, strings, and nested at-rule blocks. `@media` and
 * `@supports` contents are kept when the rule applies to a printed document
 * (no query, `all`, or `print`); every other at-rule is skipped.
 */
final class CssParser
{
    /**
     * @param  int  $order  running source-order counter shared across stylesheets
     * @return list<CssRule>
     */
    public static function parse(string $css, Origin $origin, int &$order): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = str_replace(['<!--', '-->'], '', $css);

        return self::parseRules($css, $origin, $order);
    }

    /**
     * @return list<CssRule>
     */
    private static function parseRules(string $css, Origin $origin, int &$order): array
    {
        $rules = [];
        $position = 0;
        $length = strlen($css);

        while ($position < $length) {
            [$prelude, $terminator, $position] = self::readUntil($css, $position, ['{', ';']);
            $prelude = trim($prelude);

            if ($terminator !== '{') {
                continue;
            }

            [$body, $position] = self::readBlock($css, $position);

            if (str_starts_with($prelude, '@')) {
                if (self::atRuleApplies($prelude)) {
                    array_push($rules, ...self::parseRules($body, $origin, $order));
                }

                continue;
            }

            $declarations = DeclarationParser::parse($body);

            if ($declarations === []) {
                continue;
            }

            foreach (Tokens::splitTopLevel($prelude, ',') as $selectorText) {
                $selector = Selector::parse($selectorText);

                if ($selector !== null) {
                    $rules[] = new CssRule($selector, $declarations, $origin, $order++);
                }
            }
        }

        return $rules;
    }

    private static function atRuleApplies(string $prelude): bool
    {
        if (! preg_match('/^@(media|supports)\b(.*)$/is', $prelude, $m)) {
            return false;
        }

        if (strtolower($m[1]) === 'supports') {
            return true;
        }

        $query = strtolower(trim($m[2]));

        return $query === '' || (bool) preg_match('/\b(all|print)\b/', $query) && ! str_contains($query, 'not ');
    }

    /**
     * @param  list<string>  $stops
     * @return array{0: string, 1: string|null, 2: int} consumed text, the stop character found (or null at end), next position
     */
    private static function readUntil(string $css, int $position, array $stops): array
    {
        $length = strlen($css);
        $quote = null;
        $depth = 0;
        $start = $position;

        for (; $position < $length; $position++) {
            $char = $css[$position];

            if ($quote !== null) {
                if ($char === '\\') {
                    $position++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && $depth > 0) {
                $depth--;
            } elseif ($depth === 0 && in_array($char, $stops, true)) {
                return [substr($css, $start, $position - $start), $char, $position + 1];
            } elseif ($char === '}') {
                return [substr($css, $start, $position - $start), null, $position + 1];
            }
        }

        return [substr($css, $start), null, $length];
    }

    /**
     * @return array{0: string, 1: int} block contents (without braces) and the position after the closing brace
     */
    private static function readBlock(string $css, int $position): array
    {
        $length = strlen($css);
        $quote = null;
        $depth = 1;
        $start = $position;

        for (; $position < $length; $position++) {
            $char = $css[$position];

            if ($quote !== null) {
                if ($char === '\\') {
                    $position++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return [substr($css, $start, $position - $start), $position + 1];
            }
        }

        return [substr($css, $start), $length];
    }
}
