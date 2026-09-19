<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Closure;
use Dom\Element;
use Dom\HTMLDocument;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\StyleResolver;
use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\Image\ImageHandler;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Options;

/**
 * What the HTML writers share for one document: the DOM being built, the
 * editor's stylesheet (so only formatting that differs from it is written)
 * and the output settings.
 *
 * Plain HTML is written for no editor in particular: its baseline is what a
 * browser gives HTML by default, and every block spells out its font, size,
 * colour, margins and line height, since any editor's CSS may change them.
 */
final class WriterContext
{
    public readonly HTMLDocument $dom;

    public readonly StyleResolver $resolver;

    public readonly CssFormatter $css;

    public readonly OpenComments $comments;

    /**
     * Whether the HTML is plain: self-contained, standard markup. Every
     * profile builds on it but SunEditor's, which has markup of its own.
     */
    public readonly bool $plain;

    /** @var array<string, true> messages already given by {@see self::warnOnce()} */
    private array $warned = [];

    /**
     * @param  Editor|null  $editor  the editor the HTML is written for; null for plain HTML
     * @param  Closure(string): void  $warn
     */
    public function __construct(
        public readonly Document $document,
        public readonly ?Editor $editor,
        public readonly Options $options,
        public readonly ImageHandler $images,
        private readonly Closure $warn,
    ) {
        $this->plain = $editor !== Editor::SunEditor;
        $this->dom = HTMLDocument::createEmpty();
        $this->resolver = StyleResolver::fromStylesheets($options->stylesheet($editor), $options->extraStylesheet, []);
        $this->css = new CssFormatter($options->cssUnit);
        $this->comments = new OpenComments();
    }

    public function warn(string $message): void
    {
        ($this->warn)($message);
    }

    public function warnOnce(string $message): void
    {
        if (! isset($this->warned[$message])) {
            $this->warned[$message] = true;
            $this->warn($message);
        }
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
