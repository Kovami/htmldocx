<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\StyleResolver;
use Kovami\HtmlDocx\Image\ImageHandler;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Options;

/**
 * What the HTML writers share for one document: the DOM being built, the
 * editor's stylesheet (so only formatting that differs from it is written)
 * and the output settings.
 */
final readonly class WriterContext
{
    public HTMLDocument $dom;

    public StyleResolver $resolver;

    public CssFormatter $css;

    public OpenComments $comments;

    /**
     * @param  Closure(string): void  $warn
     */
    public function __construct(
        public Document $document,
        public Options $options,
        public ImageHandler $images,
        private Closure $warn,
    ) {
        $this->dom = HTMLDocument::createEmpty();
        $this->resolver = StyleResolver::fromStylesheets($options->defaultStylesheet, $options->extraStylesheet, []);
        $this->css = new CssFormatter($options->cssUnit);
        $this->comments = new OpenComments();
    }

    public function warn(string $message): void
    {
        ($this->warn)($message);
    }

    public function element(string $tag, Element $parent): Element
    {
        $element = $this->dom->createElement($tag);
        $parent->append($element);

        return $element;
    }

    /**
     * Styles an element already placed in the tree: the callback receives the
     * style the editor's stylesheet gives the element and returns the
     * declarations to add; the element's final computed style is returned.
     *
     * @param  Closure(ComputedStyle): array<string, string>  $declarations
     */
    public function style(Element $element, ComputedStyle $parent, Closure $declarations): ComputedStyle
    {
        $baseline = $this->resolver->resolve($element, $parent);
        $own = $declarations($baseline);

        if ($own === []) {
            return $baseline;
        }

        $existing = trim((string) $element->getAttribute('style'));
        $element->setAttribute('style', trim($existing . ' ' . CssFormatter::declarations($own)));

        return $this->resolver->resolve($element, $parent);
    }

    /** Whether a value equals the document's own default and the editor's default should be used instead. */
    public function adoptsEditorDefault(mixed $value, mixed $documentDefault): bool
    {
        return ! $this->options->keepDocumentDefaults && $value === $documentDefault;
    }

    public function id(string $name): string
    {
        return $this->options->idPrefix . $name;
    }

    /**
     * Word hides a bookmark whose name starts with an underscore, which is
     * how the HTML reader marks the ones it creates; the id is the name
     * without it, so an id survives HTML → DOCX → HTML unchanged.
     */
    public function bookmarkId(string $name): string
    {
        return $this->id((string) preg_replace('/^_(?=.)/', '', $name));
    }
}
