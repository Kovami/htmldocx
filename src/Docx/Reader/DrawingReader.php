<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Closure;
use Dom\Element;
use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\ImageData;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\RunProperties;

/**
 * Reads pictures and text boxes from DrawingML (`w:drawing`) and legacy
 * VML (`w:pict`, `w:object`). Pictures become image runs; the content of
 * text boxes and shapes is returned as blocks for the caller to place after
 * the anchoring paragraph. Linked (external) pictures are never fetched.
 */
final readonly class DrawingReader
{
    private const int EMU_PER_POINT = 12700;

    /**
     * @param  Closure(Element): list<Block>  $readContent  reads a `w:txbxContent`
     */
    public function __construct(
        private ReaderContext $context,
        private string $part,
        private Closure $readContent,
    ) {}

    /**
     * @return array{inlines: list<Inline>, blocks: list<Block>}
     */
    public function read(Element $element, RunProperties $properties): array
    {
        $inlines = [];
        $blocks = [];

        if (Xml::is($element, 'drawing')) {
            foreach (Xml::children($element, null) as $container) {
                if (Xml::is($container, 'inline', Namespaces::WP) || Xml::is($container, 'anchor', Namespaces::WP)) {
                    $this->drawingObject($container, $properties, $inlines, $blocks);
                }
            }
        } else {
            $this->vml($element, $properties, $inlines, $blocks);
        }

        return ['inlines' => $inlines, 'blocks' => $blocks];
    }

    /**
     * @param  list<Inline>  $inlines
     * @param  list<Block>  $blocks
     */
    private function drawingObject(Element $container, RunProperties $properties, array &$inlines, array &$blocks): void
    {
        $docPr = Xml::child($container, 'docPr', Namespaces::WP);

        if (in_array(strtolower((string) $docPr?->getAttribute('hidden')), ['1', 'true'], true)) {
            return;
        }

        $extent = Xml::child($container, 'extent', Namespaces::WP);
        $width = max(1, (int) $extent?->getAttribute('cx'));
        $height = max(1, (int) $extent?->getAttribute('cy'));
        $description = trim((string) ($docPr?->getAttribute('descr') ?: $docPr?->getAttribute('title')));
        $float = Xml::is($container, 'anchor', Namespaces::WP) ? self::float($container) : null;
        $graphicData = Xml::child(Xml::child($container, 'graphic', Namespaces::A), 'graphicData', Namespaces::A);
        $link = $this->hyperlink(Xml::child($docPr, 'hlinkClick', Namespaces::A));

        foreach ($this->graphics($graphicData) as $graphic) {
            if (Xml::is($graphic, 'pic', Namespaces::PIC)) {
                $image = $this->picture($graphic, $width, $height, $description, $properties, $float);

                if ($image !== null) {
                    $inlines[] = $link === null ? $image : new Hyperlink($link, null, [$image]);
                }
            } else {
                $content = Xml::child(Xml::child($graphic, 'txbx', Namespaces::WPS), 'txbxContent');

                if ($content !== null) {
                    array_push($blocks, ...($this->readContent)($content));
                }
            }
        }

        if ($graphicData !== null && $inlines === [] && $blocks === [] && ! str_ends_with((string) $graphicData->getAttribute('uri'), '/wordprocessingShape')) {
            $this->context->warn('A drawing without a picture preview (chart, diagram or ink) was skipped');
        }
    }

    /**
     * Pictures and shapes inside a graphic frame, looking into groups.
     *
     * @return list<Element>
     */
    private function graphics(?Element $parent): array
    {
        $result = [];

        foreach (Xml::children($parent, null) as $child) {
            if (Xml::is($child, 'pic', Namespaces::PIC) || Xml::is($child, 'wsp', Namespaces::WPS)) {
                $result[] = $child;
            } elseif (Xml::is($child, 'wgp', Namespaces::WPG) || Xml::is($child, 'grpSp', Namespaces::WPG)) {
                array_push($result, ...$this->graphics($child));
            }
        }

        return $result;
    }

    private function picture(Element $picture, int $width, int $height, string $description, RunProperties $properties, ?string $float): ?ImageRun
    {
        $blip = Xml::child(Xml::child($picture, 'blipFill', Namespaces::PIC), 'blip', Namespaces::A);

        if ($blip === null) {
            return null;
        }

        if (! $blip->hasAttributeNS(Namespaces::R, 'embed') && $blip->hasAttributeNS(Namespaces::R, 'link')) {
            $this->context->warn('A linked picture was not loaded: external images are never fetched');

            return null;
        }

        $data = $this->imageData((string) $blip->getAttributeNS(Namespaces::R, 'embed'));

        return $data === null ? null : new ImageRun($data, $width, $height, $description, $properties, $float);
    }

    /**
     * @param  list<Inline>  $inlines
     * @param  list<Block>  $blocks
     */
    private function vml(Element $element, RunProperties $properties, array &$inlines, array &$blocks): void
    {
        foreach (Xml::children($element, null) as $shape) {
            if ($shape->namespaceURI !== Namespaces::V) {
                continue;
            }

            $style = self::cssDeclarations((string) $shape->getAttribute('style'));

            if (($style['visibility'] ?? '') === 'hidden') {
                continue;
            }

            $imageData = Xml::child($shape, 'imagedata', Namespaces::V);
            $textbox = Xml::child(Xml::child($shape, 'textbox', Namespaces::V), 'txbxContent');

            if ($imageData !== null) {
                $id = $imageData->getAttributeNS(Namespaces::R, 'id') ?: $imageData->getAttributeNS(Namespaces::O, 'relid');
                $width = self::emu($style['width'] ?? null);
                $height = self::emu($style['height'] ?? null);
                $data = $id === null || $id === '' ? null : $this->imageData($id);

                if ($data !== null) {
                    $width ??= (int) round($data->widthPx * 9525);
                    $height ??= (int) round($data->heightPx * 9525);
                    $float = match ($style['mso-position-horizontal'] ?? null) {
                        'left' => 'left',
                        'right' => 'right',
                        default => null,
                    };
                    $description = trim((string) ($shape->getAttribute('alt') ?: $imageData->getAttributeNS(Namespaces::O, 'title')));

                    $inlines[] = new ImageRun($data, max(1, $width), max(1, $height), $description, $properties, ($style['position'] ?? '') === 'absolute' ? $float : null);
                }
            }

            if ($textbox !== null) {
                array_push($blocks, ...($this->readContent)($textbox));
            }

            if (Xml::is($shape, 'group', Namespaces::V)) {
                $this->vml($shape, $properties, $inlines, $blocks);
            }
        }
    }

    private function imageData(string $relationshipId): ?ImageData
    {
        $relationship = $this->context->package->relationship($this->part, $relationshipId);

        if ($relationship === null || $relationship->external) {
            $this->context->warn('A picture was not loaded: ' . ($relationship === null ? 'its part is missing' : 'external images are never fetched'));

            return null;
        }

        if (! $this->context->package->has($relationship->target)) {
            $this->context->warn("A picture was not loaded: {$relationship->target} is missing from the package");

            return null;
        }

        $bytes = $this->context->package->read($relationship->target);
        $inspected = $this->context->images->inspect($bytes);

        if ($inspected !== null) {
            return $inspected;
        }

        $extension = strtolower(pathinfo($relationship->target, PATHINFO_EXTENSION));
        $contentType = $this->context->package->contentType($relationship->target) ?? 'application/octet-stream';

        if (in_array($extension, ['emf', 'wmf', 'emz', 'wmz'], true) || str_contains($contentType, 'emf') || str_contains($contentType, 'wmf')) {
            $this->context->warn("A {$extension} picture was skipped: browsers cannot display Windows metafiles");

            return null;
        }

        if ($extension === 'svg' || str_contains($contentType, 'svg')) {
            // An SVG can carry scripts; served from the application's own origin it is stored XSS.
            $this->context->warn('An SVG picture was skipped: SVG can carry scripts, so it is never passed on');

            return null;
        }

        $this->context->warn("A picture of type {$contentType} was skipped: its format is not recognised");

        return null;
    }

    private function hyperlink(?Element $click): ?string
    {
        $id = $click?->getAttributeNS(Namespaces::R, 'id');

        if ($id === null || $id === '') {
            return null;
        }

        $relationship = $this->context->package->relationship($this->part, $id);

        return $relationship !== null && $relationship->external ? $relationship->target : null;
    }

    /** Text wraps around an anchored picture aligned to one side of the column. */
    private static function float(Element $anchor): ?string
    {
        $wraps = ['wrapSquare', 'wrapTight', 'wrapThrough'];
        $wrapped = array_filter($wraps, static fn(string $wrap): bool => Xml::child($anchor, $wrap, Namespaces::WP) !== null) !== [];

        if (! $wrapped) {
            return null;
        }

        $align = trim((string) Xml::child(Xml::child($anchor, 'positionH', Namespaces::WP), 'align', Namespaces::WP)?->textContent);

        return match ($align) {
            'left', 'inside' => 'left',
            'right', 'outside' => 'right',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function cssDeclarations(string $style): array
    {
        $declarations = [];

        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $declarations[strtolower(trim($property))] = trim($value);
        }

        return $declarations;
    }

    private static function emu(?string $length): ?int
    {
        if ($length === null || preg_match('/^([\d.]+)\s*(pt|px|in|cm|mm)?$/i', $length, $match) !== 1) {
            return null;
        }

        $points = (float) $match[1] * match (strtolower($match[2] ?? 'px')) {
            'pt' => 1.0,
            'in' => 72.0,
            'cm' => 72 / 2.54,
            'mm' => 72 / 25.4,
            default => 0.75,
        };

        return (int) round($points * self::EMU_PER_POINT);
    }
}
