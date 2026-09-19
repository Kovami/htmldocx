<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

use Dom\Element;

/**
 * Runs the CSS cascade for an element and computes its style from its
 * parent's. Precedence, lowest first: default rules, presentational
 * attributes, base rules, author rules, inline `style`; then `!important`
 * declarations in the same order.
 */
final class StyleResolver
{
    private const array FONT_SIZE_KEYWORDS = [
        'xx-small' => 6.75, 'x-small' => 7.5, 'small' => 9.75, 'medium' => 12.0,
        'large' => 13.5, 'x-large' => 18.0, 'xx-large' => 24.0, 'xxx-large' => 36.0,
    ];

    private const array GENERIC_FONTS = [
        'serif' => 'Times New Roman', 'sans-serif' => 'Arial', 'monospace' => 'Courier New',
        'cursive' => 'Comic Sans MS', 'fantasy' => 'Impact', 'system-ui' => 'Segoe UI',
        'ui-monospace' => 'Courier New', 'ui-sans-serif' => 'Arial', 'ui-serif' => 'Times New Roman',
    ];

    private const array WHITE_SPACE = ['normal', 'nowrap', 'pre', 'pre-wrap', 'pre-line', 'break-spaces'];

    /** @var list<CssRule> */
    private readonly array $rules;

    /**
     * @param  list<CssRule>  $rules
     */
    public function __construct(array $rules)
    {
        usort($rules, static fn(CssRule $a, CssRule $b): int => $a->comparePrecedence($b));
        $this->rules = $rules;
    }

    /**
     * @param  list<string>  $authorStylesheets
     */
    public static function fromStylesheets(string $defaultCss, string $baseCss, array $authorStylesheets): self
    {
        $order = 0;
        $rules = [
            ...CssParser::parse($defaultCss, Origin::Default, $order),
            ...CssParser::parse($baseCss, Origin::Base, $order),
        ];

        foreach ($authorStylesheets as $css) {
            array_push($rules, ...CssParser::parse($css, Origin::Author, $order));
        }

        return new self($rules);
    }

    /**
     * A resolver limited to the default and base origins: what an element
     * looks like before the document's own CSS is applied.
     */
    public function withoutAuthorRules(): self
    {
        return new self(array_values(array_filter(
            $this->rules,
            static fn(CssRule $rule): bool => $rule->origin !== Origin::Author,
        )));
    }

    public function resolve(Element $element, ComputedStyle $parent): ComputedStyle
    {
        $d = $this->cascade($element);
        $display = strtolower(trim($d['display'] ?? 'inline'));
        $isInline = ! in_array($display, ComputedStyle::BLOCK_DISPLAYS, true);

        $lines = strtolower($d['text-decoration-line'] ?? '');
        $verticalAlign = strtolower(trim($d['vertical-align'] ?? ''));
        // A superscript drawn smaller keeps its text's size: in Word the
        // smaller glyphs are what superscript is, not a size of their own.
        $shrinks = in_array($verticalAlign, ['sub', 'super'], true)
            && preg_match('/^\s*(smaller|[\d.]+\s*(%|em))\s*$/i', $d['font-size'] ?? '') === 1;
        $fontSize = $shrinks ? $parent->fontSizePt : $this->fontSize($d['font-size'] ?? null, $parent);

        return new ComputedStyle(
            display: $display,
            fontFamily: $this->fontFamily($d['font-family'] ?? null) ?? $parent->fontFamily,
            fontSizePt: $fontSize,
            rootFontSizePt: $parent->rootFontSizePt,
            bold: $this->fontWeight($d['font-weight'] ?? null) ?? $parent->bold,
            italic: $this->keyword($d['font-style'] ?? null, ['italic' => true, 'oblique' => true, 'normal' => false]) ?? $parent->italic,
            underline: str_contains($lines, 'underline')
                ? $this->decorationStyle($d['text-decoration-style'] ?? null)
                : $parent->underline,
            strike: str_contains($lines, 'line-through') || $parent->strike,
            color: Color::toHex($d['color'] ?? null) ?? $parent->color,
            inlineBackground: $isInline ? (Color::toHex($d['background-color'] ?? null) ?? $parent->inlineBackground) : null,
            verticalPosition: match (true) {
                in_array($verticalAlign, ['sub', 'super'], true) => $verticalAlign,
                $isInline && $verticalAlign !== 'baseline' => $parent->verticalPosition,
                default => 'baseline',
            },
            textAlign: $this->textAlign($d['text-align'] ?? null) ?? $parent->textAlign,
            lineHeight: $this->lineHeight($d['line-height'] ?? null, $fontSize, $parent),
            textIndentPt: isset($d['text-indent'])
                ? (Length::toPoints($d['text-indent'], $fontSize, $parent->rootFontSizePt) ?? $parent->textIndentPt)
                : $parent->textIndentPt,
            textTransform: $this->keyword($d['text-transform'] ?? null, ['none' => 'none', 'uppercase' => 'uppercase',
                'lowercase' => 'lowercase', 'capitalize' => 'capitalize']) ?? $parent->textTransform,
            smallCaps: $this->keyword($d['font-variant-caps'] ?? $d['font-variant'] ?? null, ['small-caps' => true,
                'all-small-caps' => true, 'normal' => false]) ?? $parent->smallCaps,
            letterSpacingPt: $this->letterSpacing($d['letter-spacing'] ?? null, $fontSize, $parent),
            shadow: isset($d['text-shadow']) && strtolower(trim($d['text-shadow'])) !== 'inherit'
                ? strtolower(trim($d['text-shadow'])) !== 'none'
                : $parent->shadow,
            whiteSpace: $this->keyword($d['white-space'] ?? null, array_combine(self::WHITE_SPACE, self::WHITE_SPACE)) ?? $parent->whiteSpace,
            direction: $this->keyword($d['direction'] ?? null, ['ltr' => 'ltr', 'rtl' => 'rtl']) ?? $parent->direction,
            listStyleType: $this->listStyleType($d['list-style-type'] ?? null) ?? $parent->listStyleType,
            declarations: $d,
        );
    }

