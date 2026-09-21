<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

use DateTimeImmutable;
use Dom\Element;
use Dom\Node;
use Dom\Text;
use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\FontMetrics;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Css\StyleResolver;
use Kovami\HtmlDocx\Html\Writer\InlineWriter;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Bookmark;
use Kovami\HtmlDocx\Model\Border;
use Kovami\HtmlDocx\Model\BorderSet;
use Kovami\HtmlDocx\Model\Comment;
use Kovami\HtmlDocx\Model\CommentEnd;
use Kovami\HtmlDocx\Model\CommentRanges;
use Kovami\HtmlDocx\Model\CommentStart;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\DocumentMetadata;
use Kovami\HtmlDocx\Model\Field;
use Kovami\HtmlDocx\Model\Formula;
use Kovami\HtmlDocx\Model\HeaderFooter;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\Note;
use Kovami\HtmlDocx\Model\NoteReference;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\Table;
use Kovami\HtmlDocx\Model\TextRun;
use Kovami\HtmlDocx\Options;
use Throwable;
use WeakMap;

/**
 * Walks the HTML DOM and builds the document model, following the CSS
 * visual formatting model: block-level boxes start paragraphs (or tables),
 * inline content flows into the current paragraph, and box margins,
 * borders, padding and backgrounds become paragraph indentation, spacing,
 * borders and shading. One instance converts one document.
 */
final class DocumentBuilder
{
    private const array IGNORED_ELEMENTS = [
        'head', 'script', 'style', 'template', 'noscript', 'svg', 'canvas', 'select', 'textarea', 'button',
    ];

    private const array MEDIA_ELEMENTS = ['iframe', 'video', 'audio', 'embed', 'object'];

    /** Narrowest text column (twips, 1in) that nesting indents may leave. */
    private const int MIN_CONTENT_WIDTH = 1440;

    /** The note sections this library's HTML writer emits, by note type. */
    /** The note lists of SunEditor HTML, and of plain HTML, where a DPUB-ARIA section holds them. */
    private const array NOTE_LISTS = [
        Note::FOOTNOTE => 'ol.se-footnotes, section.footnotes > ol',
        Note::ENDNOTE => 'ol.se-endnotes, section.endnotes > ol',
    ];

    private readonly NumberingRegistry $numbering;

    private readonly BookmarkRegistry $bookmarks;

    private readonly TableBuilder $tables;

    /** @var array<string, true> */
    private array $linkedFragments = [];

    /** @var array<string, true> */
    private array $placedBookmarks = [];

    /** @var array<string, array{type: string, number: int}> id of a note body => the note it holds */
    private array $noteItems = [];

    /**
     * What the note sections cover, by object id. The elements themselves are the values:
     * an id only belongs to an element for as long as something holds on to it.
     *
     * @var array<int, array{type: string, list: Element}>
     */
    private array $noteLists = [];

    /** @var array<int, array{number: int, item: Element}> object id of a note body => the note it holds */
    private array $noteBodies = [];

    /** @var array<int, Element> what a note section draws around itself: its rule and backlinks */
    private array $noteDecorations = [];

    /** @var list<Note> */
    private array $notes = [];

    /** @var array<int, array{kind: string, type: string, element: Element}> the header and footer `div`s, by object id */
    private array $furnitureSections = [];

    /** The header or footer line read last, which the next line of the same one follows. */
    private ?Element $lastFurniture = null;

    /** @var list<HeaderFooter> */
    private array $headersFooters = [];

    /** @var array<int, Element> the comment lists, by object id */
    private array $commentLists = [];

    /** @var array<int, array{item: Element, id: int}> comment bodies, by object id */
    private array $commentItems = [];

    /** @var array<int, Element> comment id => the first span marking its text */
    private array $commentFirstSpans = [];

    /** @var array<int, Element> comment id => the last span marking its text */
    private array $commentLastSpans = [];

    /** @var list<Comment> */
    private array $comments = [];

    private int $pageContentWidth = 0;

    /** @var list<Bookmark> */
    private array $pendingBookmarks = [];

    private bool $pendingPageBreak = false;

    /** @var WeakMap<ParagraphProperties, int> how much lower to set each paragraph's text in Word, in twips; see lowerText() */
    private WeakMap $lowered;

    /** @var WeakMap<ParagraphProperties, array{0: int, 1: int}> how much a browser grows each paragraph to hold its scripts, above and below, in twips */
    private WeakMap $scriptGrowth;

    /** @var WeakMap<ParagraphProperties, int> room a browser leaves under a picture alone on its line, in twips */
    private WeakMap $pictureGap;

    public function __construct(
        private readonly StyleResolver $resolver,
        private readonly PropertyMapper $mapper,
        private readonly ImageFactory $images,
        private readonly Options $options,
    ) {
        $this->numbering = new NumberingRegistry();
        $this->bookmarks = new BookmarkRegistry();
        $this->tables = new TableBuilder($resolver, $mapper);
        $this->lowered = new WeakMap();
        $this->scriptGrowth = new WeakMap();
        $this->pictureGap = new WeakMap();
    }

