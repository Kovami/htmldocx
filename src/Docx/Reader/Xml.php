<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Dom\Element;
use Dom\XMLDocument;
use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Throwable;

/**
 * Parsing and navigation helpers for WordprocessingML parts.
 *
 * Parsing is hardened for hostile input: documents declaring a DOCTYPE are
 * rejected before libxml sees them (no entity expansion, no XXE) and the
 * network is never touched. Strict OOXML namespaces are mapped onto their
 * transitional equivalents, so the rest of the reader handles one dialect.
 */
final class Xml
{
    public static function parse(string $xml, string $partName): XMLDocument
    {
        if (str_starts_with($xml, "\u{FEFF}")) {
            $xml = substr($xml, 3);
        }

        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw HtmlDocxException::malformedDocx("{$partName} declares a DOCTYPE, which OOXML never does");
        }

        $xml = strtr($xml, Namespaces::STRICT_TO_TRANSITIONAL);

        set_error_handler(static fn (): bool => true);

        try {
            return XMLDocument::createFromString($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        } catch (Throwable $exception) {
            throw HtmlDocxException::malformedDocx("{$partName} is not well-formed XML ({$exception->getMessage()})");
        } finally {
            restore_error_handler();
        }
    }

    public static function child(?Element $element, string $localName, string $namespace = Namespaces::W): ?Element
    {
        for ($child = $element?->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if ($child->localName === $localName && $child->namespaceURI === $namespace) {
                return $child;
            }
        }

        return null;
    }

    /**
     * @return list<Element>
     */
    public static function children(?Element $element, ?string $localName = null, string $namespace = Namespaces::W): array
    {
        $children = [];

        for ($child = $element?->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if ($localName === null || ($child->localName === $localName && $child->namespaceURI === $namespace)) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /**
     * Descendants in document order, optionally filtered by name.
     *
     * @return list<Element>
     */
    public static function descendants(?Element $element, string $localName, string $namespace = Namespaces::W): array
    {
        if ($element === null) {
            return [];
        }

        return iterator_to_array($element->getElementsByTagNameNS($namespace, $localName), false);
    }

    public static function is(?Element $element, string $localName, string $namespace = Namespaces::W): bool
    {
        return $element !== null && $element->localName === $localName && $element->namespaceURI === $namespace;
    }

    /** A `w:`-qualified attribute (or another namespace's), null when absent. */
    public static function attr(?Element $element, string $localName, ?string $namespace = Namespaces::W): ?string
    {
        if ($element === null) {
            return null;
        }

        if ($namespace !== null && $element->hasAttributeNS($namespace, $localName)) {
            return $element->getAttributeNS($namespace, $localName);
        }

        return $element->hasAttribute($localName) ? $element->getAttribute($localName) : null;
    }

    /** The `w:val` of a child property element, e.g. `val($rPr, 'sz')`. */
    public static function val(?Element $properties, string $localName): ?string
    {
        return self::attr(self::child($properties, $localName), 'val');
    }

    /**
     * An ST_OnOff property: absent element → null, element without a value
     * or with true/1/on → true, false/0/off → false.
     */
    public static function onOff(?Element $properties, string $localName): ?bool
    {
        $element = self::child($properties, $localName);

        if ($element === null) {
            return null;
        }

        return ! in_array(strtolower((string) self::attr($element, 'val')), ['false', '0', 'off', 'none'], true);
    }

    public static function int(?string $value): ?int
    {
        if ($value === null || preg_match('/^\s*[+-]?\d+(\.\d+)?\s*$/', $value) !== 1) {
            return null;
        }

        return (int) round((float) $value);
    }

    /**
     * An ST_TwipsMeasure / ST_SignedTwipsMeasure: plain twips or a universal
     * measure such as "1in", "2.54cm", "72pt".
     */
    public static function twips(?string $value): ?int
    {
        return self::measure($value, 1.0);
    }

    /** An ST_HpsMeasure (half-points) or universal measure, in half-points. */
    public static function halfPoints(?string $value): ?int
    {
        return self::measure($value, 0.1);
    }

    /** An ST_EighthPointMeasure. */
    public static function eighthPoints(?string $value): ?int
    {
        return self::int($value);
    }

    private static function measure(?string $value, float $twipsToUnit): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\s*([+-]?\d+(?:\.\d+)?)\s*(mm|cm|in|pt|pc|pi)?\s*$/i', $value, $match) !== 1) {
            return null;
        }

        $number = (float) $match[1];

        if (! isset($match[2])) {
            return (int) round($number);
        }

        $twips = match (strtolower($match[2])) {
            'mm' => $number * 1440 / 25.4,
            'cm' => $number * 1440 / 2.54,
            'in' => $number * 1440,
            'pt' => $number * 20,
            default => $number * 240,
        };

        return (int) round($twips * $twipsToUnit);
    }
}