    /**
     * @return array<string, string>
     */
    private function cascade(Element $element): array
    {
        $normal = [];
        $important = [];
        $hintsApplied = false;

        foreach ($this->rules as $rule) {
            if (! $hintsApplied && $rule->origin !== Origin::Default) {
                $normal = [...$normal, ...PresentationalHints::for($element)];
                $hintsApplied = true;
            }

            if (! $rule->selector->matches($element)) {
                continue;
            }

            foreach ($rule->declarations as $property => $declaration) {
                if ($declaration->important) {
                    $important[$property] = $declaration->value;
                } else {
                    $normal[$property] = $declaration->value;
                }
            }
        }

        if (! $hintsApplied) {
            $normal = [...$normal, ...PresentationalHints::for($element)];
        }

        $inlineNormal = [];
        $inlineImportant = [];
        $styleAttribute = (string) $element->getAttribute('style');

        if (trim($styleAttribute) !== '') {
            foreach (DeclarationParser::parse($styleAttribute) as $property => $declaration) {
                if ($declaration->important) {
                    $inlineImportant[$property] = $declaration->value;
                } else {
                    $inlineNormal[$property] = $declaration->value;
                }
            }
        }

        return [...$normal, ...$inlineNormal, ...$important, ...$inlineImportant];
    }

    private function fontSize(?string $value, ComputedStyle $parent): float
    {
        $value = strtolower(trim((string) $value));

        $size = match (true) {
            $value === '' || $value === 'inherit' => null,
            isset(self::FONT_SIZE_KEYWORDS[$value]) => self::FONT_SIZE_KEYWORDS[$value],
            $value === 'smaller' => $parent->fontSizePt / 1.2,
            $value === 'larger' => $parent->fontSizePt * 1.2,
            default => Length::toPoints($value, $parent->fontSizePt, $parent->rootFontSizePt, $parent->fontSizePt),
        };

        return $size !== null && $size > 0 ? $size : $parent->fontSizePt;
    }

    private function fontFamily(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        foreach (Tokens::splitTopLevel($value, ',') as $family) {
            $family = Tokens::unquote($family);
            $lower = strtolower($family);

            if (in_array($lower, ['inherit', 'initial', 'unset'], true)) {
                return null;
            }

            if ($family !== '') {
                return self::GENERIC_FONTS[$lower] ?? $family;
            }
        }

        return null;
    }

    private function fontWeight(?string $value): ?bool
    {
        $value = strtolower(trim((string) $value));

        return match (true) {
            $value === 'bold' || $value === 'bolder' => true,
            $value === 'normal' || $value === 'lighter' => false,
            is_numeric($value) => (float) $value >= 600,
            default => null,
        };
    }

    private function decorationStyle(?string $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['double', 'dotted', 'dashed', 'wavy'], true) ? $value : 'solid';
    }

    private function textAlign(?string $value): ?string
    {
        return match (strtolower(trim((string) $value))) {
            'left', 'start', '-webkit-left' => 'left',
            'right', 'end', '-webkit-right' => 'right',
            'center', '-webkit-center' => 'center',
            'justify' => 'justify',
            default => null,
        };
    }

    private function lineHeight(?string $value, float $fontSize, ComputedStyle $parent): ?LineHeight
    {
        $value = strtolower(trim((string) $value));

        if ($value === '' || $value === 'inherit') {
            return $parent->lineHeight;
        }

        if ($value === 'normal') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value > 0 ? LineHeight::multiple((float) $value) : $parent->lineHeight;
        }

        if (str_ends_with($value, '%') && is_numeric(substr($value, 0, -1))) {
            return LineHeight::multiple((float) $value / 100);
        }

        $points = Length::toPoints($value, $fontSize, $parent->rootFontSizePt);

        return $points !== null && $points > 0 ? LineHeight::points($points) : $parent->lineHeight;
    }

    private function letterSpacing(?string $value, float $fontSize, ComputedStyle $parent): float
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            '', 'inherit' => $parent->letterSpacingPt,
            'normal' => 0.0,
            default => Length::toPoints($value, $fontSize, $parent->rootFontSizePt) ?? $parent->letterSpacingPt,
        };
    }

    private function listStyleType(?string $value): ?string
    {
        $value = trim((string) $value);

        // A quoted value is the marker itself, so its case matters.
        if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
            return $value;
        }

        $value = strtolower($value);

        return $value === '' || in_array($value, ['inherit', 'initial', 'unset'], true) ? null : $value;
    }

    /**
     * @template T
     *
     * @param  array<string, T>  $map
     * @return T|null
     */
    private function keyword(?string $value, array $map): mixed
    {
        return $map[strtolower(trim((string) $value))] ?? null;
    }
}