    public function build(HtmlDocument $html, PageLayout $pageLayout): Document
    {
        $this->linkedFragments = $html->linkedFragments();

        $root = ComputedStyle::root($this->options->baseFontFamily(), $this->options->baseFontSizePt(), strtoupper($this->options->baseTextColor()));
        $body = $html->body();
        $this->pageContentWidth = $pageLayout->contentWidthTwips();
        self::unwrapTableFigures($html);
        $this->collectNotes($html);
        $this->collectHeadersFooters($html);
        $this->collectComments($html);
        $sink = new BlockSink();

        // What the editor shows text in: the profile's own typography, unless
        // the document or the options say otherwise.
        $base = $this->resolver->resolve($body, $root);
        $this->renderChildren($body, $base, new BlockContext($this->pageContentWidth), $sink);

        $catalog = new StyleCatalog($this->resolver->withoutAuthorRules(), $this->mapper);
        $blocks = BlockNormalizer::normalize($sink->blocks);
        $this->lowerText($blocks);
        $comments = CommentRanges::balance([$blocks, ...array_map(static fn(Note $note): array => $note->blocks, $this->notes)], $this->comments);

        return new Document(
            blocks: $blocks,
            defaultRunProperties: $this->mapper->run($base),
            styles: $catalog->definitions($base),
            lists: $this->numbering->definitions(),
            pageLayout: $pageLayout,
            metadata: new DocumentMetadata(
                createdAt: $this->options->createdAt ?? new DateTimeImmutable(),
                title: $this->options->title ?? $html->title(),
                author: $this->options->author,
                language: $this->options->language,
            ),
            notes: $this->notes,
            headersFooters: $this->headersFooters,
            comments: $comments,
        );
    }

    private function renderChildren(Node $parent, ComputedStyle $style, BlockContext $context, BlockSink $sink): void
    {
        $flow = new InlineFlow($style, $context);

        foreach ($parent->childNodes as $child) {
            $this->renderNode($child, $style, $flow, $sink);
        }

        $this->flushParagraph($flow, $sink);
    }

    private function renderNode(Node $node, ComputedStyle $parentStyle, InlineFlow $flow, BlockSink $sink): void
    {
        if ($node instanceof Text) {
            $flow->buffer()->appendText($node->data, $this->mapper->run($parentStyle), $parentStyle, $flow->link);

            return;
        }

        if (! $node instanceof Element || in_array($node->localName, self::IGNORED_ELEMENTS, true)) {
            return;
        }

        $style = $this->resolver->resolve($node, $parentStyle);

        if ($style->display === 'none') {
            return;
        }

        if (isset($this->noteDecorations[spl_object_id($node)])) {
            return;
        }

        $section = $this->noteLists[spl_object_id($node)] ?? null;

        if ($section !== null) {
            $this->flushParagraph($flow, $sink);
            $this->renderNotes($node, $section['type'], $style, $flow->context);

            return;
        }

        $furniture = $this->furnitureSections[spl_object_id($node)] ?? null;

        if ($furniture !== null && ! $this->hasHeaderFooter($furniture['kind'], $furniture['type'])) {
            $this->flushParagraph($flow, $sink);
            $this->headersFooters[] = new HeaderFooter($furniture['kind'], $furniture['type'], $this->renderLine($node, $style));
            $this->lastFurniture = $node;

            return;
        }

        // The next line of the header just read: SunEditor keeps one line per div.
        $previous = $node->previousElementSibling;

        if ($furniture !== null && $previous !== null && $previous === $this->lastFurniture
            && $this->furnitureSections[spl_object_id($previous)]['kind'] === $furniture['kind']
            && $this->furnitureSections[spl_object_id($previous)]['type'] === $furniture['type']) {
            $this->flushParagraph($flow, $sink);
            $this->lastFurniture = $node;
            $last = array_pop($this->headersFooters);
            $this->headersFooters[] = new HeaderFooter($furniture['kind'], $furniture['type'], [...$last->blocks ?? [], ...$this->renderLine($node, $style)]);

            return;
        }

        if (isset($this->commentLists[spl_object_id($node)])) {
            $this->flushParagraph($flow, $sink);
            $this->renderComments($node, $style);

            return;
        }

        $note = $node->localName === 'a' ? ($this->noteItems[(string) self::fragment($node)] ?? null) : null;

        if ($note !== null) {
            // The mark carries no formatting of its own: the `<sup>` around it does.
            $flow->buffer()->appendNoteReference(
                new NoteReference($note['type'], $note['number'], $this->mapper->run($parentStyle)),
                $flow->link,
            );

            return;
        }

        $this->queueBookmarks($node);
        $tag = $node->localName;

        match (true) {
            $tag === 'br' => $flow->buffer()->appendBreak($this->mapper->run($style), $flow->link),
            $tag === 'img' => $this->renderImage($node, $style, $flow),
            $tag === 'table' => $this->renderTable($node, $style, $flow, $sink),
            $tag === 'hr' => $this->renderHorizontalRule($style, $flow, $sink),
            $tag === 'input' => $this->renderInput($node, $style, $flow),
            in_array($tag, self::MEDIA_ELEMENTS, true) => $this->renderMedia($node, $style, $flow),
            $style->isBlockLevel() => $this->renderBlock($node, $style, $flow, $sink),
            default => $this->renderInline($node, $style, $flow, $sink),
        };
    }

    private function renderInline(Element $element, ComputedStyle $style, InlineFlow $flow, BlockSink $sink): void
    {
        $latex = self::latex($element);

        if ($latex !== null) {
            if ($latex !== '') {
                $flow->buffer()->appendFormula(new Formula($latex, self::isDisplay($element), $this->mapper->run($style)), $flow->link);
            }

            return;
        }

        $field = strtoupper((string) $element->getAttribute('data-field'));

        if (in_array($field, Field::SUPPORTED, true)) {
            $flow->buffer()->appendField(new Field($field, trim($element->textContent ?? ''), $this->mapper->run($style)), $flow->link);

            return;
        }

        $closes = [];

        foreach ($element->localName === 'span' ? self::commentIds($element) : [] as $id) {
            if (($this->commentFirstSpans[$id] ?? null) === $element) {
                $flow->buffer()->appendCommentBoundary(new CommentStart($id));
            }

            if (($this->commentLastSpans[$id] ?? null) === $element) {
                $closes[] = $id;
            }
        }

        $previousLink = $flow->link;

        if ($element->localName === 'a' && $element->hasAttribute('href')) {
            $flow->link = $this->linkTarget((string) $element->getAttribute('href')) ?? $previousLink;
        }

        if ($element->localName === 'q') {
            $flow->buffer()->appendText("\u{201C}", $this->mapper->run($style), $style, $flow->link);
        }

        foreach ($element->childNodes as $child) {
            $this->renderNode($child, $style, $flow, $sink);
        }

        if ($element->localName === 'q') {
            $flow->buffer()->appendText("\u{201D}", $this->mapper->run($style), $style, $flow->link);
        }

        foreach ($closes as $id) {
            $flow->buffer()->appendCommentBoundary(new CommentEnd($id));
        }

        $flow->link = $previousLink;
    }

