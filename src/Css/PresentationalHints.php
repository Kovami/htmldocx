<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

use Dom\Element;

/**
 * Maps legacy presentational HTML attributes to CSS, as browsers do.
 */
final class PresentationalHints
{
    private const array FONT_SIZES = [
        '1' => 'x-small', '2' => 'small', '3' => 'medium', '4' => 'large',
        '5' => 'x-large', '6' => 'xx-large', '7' => 'xxx-large',
    ];

    private const array ORDERED_LIST_TYPES = [
        '1' => 'decimal', 'a' => 'lower-alpha', 'A' => 'upper-alpha', 'i' => 'lower-roman', 'I' => 'upper-roman',
    ];

    private const array UNORDERED_LIST_TYPES = ['disc' => 'disc', 'circle' => 'circle', 'square' => 'square'];

    /**
     * @return array<string, string>
     */
    public static function for(Element $element): array
    {
        $tag = $element->localName;
        $hints = [];

        $align = strtolower(trim((string) $element->getAttribute('align')));

        if ($align !== '') {
            if ($tag === 'table') {
                if ($align === 'center') {
                    $hints['margin-left'] = 'auto';
                    $hints['margin-right'] = 'auto';
                }
            } elseif (in_array($align, ['left', 'right', 'center', 'justify'], true) && $tag !== 'img') {
                $hints['text-align'] = $align;
            }
        }

        $valign = strtolower(trim((string) $element->getAttribute('valign')));

        if (in_array($valign, ['top', 'middle', 'bottom'], true)) {
            $hints['vertical-align'] = $valign;
        }

        if ($element->hasAttribute('bgcolor')) {
            $hints['background-color'] = (string) $element->getAttribute('bgcolor');
        }

        foreach (['width', 'height'] as $dimension) {
            $value = trim((string) $element->getAttribute($dimension));

            if ($value !== '' && in_array($tag, ['table', 'td', 'th', 'tr', 'col', 'colgroup', 'img', 'hr'], true)) {
                $hints[$dimension] = is_numeric($value) ? $value.'px' : $value;
            }
        }

        if ($tag === 'font') {
            if ($element->hasAttribute('color')) {
                $hints['color'] = (string) $element->getAttribute('color');
            }

            if ($element->hasAttribute('face')) {
                $hints['font-family'] = (string) $element->getAttribute('face');
            }

            $size = trim((string) $element->getAttribute('size'));

            if (isset(self::FONT_SIZES[$size])) {
                $hints['font-size'] = self::FONT_SIZES[$size];
            }
        }

        $listType = self::listStyleType($element);

        if ($listType !== null) {
            $hints['list-style-type'] = $listType;
        }

        $dir = strtolower(trim((string) $element->getAttribute('dir')));

        if ($dir === 'rtl' || $dir === 'ltr') {
            $hints['direction'] = $dir;
        }

        return $hints;
    }

    /** `type` on lists and items; the numbering keywords are case-sensitive. */
    private static function listStyleType(Element $element): ?string
    {
        $tag = $element->localName;

        if (! $element->hasAttribute('type') || ! in_array($tag, ['ol', 'ul', 'li'], true)) {
            return null;
        }

        $type = trim((string) $element->getAttribute('type'));

        if ($tag === 'li') {
            $tag = $element->parentElement?->localName === 'ol' ? 'ol' : 'ul';
        }

        return $tag === 'ol'
            ? self::ORDERED_LIST_TYPES[$type] ?? null
            : self::UNORDERED_LIST_TYPES[strtolower($type)] ?? null;
    }
}
