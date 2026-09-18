<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use Dom\Element;
use Dom\HTMLDocument as NativeHtmlDocument;
use Dom\HTMLElement;

/**
 * Parses HTML with PHP's spec-compliant HTML5 parser, so fragments, unclosed
 * tags and entities are handled exactly as a browser would handle them.
 */
final readonly class HtmlDocument
{
    private function __construct(
        private NativeHtmlDocument $document,
    ) {}

    public static function fromString(string $html): self
    {
        $document = NativeHtmlDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');

        if ($document->body === null) {
            $document->documentElement?->append($document->createElement('body'));
        }

        return new self($document);
    }

    /**
     * The element children of an element. `Dom\Element::$children` would say
     * the same, but it only exists from PHP 8.5.
     *
     * @return list<Element>
     */
    public static function elementChildren(Element $element): array
    {
        $children = [];

        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            $children[] = $child;
        }

        return $children;
    }

    public function body(): HTMLElement
    {
        /** @var HTMLElement */
        return $this->document->body;
    }

    /**
     * @return list<string>
     */
    public function stylesheets(): array
    {
        $sheets = [];

        foreach ($this->document->querySelectorAll('style') as $style) {
            $media = strtolower(trim((string) $style->getAttribute('media')));

            if ($media === '' || preg_match('/\b(all|print)\b/', $media)) {
                $sheets[] = (string) $style->textContent;
            }
        }

        return $sheets;
    }

    public function title(): ?string
    {
        $title = trim((string) $this->document->querySelector('title')?->textContent);

        return $title === '' ? null : $title;
    }

    /**
     * @return array<string, true> ids referenced by in-document links (`href="#id"`)
     */
    public function linkedFragments(): array
    {
        $fragments = [];

        foreach ($this->document->querySelectorAll('a[href^="#"]') as $anchor) {
            $fragment = rawurldecode(substr((string) $anchor->getAttribute('href'), 1));

            if ($fragment !== '') {
                $fragments[$fragment] = true;
            }
        }

        return $fragments;
    }

    public function native(): NativeHtmlDocument
    {
        return $this->document;
    }
}