    private function renderBlock(Element $element, ComputedStyle $style, InlineFlow $flow, BlockSink $sink): void
    {
        $this->flushParagraph($flow, $sink);

        $parent = $flow->context;
        $percentBase = $parent->availableWidth / Length::TWIPS_PER_POINT;
        $length = static fn(string $property): float => max(0.0, $style->lengthPt($property, $percentBase) ?? 0.0);

        // Percentage vertical padding around an embedded player is the responsive
        // aspect-ratio idiom: it reserves the player's height. The player becomes
        // a one-line link here, so that space would only be a blank gap.
        $sizesPlayer = $this->containsMedia($element);
        $padding = static fn(string $side): float => $sizesPlayer && in_array($side, ['top', 'bottom'], true)
            && str_ends_with((string) $style->value("padding-{$side}"), '%') ? 0.0 : $length("padding-{$side}");

        $edges = [];
        $horizontal = ['left' => 0, 'right' => 0];

        foreach (['top', 'left', 'bottom', 'right'] as $side) {
            $edge = $style->border($side);
            $edges[$side] = $this->mapper->border($edge, $padding($side));

            if (isset($horizontal[$side])) {
                $horizontal[$side] = Length::pointsToTwips($length("margin-{$side}") + $padding($side) + ($edge->widthPt ?? 0));
            }
        }

        $horizontal = self::fitIndents($horizontal, $parent->availableWidth);
        $tag = $element->localName;

        // A box of its own width holds its pictures and tables to it; as in
        // CSS, min-width wins over width and max-width (SunEditor stretches a
        // picture component to the column that way).
        $available = $parent->availableWidth - $horizontal['left'] - $horizontal['right'];
        $widths = array_filter(
            [$style->lengthPt('width', $percentBase), $style->lengthPt('max-width', $percentBase)],
            static fn(?float $width): bool => $width !== null && $width > 0,
        );

        if ($widths !== []) {
            $available = min($available, Length::pointsToTwips(max(min($widths), $style->lengthPt('min-width', $percentBase) ?? 0.0)));
        }

        $context = $parent->with([
            'availableWidth' => max(Length::TWIPS_PER_POINT, $available),
            'indentLeft' => $parent->indentLeft + $horizontal['left'],
            'indentRight' => $parent->indentRight + $horizontal['right'],
            'borders' => $parent->borders->mergedWith(new BorderSet($edges['top'], $edges['left'], $edges['bottom'], $edges['right'])),
            'shading' => $style->backgroundColor() ?? $parent->shading,
            'styleId' => StyleCatalog::HEADING_STYLE_IDS[$tag]
                ?? ($tag === 'figcaption' ? StyleCatalog::CAPTION_STYLE_ID : $parent->styleId),
        ]);

        if ($tag === 'ul' || $tag === 'ol') {
            $this->emitPendingMarker($flow, $sink);

            $context = $context->with([
                // A marker inside the first line is Word's marker with no hanging indent, followed by a space.
                'list' => $style->value('list-style-position') === 'inside'
                    ? ListCounter::for($element, $this->numbering, min(8, $parent->listDepth), $context->indentLeft, 0, 'space')
                    : ListCounter::for($element, $this->numbering, min(8, $parent->listDepth), $context->indentLeft, min(360, $context->indentLeft)),
                'listDepth' => $parent->listDepth + 1,
                'marker' => null,
            ]);
        } elseif ($style->display === 'list-item' && $parent->list !== null) {
            $context = $context->with(['marker' => $parent->list->markerFor($element, $style)]);
        }

        if ($style->breaksPageBefore()) {
            $this->pendingPageBreak = true;
        }

        // The writer pads the first line of a bulleted item by what Word's
        // Symbol bullet adds to it; that is not space before the item.
        $bullet = $context->marker !== null && ! $context->marker->consumed ? $this->bulletLine($style) : 0.0;
        $firstIndex = $sink->count();
        $this->renderChildren($element, $style, $context, $sink);

        if ($context->marker !== null && $context->marker !== $parent->marker && ! $context->marker->consumed) {
            $sink->add($this->createParagraph(new InlineFlow($style, $context), []));
        }

        // Padding under a border is the border's space in Word, which takes
        // that room itself, above and below the text as CSS's does; padding
        // with no border there has to come from the paragraph's spacing.
        $this->applyVerticalMargins(
            $sink,
            $firstIndex,
            Length::pointsToTwips($length('margin-top') + max(0.0, ($edges['top'] === null ? $padding('top') : 0.0) - $bullet)),
            Length::pointsToTwips($length('margin-bottom') + ($edges['bottom'] === null ? $padding('bottom') : 0.0)),
            // A box that establishes a formatting context of its own (a
            // scroll container) keeps its content's margins inside it.
            ! in_array(strtolower(trim((string) $style->value('overflow'))), ['', 'visible'], true),
        );

        if ($style->breaksPageAfter()) {
            $this->pendingPageBreak = true;
        }
    }

