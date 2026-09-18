<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

use Kovami\HtmlDocx\Config\PageLayout;

final readonly class Document
{
    /**
     * @param  list<Block>  $blocks
     * @param  list<StyleDefinition>  $styles  the first one is the default paragraph style
     * @param  list<ListDefinition>  $lists
     * @param  list<Note>  $notes  footnotes and endnotes, in reference order
     * @param  list<HeaderFooter>  $headersFooters  at most one per kind and type
     * @param  list<Comment>  $comments
     */
    public function __construct(
        public array $blocks,
        public RunProperties $defaultRunProperties,
        public array $styles,
        public array $lists,
        public PageLayout $pageLayout,
        public DocumentMetadata $metadata,
        public array $notes = [],
        public ParagraphProperties $defaultParagraphProperties = new ParagraphProperties(),
        public array $headersFooters = [],
        public array $comments = [],
    ) {}

    public function list(int $numId): ?ListDefinition
    {
        foreach ($this->lists as $list) {
            if ($list->numId === $numId) {
                return $list;
            }
        }

        return null;
    }

    public function style(?string $id): ?StyleDefinition
    {
        foreach ($this->styles as $style) {
            if ($style->id === $id || ($id === null && $style->isDefault)) {
                return $style;
            }
        }

        return null;
    }
}