    /**
     * Shrinks a box's horizontal indents proportionally so its content keeps a
     * usable width; unlike a browser, Word cannot let deeply nested content
     * overflow past the page edge.
     *
     * @param  array{left: int, right: int}  $indents  twips
     * @return array{left: int, right: int}
     */
    private static function fitIndents(array $indents, int $availableWidth): array
    {
        $budget = max(0, $availableWidth - self::MIN_CONTENT_WIDTH);
        $requested = $indents['left'] + $indents['right'];

        if ($requested <= $budget) {
            return $indents;
        }

        $left = intdiv($indents['left'] * $budget, $requested);

        return ['left' => $left, 'right' => $budget - $left];
    }

    private function containsMedia(Element $element): bool
    {
        return $element->querySelector(implode(', ', self::MEDIA_ELEMENTS)) !== null;
    }

    /**
     * Word can only number paragraphs, so a list item that starts with a table,
     * rule or nested list gets its marker on an empty paragraph placed first.
     */
    private function emitPendingMarker(InlineFlow $flow, BlockSink $sink): void
    {
        $marker = $flow->context->marker;

        if ($marker !== null && ! $marker->consumed) {
            $sink->add($this->createParagraph(new InlineFlow($flow->style, $flow->context), []));
        }
    }

    /**
     * Sets each paragraph's text as far down in Word as a browser shows it
     * (FontMetrics::baselineShift()) without moving what follows: the
     * paragraph's spacing before gains the difference and the next one's
     * gives it back. Word collapses spacing the way CSS collapses margins,
     * so the gap between two paragraphs is the larger of after and before:
     * the new gap goes into before, and after may not exceed it.
     *
     * @param  list<Block>  $blocks
     */
    private function lowerText(array $blocks): void
    {
        $previous = null;
        $lowered = 0;
        $grownBelow = 0;

        foreach ($blocks as $block) {
            if ($block instanceof Table) {
                foreach ($block->rows as $row) {
                    foreach ($row->cells as $cell) {
                        $this->lowerText($cell->blocks);
                    }
                }
            }

            $properties = $block instanceof Paragraph ? $block->properties : null;

            // A style's own spacing is not known here; leave such a paragraph as it is.
            if ($properties === null || ($properties->styleId !== null && ($properties->spacingBefore === null || $properties->spacingAfter === null))) {
                $this->giveBack($previous, $lowered, $grownBelow);
                [$previous, $lowered, $grownBelow] = [null, 0, 0];

                continue;
            }

            // A line holding only a picture starts at the picture's top in both (HtmlWriter does the same).
            $lower = self::pictureOnly($block->children) ? 0 : $this->lowered[$properties] ?? 0;

            // A browser grows a line that holds a superscript or subscript, Word does not: the paragraph takes the room in its spacing.
            [$above, $below] = $this->scriptGrowth[$properties] ?? [0, 0];

            // Word's line ends at a picture alone on it; a browser's goes on below the baseline.
            // ponytail: single spacing only; Word also multiplies a picture's line, not measured yet.
            if (self::pictureOnly($block->children) && ($properties->lineSpacing ?? 240) === 240) {
                $below += $this->pictureGap[$properties] ?? 0;
            }

            if ($lower !== 0 || $lowered !== 0 || $above !== 0 || $grownBelow !== 0) {
                $gap = max($previous->spacingAfter ?? 0, $properties->spacingBefore ?? 0) + $lower - $lowered + $above + $grownBelow;
                $properties->spacingBefore = max(0, $gap);

                if ($previous !== null && ($previous->spacingAfter ?? 0) > $properties->spacingBefore) {
                    $previous->spacingAfter = $properties->spacingBefore;
                }

                // Too little space to give back: lower the one before less, or what follows moves down.
                if ($gap < 0 && $previous !== null) {
                    $previous->spacingBefore = max(0, ($previous->spacingBefore ?? 0) + $gap);
                }
            }

            [$previous, $lowered, $grownBelow] = [$properties, $lower, $below];
        }

        $this->giveBack($previous, $lowered, $grownBelow);
    }

    /**
     * @param  list<Inline>  $children
     */
    private static function pictureOnly(array $children): bool
    {
        $pictures = array_filter($children, static fn(Inline $child): bool => $child instanceof ImageRun);

        return count($pictures) === 1 && array_filter($children, static fn(Inline $child): bool => ! $child instanceof ImageRun && ! $child instanceof Bookmark) === [];
    }

    /** The last paragraph of a run takes what it was lowered by off its own spacing after. */
    private function giveBack(?ParagraphProperties $properties, int $lowered, int $grownBelow = 0): void
    {
        if ($properties === null || ($lowered === 0 && $grownBelow === 0)) {
            return;
        }

        $after = ($properties->spacingAfter ?? 0) - $lowered + $grownBelow;
        $properties->spacingAfter = max(0, $after);

        // Too little space after to give back: lower it less, or what follows moves down.
        if ($after < 0) {
            $properties->spacingBefore = max(0, ($properties->spacingBefore ?? 0) + $after);
        }
    }

    /** What Word's Symbol bullet adds to the first line of an item in this style, in points; see HtmlWriter::bulletLine(). */
    private function bulletLine(ComputedStyle $style): float
    {
        $ascent = FontMetrics::ascent($style->fontFamily);
        [$spacing, $rule] = $this->mapper->lineSpacing($style->lineHeight, FontMetrics::singleLine($style->fontFamily) ?? 1.0, $style->fontSizePt);

        // Only a bullet drawn in Symbol grows the line, and the writer marks one by padding it (see ListCounter).
        if ($style->listStyleType !== 'disc' || $ascent === null || ($rule ?? 'auto') !== 'auto' || ($style->lengthPt('padding-top') ?? 0.0) <= 0) {
            return 0.0;
        }

        return (FontMetrics::SYMBOL_ASCENT - $ascent) * $style->fontSizePt * ($spacing ?? 240) / 240;
    }

    /**
     * @param  bool  $keepsMargins  the box keeps its content's margins inside
     *                              it (no collapsing through its edges)
     */
    private function applyVerticalMargins(BlockSink $sink, int $firstIndex, int $top, int $bottom, bool $keepsMargins = false): void
    {
        $lastIndex = $sink->count() - 1;

        if ($lastIndex < $firstIndex) {
            return;
        }

        $join = static fn(int $own, int $outer): int => $keepsMargins ? $own + $outer : max($own, $outer);
        $first = $sink->blocks[$firstIndex];

        if ($first instanceof Paragraph && $top > 0) {
            $first->properties->spacingBefore = $join($first->properties->spacingBefore ?? 0, $top);
        } elseif ($first instanceof Table && $top > 0) {
            $sink->replace($firstIndex, $first->withMargins($join($first->marginTop, $top), $first->marginBottom));
        }

        $last = $sink->blocks[$lastIndex];

        if ($last instanceof Paragraph && $bottom > 0) {
            $last->properties->spacingAfter = $join($last->properties->spacingAfter ?? 0, $bottom);
        } elseif ($last instanceof Table && $bottom > 0) {
            $sink->replace($lastIndex, $last->withMargins($last->marginTop, $join($last->marginBottom, $bottom)));
        }
    }

    private function renderTable(Element $element, ComputedStyle $style, InlineFlow $flow, BlockSink $sink): void
    {
        $this->flushParagraph($flow, $sink);

        if ($style->breaksPageBefore() || $this->pendingPageBreak) {
            $this->pendingPageBreak = true;
            $sink->add($this->createParagraph(new InlineFlow($style, $flow->context->with(['styleId' => null])), []));
        }

        $this->emitPendingMarker($flow, $sink);

        $renderContent = function (Element $container, ComputedStyle $containerStyle, BlockContext $context): array {
            $contentSink = new BlockSink();
            $this->renderChildren($container, $containerStyle, $context, $contentSink);

            return $contentSink->blocks;
        };

        foreach ($this->tables->build($element, $style, $flow->context, $renderContent) as $block) {
            $sink->add($block);
        }

        if ($style->breaksPageAfter()) {
            $this->pendingPageBreak = true;
        }
    }

    private function renderHorizontalRule(ComputedStyle $style, InlineFlow $flow, BlockSink $sink): void
    {
        $this->flushParagraph($flow, $sink);
        $this->emitPendingMarker($flow, $sink);

        $percentBase = $flow->context->availableWidth / Length::TWIPS_PER_POINT;
        $border = $this->mapper->border($style->border('top') ?? $style->border('bottom'))
            ?? new Border('single', 6, '999999');

        $sink->add($this->createParagraph(new InlineFlow($style, $flow->context), [], new ParagraphProperties(
            indentLeft: $flow->context->indentLeft,
            indentRight: $flow->context->indentRight,
            spacingBefore: Length::pointsToTwips(max(0, $style->lengthPt('margin-top', $percentBase) ?? 0)),
            spacingAfter: Length::pointsToTwips(max(0, $style->lengthPt('margin-bottom', $percentBase) ?? 0)),
            lineSpacing: 240,
            lineRule: 'auto',
            borders: new BorderSet(bottom: $border),
            markRunProperties: new RunProperties(size: 2),
        )));
    }

    private function renderImage(Element $element, ComputedStyle $style, InlineFlow $flow): void
    {
        $image = $this->images->create($element, $style, $flow->context->availableWidth, $this->mapper->run($style));

        if ($image !== null) {
            // Text wraps around a floated picture, or around the figure holding it.
            $float = self::float($style) ?? self::float($flow->style);

            if ($float !== null) {
                $image = new ImageRun(...[...get_object_vars($image), 'float' => $float]);
            }

            $flow->buffer()->appendImage($image, $flow->link);

            if ($float === null && ! $style->isBlockLevel() && in_array($style->value('vertical-align'), [null, 'baseline'], true)) {
                $block = $flow->style;
                $lineHeight = $block->lineHeight === null ? null : ($block->lineHeight->multiple !== null ? $block->lineHeight->multiple * $block->fontSizePt : $block->lineHeight->points);
                $flow->buffer()->pictureGap = max($flow->buffer()->pictureGap, FontMetrics::belowBaseline($block->fontFamily, $block->fontSizePt, $lineHeight) ?? 0.0);
            }

            // A picture in a figure set apart by auto margins, or set apart
            // itself, sits where they put it: Word aligns its paragraph instead.
            if ($float === null) {
                $flow->buffer()->alignment ??= self::autoMarginAlignment($flow->style) ?? ($style->isBlockLevel() ? self::autoMarginAlignment($style) : null);
            }

            return;
        }

        $alt = trim((string) $element->getAttribute('alt'));

        if ($alt !== '') {
            $flow->buffer()->appendText($alt, $this->mapper->run($style), $style, $flow->link);
        }
    }

    private function renderMedia(Element $element, ComputedStyle $style, InlineFlow $flow): void
    {
        $source = trim((string) ($element->getAttribute('src') ?: $element->getAttribute('data')));

        if ($source === '') {
            $source = trim((string) $element->querySelector('source[src]')?->getAttribute('src'));
        }

        $target = $source === '' ? null : $this->linkTarget($source);

        if ($target === null) {
            return;
        }

        $run = $this->mapper->run($style);
        $linkRun = new RunProperties(...[...get_object_vars($run), 'color' => '0056B3', 'underline' => 'single']);

        $flow->buffer()->appendText($source, $linkRun, $style, $target);
    }

    private function renderInput(Element $element, ComputedStyle $style, InlineFlow $flow): void
    {
        $type = strtolower((string) $element->getAttribute('type'));

        $text = match ($type) {
            'checkbox', 'radio' => ($element->hasAttribute('checked') ? "\u{2611}" : "\u{2610}") . ' ',
            'hidden' => '',
            default => (string) $element->getAttribute('value'),
        };

        if ($text !== '') {
            $flow->buffer()->appendText($text, $this->mapper->run($style), $style, $flow->link);
        }
    }

    private function flushParagraph(InlineFlow $flow, BlockSink $sink): void
    {
        $buffer = $flow->buffer;
        $flow->buffer = null;
        $inlines = $buffer?->finish();

        if ($inlines !== null) {
            $paragraph = $this->createParagraph($flow, $inlines);
            $this->scriptGrowth[$paragraph->properties] = array_map(Length::pointsToTwips(...), $buffer->scriptGrowth);
            $this->pictureGap[$paragraph->properties] = Length::pointsToTwips($buffer->pictureGap);

            if ($buffer->alignment !== null) {
                $paragraph->properties->alignment = $buffer->alignment;
            }

            $sink->add($paragraph);
        }
    }

    private static function float(ComputedStyle $style): ?string
    {
        $float = strtolower(trim((string) $style->value('float')));

        return in_array($float, ['left', 'right'], true) ? $float : null;
    }

    /** Where auto margins put a box: centred between two, to the right of one on the left. */
    private static function autoMarginAlignment(ComputedStyle $style): ?string
    {
        return match (true) {
            $style->isAuto('margin-left') && $style->isAuto('margin-right') => 'center',
            $style->isAuto('margin-left') => 'right',
            default => null,
        };
    }

    /**
     * @param  list<Inline>  $children
     */
    private function createParagraph(InlineFlow $flow, array $children, ?ParagraphProperties $properties = null): Paragraph
    {
        $style = $flow->style;
        $context = $flow->context;

        // Editors write an empty paragraph as `<p>&nbsp;</p>`.
        if (count($children) === 1 && $children[0] instanceof TextRun && $children[0]->text === "\u{00A0}") {
            $children = [];
        }

        if ($properties === null) {
            // A multiple of the font size is Word's multiple of the font's own single line; see HtmlWriter.
            [$lineSpacing, $lineRule] = $this->mapper->lineSpacing($style->lineHeight, FontMetrics::singleLine($style->fontFamily) ?? 1.0, $style->fontSizePt);

            $properties = new ParagraphProperties(
                styleId: $context->styleId,
                alignment: $this->mapper->alignment($style->textAlign),
                indentLeft: $context->indentLeft,
                indentRight: $context->indentRight,
                firstLine: $style->textIndentPt === null ? 0 : Length::pointsToTwips($style->textIndentPt),
                lineSpacing: $lineSpacing,
                lineRule: $lineRule,
                keepNext: $style->keepsWithNext(),
                keepLines: $style->keepsTogether(),
                shading: $context->shading,
                borders: $context->borders,
                bidi: $style->direction === 'rtl',
                markRunProperties: $this->mapper->run($style),
            );

            // Where the browser shows the text relative to Word, less what the
            // HTML itself raises it by (HtmlWriter::raise() writes that).
            $shift = $lineRule === null || $lineRule === 'auto'
                ? FontMetrics::baselineShift($style->fontFamily, $style->fontSizePt, $style->lineHeight === null ? null : ($style->lineHeight->multiple !== null ? $style->lineHeight->multiple * $style->fontSizePt : $style->lineHeight->points))
                : null;
            $lower = Length::pointsToTwips(($shift ?? 0.0) + $style->relativeTopPt);

            if ($lower !== 0) {
                $this->lowered[$properties] = $lower;
            }

            if ($context->marker !== null && ! $context->marker->consumed) {
                // HtmlWriter pads the first line of an item by what Word's Symbol
                // bullet adds to it; that padding says the bullet was Symbol's.
                $properties->numbering = $context->marker->reference(($style->lengthPt('padding-top') ?? 0.0) > 0);
                // A marker inside the first line hangs nowhere.
                $properties->firstLine = $context->list?->hanging === 0 ? 0 : -min(360, $context->indentLeft);
            }
        }

        if ($this->pendingPageBreak) {
            $properties->pageBreakBefore = true;
            $this->pendingPageBreak = false;
        }

        $bookmarks = $this->pendingBookmarks;
        $this->pendingBookmarks = [];

        return new Paragraph($properties, [...$bookmarks, ...$children]);
    }

    /**
     * The LaTeX of a formula element, in any shape an editor keeps one:
     * SunEditor's KaTeX span, MathML with a TeX annotation, TipTap's math
     * nodes, and the `\(…\)` of a `math-tex` span (MathJax, ckeditor5-math).
     */
    private static function latex(Element $element): ?string
    {
        $class = ' ' . $element->getAttribute('class') . ' ';
        $type = (string) $element->getAttribute('data-type');

        $latex = match (true) {
            // SunEditor 3 keeps it in data-se-value, SunEditor 2 in data-exp.
            $element->hasAttribute('data-se-value') && str_contains($class, ' se-math ') => $element->getAttribute('data-se-value'),
            $element->hasAttribute('data-exp') && str_contains($class, 'katex') => $element->getAttribute('data-exp'),
            $element->localName === 'math' => $element->querySelector('annotation[encoding="application/x-tex"]')?->textContent,
            $type === 'inline-math' || $type === 'block-math' => $element->getAttribute('data-latex'),
            str_contains($class, ' math-tex ') => preg_replace('/^\s*\\\\[(\[]\s*|\s*\\\\[)\]]\s*$/', '', (string) $element->textContent),
            default => false,
        };

        return $latex === false ? null : trim((string) $latex);
    }

    /** Whether a formula element is set on a line of its own, as Word's display math is. */
    private static function isDisplay(Element $element): bool
    {
        return match (true) {
            $element->localName === 'math' => $element->getAttribute('display') === 'block',
            $element->hasAttribute('data-type') => $element->getAttribute('data-type') === 'block-math',
            default => str_starts_with(trim((string) $element->textContent), '\\['),
        };
    }

    /**
     * Finds the note sections this library's HTML writer emits — `<ol class="se-footnotes">`
     * and `<ol class="se-endnotes">`, or in plain HTML the list in `<section class="footnotes">`
     * and `<section class="endnotes">`, the rule above each and the links back to the marks —
     * so a document that came from a DOCX keeps its notes as notes on the way back.
     */
    private function collectNotes(HtmlDocument $html): void
    {
        foreach (self::NOTE_LISTS as $type => $selector) {
            foreach ([...$html->body()->querySelectorAll($selector), ...self::listsOfNotes($html, $type)] as $list) {
                if (isset($this->noteLists[spl_object_id($list)])) {
                    continue;
                }

                $this->noteLists[spl_object_id($list)] = ['type' => $type, 'list' => $list];
                $separator = $list->previousElementSibling;

                if ($separator?->localName === 'hr') {
                    $this->noteDecorations[spl_object_id($separator)] = $separator;
                }

                $number = 1;

                foreach (HtmlDocument::elementChildren($list) as $item) {
                    if ($item->localName !== 'li') {
                        continue;
                    }

                    $value = (int) $item->getAttribute('value');
                    $number = $value > 0 ? $value : $number;
                    $this->noteBodies[spl_object_id($item)] = ['number' => $number, 'item' => $item];
                    $id = (string) $item->getAttribute('id');

                    if ($id !== '') {
                        $this->noteItems[$id] = ['type' => $type, 'number' => $number];
                        unset($this->linkedFragments[$id]);
                    }

                    $this->collectBacklinks($html, $item, $id);
                    $number++;
                }
            }
        }
    }

    /**
     * CKEditor wraps a table in `<figure class="table">` and moves its width
     * there; the table goes back in the figure's place, with the width.
     */
    private static function unwrapTableFigures(HtmlDocument $html): void
    {
        // CKEditor's figure.table, and the figure SunEditor 3 scrolls a table in.
        foreach ($html->body()->querySelectorAll('figure.table, figure.se-flex-component') as $figure) {
            $table = $figure->firstElementChild;

            if ($table?->localName !== 'table' || $table->nextElementSibling !== null) {
                continue;
            }

            $width = preg_match('/(?:^|;)\s*width\s*:\s*([^;]+)/i', (string) $figure->getAttribute('style'), $match) === 1 ? trim($match[1]) : null;

            // SunEditor 3's figure is as wide as its content (max-content) unless it says otherwise.
            if ($width === null && str_contains(' ' . $figure->getAttribute('class') . ' ', ' se-flex-component ')) {
                $width = 'auto';
            }

            // The figure has the width; a table filling it has the same.
            $style = (string) preg_replace('/(?:^|;)\s*width\s*:\s*100%\s*(?=;|$)/i', '', (string) $table->getAttribute('style'));

            if ($width !== null && preg_match('/(?:^|;)\s*width\s*:/i', $style) !== 1) {
                $table->setAttribute('style', rtrim("width: {$width}; " . ltrim($style, '; '), '; ') . ';');
            }

            $figure->replaceWith($table);
        }
    }

    /**
     * Note lists an editor stripped of their section and classes, found by
     * the ids this library gives their items (`footnote-1`, `endnote-1`).
     *
     * @return list<Element>
     */
    private static function listsOfNotes(HtmlDocument $html, string $type): array
    {
        $lists = [];

        foreach ($html->body()->querySelectorAll('ol') as $list) {
            $first = $list->firstElementChild;

            if ($first?->localName === 'li' && preg_match('/(^|[-_])' . $type . '-\\d+$/', (string) $first->getAttribute('id')) === 1) {
                $lists[] = $list;
            }
        }

        return $lists;
    }

    /** Marks the "back to the mark" links of one note body, which the mark itself replaces. */
    private function collectBacklinks(HtmlDocument $html, Element $item, string $itemId): void
    {
        if ($itemId === '') {
            return;
        }

        foreach ($item->querySelectorAll('a[href^="#"]') as $anchor) {
            $fragment = self::fragment($anchor);
            $target = $fragment === null ? null : $html->native()->getElementById($fragment);

            // The mark's own id may be gone (TipTap keeps none on links); its name still tells.
            $mark = preg_replace('/-(\\d+)$/', '-ref-$1', $itemId);

            if ($target?->localName === 'a' && self::fragment($target) === $itemId || $target === null && $fragment === $mark) {
                $this->noteDecorations[spl_object_id($anchor)] = $anchor;
                unset($this->linkedFragments[$fragment]);
            }
        }
    }

    /** Turns one note section back into notes; their bodies start at the page's own text column. */
    private function renderNotes(Element $list, string $type, ComputedStyle $style, BlockContext $context): void
    {
        foreach (HtmlDocument::elementChildren($list) as $item) {
            $body = $this->noteBodies[spl_object_id($item)] ?? null;

            if ($body === null) {
                continue;
            }

            $sink = new BlockSink();
            $this->renderChildren($item, $this->resolver->resolve($item, $style), new BlockContext($context->availableWidth), $sink);
            $this->notes[] = new Note($type, $body['number'], BlockNormalizer::normalize($sink->blocks));
        }
    }

    /**
     * Finds the `<div class="se-header">` and `<div class="se-footer">` this library's
     * HTML writer emits; `data-type` tells the first-page and even-page variants apart.
     */
    private function collectHeadersFooters(HtmlDocument $html): void
    {
        foreach ([HeaderFooter::HEADER, HeaderFooter::FOOTER] as $kind) {
            foreach ($html->body()->querySelectorAll("div.se-{$kind}") as $element) {
                $type = strtolower((string) $element->getAttribute('data-type'));

                $this->furnitureSections[spl_object_id($element)] = [
                    'kind' => $kind,
                    'type' => in_array($type, [HeaderFooter::FIRST, HeaderFooter::EVEN], true) ? $type : HeaderFooter::DEFAULT,
                    'element' => $element,
                ];
            }
        }
    }

    private function hasHeaderFooter(string $kind, string $type): bool
    {
        foreach ($this->headersFooters as $headerFooter) {
            if ($headerFooter->kind === $kind && $headerFooter->type === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the comment list (`<ol class="se-comments">`, one `li` per comment, its id in
     * `data-comment`) and the spans marking the text each comment is about. A range may
     * be split over several spans; it runs from the first to the last.
     */
    private function collectComments(HtmlDocument $html): void
    {
        foreach ($html->body()->querySelectorAll('ol.se-comments') as $list) {
            $this->commentLists[spl_object_id($list)] = $list;

            foreach (HtmlDocument::elementChildren($list) as $item) {
                $id = $item->localName === 'li' ? (self::commentIds($item)[0] ?? null) : null;

                if ($id !== null) {
                    $this->commentItems[spl_object_id($item)] = ['item' => $item, 'id' => $id];
                }
            }
        }

        foreach ($html->body()->querySelectorAll('span.se-comment[data-comment]') as $span) {
            foreach (self::commentIds($span) as $id) {
                $this->commentFirstSpans[$id] ??= $span;
                $this->commentLastSpans[$id] = $span;
            }
        }
    }

    /**
     * @return list<int>
     */
    private static function commentIds(Element $element): array
    {
        $ids = [];

        foreach (preg_split('/\s+/', trim((string) $element->getAttribute('data-comment'))) ?: [] as $token) {
            if (ctype_digit($token) && (int) $token > 0) {
                $ids[] = (int) $token;
            }
        }

        return $ids;
    }

    private function renderComments(Element $list, ComputedStyle $style): void
    {
        $seen = [];

        foreach ($this->comments as $comment) {
            $seen[$comment->id] = true;
        }

        foreach (HtmlDocument::elementChildren($list) as $item) {
            $entry = $this->commentItems[spl_object_id($item)] ?? null;

            if ($entry === null || isset($seen[$entry['id']])) {
                continue;
            }

            $seen[$entry['id']] = true;
            $parent = (int) $item->getAttribute('data-parent');
            $author = trim((string) $item->getAttribute('data-author'));
            $initials = trim((string) $item->getAttribute('data-initials'));

            $this->comments[] = new Comment(
                id: $entry['id'],
                blocks: $this->renderApart($item, $this->resolver->resolve($item, $style)),
                author: $author === '' ? null : $author,
                initials: $initials === '' ? null : $initials,
                date: self::date((string) $item->getAttribute('data-date')),
                parentId: $parent > 0 && $parent !== $entry['id'] ? $parent : null,
                resolved: $item->getAttribute('data-resolved') === 'true',
            );
        }
    }

    /**
     * A header or footer line: SunEditor's div is the line itself, with its own
     * margins; the 1.x div around paragraphs has none.
     *
     * @return list<Block>
     */
    private function renderLine(Element $element, ComputedStyle $style): array
    {
        $sink = new BlockSink();
        $flow = new InlineFlow($style, new BlockContext($this->pageContentWidth));
        $this->renderBlock($element, $style, $flow, $sink);
        $this->flushParagraph($flow, $sink);

        return BlockNormalizer::normalize($sink->blocks);
    }

    /**
     * Content kept apart from the body — a comment — laid out on the page's
     * own text column.
     *
     * @return list<Block>
     */
    private function renderApart(Element $container, ComputedStyle $style): array
    {
        $sink = new BlockSink();
        $this->renderChildren($container, $style, new BlockContext($this->pageContentWidth), $sink);

        return BlockNormalizer::normalize($sink->blocks);
    }

    private static function date(string $value): ?DateTimeImmutable
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** The id an in-document link points at, or null for any other href. */
    private static function fragment(Element $anchor): ?string
    {
        $href = trim((string) $anchor->getAttribute('href'));

        if (! str_starts_with($href, '#')) {
            return null;
        }

        $fragment = rawurldecode(substr($href, 1));

        return $fragment === '' ? null : $fragment;
    }

    private function queueBookmarks(Element $element): void
    {
        $ids = [(string) $element->getAttribute('id')];

        if ($element->localName === 'a') {
            $ids[] = (string) $element->getAttribute('name');
        }

        foreach ($ids as $id) {
            if ($id !== '' && isset($this->linkedFragments[$id]) && ! isset($this->placedBookmarks[$id])) {
                $this->placedBookmarks[$id] = true;
                $this->pendingBookmarks[] = new Bookmark($this->bookmarks->nextId(), $this->bookmarks->nameFor($id));
            }
        }
    }

    private function linkTarget(string $href): ?LinkTarget
    {
        $href = trim($href);

        if (str_starts_with($href, '#')) {
            $fragment = rawurldecode(substr($href, 1));

            return $fragment === '' ? null : LinkTarget::anchor($this->bookmarks->nameFor($fragment));
        }

        // One filter for both directions, so a scheme hidden by a tab or a control character is caught here too.
        if (InlineWriter::safeUrl($href) === null) {
            return null;
        }

        $encoded = (string) preg_replace_callback(
            '/[^\x21-\x7E]|["<>\\\\^`{|}]/',
            static fn(array $m): string => rawurlencode($m[0]),
            $href,
        );

        return LinkTarget::url($encoded);
    }
}
