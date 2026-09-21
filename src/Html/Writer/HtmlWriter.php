<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Closure;
use DateTimeZone;
use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\FontMetrics;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Docx\Reader\NumberFormat;
use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\Image\DataUriImageHandler;
use Kovami\HtmlDocx\Image\ImageHandler;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Bookmark;
use Kovami\HtmlDocx\Model\BreakRun;
use Kovami\HtmlDocx\Model\Comment;
use Kovami\HtmlDocx\Model\CommentEnd;
use Kovami\HtmlDocx\Model\CommentStart;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\HeaderFooter;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\ListLevel;
use Kovami\HtmlDocx\Model\Note;
use Kovami\HtmlDocx\Model\NumberingReference;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\Table;
use Kovami\HtmlDocx\Model\TabRun;
use Kovami\HtmlDocx\Model\TextRun;
use Kovami\HtmlDocx\Options;

/**
 * Writes the document model as HTML a rich-text editor can load back:
 * formatting is visible (inline styles rather than semantic classes), but
 * only what differs from the editor's own stylesheet is spelled out, so a
 * document survives HTML → DOCX → HTML unchanged.
 *
 * Page headers open the output and page footers close it, as
 * `<div class="se-header">` / `<div class="se-footer">` (with `data-type`
 * for the first-page and even-page variants); footnotes, endnotes and
 * comments are lists between the body and the footers. The text a comment
 * is about is wrapped in `<span class="se-comment" data-comment="…">`.
 *
 * Between two paragraphs Word leaves the larger of the first one's space
 * after and the second one's space before, as collapsing CSS margins do, so
 * each paragraph's margins carry its own spacing.
 *
 * One instance converts one document.
 */
final class HtmlWriter
{
    /** Word's default tab stop interval, in twips. */
    private const int WORD_TAB_STOP = 720;

    /** Lists open around the block currently being written, outermost first. */
    private ListStack $lists;

    private readonly WriterContext $context;

    private readonly InlineWriter $inlines;

    private readonly TableWriter $tables;

    /**
     * How far each block's text is raised to sit where Word sets it, in
     * points (see leadingAbove()); a nested block is raised with its parent.
     *
     * @var \WeakMap<Element, float>
     */
    private \WeakMap $raised;

    /**
     * @param  Editor|null  $editor  the editor the HTML is written for; null for plain HTML
     * @param  Closure(string): void|null  $warn  receives a message for every piece of content that could not be converted
     */
    public function __construct(
        Document $document,
        ?Editor $editor,
        Options $options = new Options(),
        ?ImageHandler $images = null,
        ?Closure $warn = null,
    ) {
        $this->context = new WriterContext(
            $document,
            $editor,
            $options,
            $images ?? new DataUriImageHandler(),
            $warn ?? static function (string $message): void {},
        );
        $this->inlines = new InlineWriter($this->context);
        $this->tables = new TableWriter($this->context, $this->writeBlocks(...));
        $this->lists = new ListStack();
        $this->raised = new \WeakMap();
    }

    public function toHtml(): string
    {
        $document = $this->context->document;
        $options = $this->context->options;

        $root = ComputedStyle::root($options->baseFontFamily(), $options->baseFontSizePt(), strtoupper($options->baseTextColor()));
        $body = $this->context->dom->createElement('body');

        if (! $this->context->plain) {
            $body->setAttribute('class', 'sun-editor-editable');
        }

        $this->context->dom->append($body);

        $style = $this->context->resolver->resolve($body, $root);

        $this->headersFooters(HeaderFooter::HEADER, $body, $style);
        $this->writeBlocks($document->blocks, $body, $style, 'p', $document->pageLayout->contentWidthTwips());
        $this->notes($body, $style);
        $this->comments($body, $style);
        $this->headersFooters(HeaderFooter::FOOTER, $body, $style);
        $this->reportCuts();

        $fragment = [];

        foreach ($body->childNodes as $child) {
            $fragment[] = $this->context->dom->saveHtml($child);
        }

        $html = implode("\n", $fragment);

        return $options->fullHtmlDocument ? $this->fullDocument($html) : $html;
    }

    /**
     * @param  list<Block>  $blocks
     * @param  string  $paragraphTag  element a paragraph becomes: `p` in the body, `div` inside a table cell
     * @param  int  $availableWidth  twips the container leaves for content
     */
    public function writeBlocks(array $blocks, Element $parent, ComputedStyle $parentStyle, string $paragraphTag = 'p', int $availableWidth = 0): void
    {
        $enclosing = $this->lists;
        $this->lists = new ListStack();

        foreach ($blocks as $block) {
            if ($block instanceof Table) {
                $this->lists->clear();
                // A table has no spacing of its own in Word: the model moved it
                // onto the neighbouring paragraphs, which carry it in CSS too.
                $this->tables->write($block, $parent, $parentStyle, $availableWidth, $block->marginTop, $block->marginBottom);

                continue;
            }

            if (! $block instanceof Paragraph) {
                continue;
            }

            $numbering = $block->properties->numbering;

            if ($numbering === null) {
                $frame = $this->lists->isEmpty() ? null : $this->lists->top();

                // An unnumbered paragraph indented to the open item's text is a
                // second paragraph of that item, not the end of the list.
                if ($frame !== null && $frame->item !== null && $frame->itemStyle !== null
                    && $block->properties->indentLeft >= $frame->indent) {
                    $this->paragraph($block, $frame->item, $frame->itemStyle, $paragraphTag, $frame->indent);
                } else {
                    $this->lists->clear();
                    $this->paragraph($block, $parent, $parentStyle, $paragraphTag, 0);
                }
            } else {
                $item = $this->listItem($numbering, $block->properties, $parent, $parentStyle);
                $this->paragraph($block, $item['list'], $item['style'], $paragraphTag, $item['indent'], $item['element']);
            }
        }

        $this->lists = $enclosing;
    }

    /**
     * @param  Element  $parent  where new elements go
     * @param  ComputedStyle  $parentStyle  the computed style of $parent
     * @param  int  $indentBase  indentation the container already provides, in twips
     * @param  Element|null  $item  the `li` to fill, when the paragraph is a list item
     */
    private function paragraph(
        Paragraph $paragraph,
        Element $parent,
        ComputedStyle $parentStyle,
        string $tag,
        int $indentBase,
        ?Element $item = null,
    ): void {
        $properties = $paragraph->properties;
        $heading = $this->headingTag($properties);
        $segments = self::splitOnPageBreaks($paragraph->children);

        if ($item === null && count($segments) === 1 && ! $this->context->plain) {
            $image = self::imageOnly($segments[0]);

            if ($image !== null) {
                $this->imageComponent($image, $properties, $segments[0], $parent, $parentStyle);

                return;
            }
        }

        // TipTap keeps a list item's text in a paragraph of its own; the item
        // keeps the font, which its marker is drawn in.
        if ($item !== null && $this->context->editor === Editor::TipTap) {
            $this->context->style($item, $parentStyle, fn(ComputedStyle $editor): array => $this->blockFont($properties, $editor));
        }

        $itemStyle = $item === null ? null : $this->context->resolver->resolve($item, $parentStyle);
        $last = count($segments) - 1;

        foreach ($segments as $index => $children) {
            $fills = $item !== null && $index === 0 && $heading === null && $this->context->editor !== Editor::TipTap;

            if ($fills) {
                $element = $item;
                $baseline = $parentStyle;
            } else {
                $element = $this->context->element($heading ?? ($item === null || $this->context->plain ? $tag : 'div'), $item ?? $parent);
                $baseline = $item === null ? $parentStyle : $itemStyle;
            }

            $top = $index === 0 ? ($properties->spacingBefore ?? 0) : 0;
            $bottom = $index === $last ? ($properties->spacingAfter ?? 0) : 0;
            $pageBreak = $index === 0 ? $properties->pageBreakBefore : true;
            $preserve = self::preservesWhitespace($children);
            // An empty paragraph is as tall as its paragraph mark, the only
            // formatting it has left.
            $mark = self::isEmpty($children) ? $properties->markRunProperties : null;

            $style = $this->context->style(
                $element,
                $baseline ?? $parentStyle,
                fn(ComputedStyle $editor): array => $this->paragraphCss($properties, $editor, $top, $bottom, $pageBreak, $preserve, $indentBase, $item !== null, $mark)
                    // A line holding only a picture starts at the picture's top in both.
                    + $this->raise($element, self::imageOnly($children) === null ? $this->leadingAbove($properties, $mark) : 0.0)
                    + ($index === 0 && $item !== null ? $this->bulletLine($properties) : []),
            );

            // A bookmark at the start of a paragraph is the paragraph's id: editors drop empty anchors.
            $first = $children[0] ?? null;

            if ($this->context->plain && $first instanceof Bookmark && ! $element->hasAttribute('id')) {
                $element->setAttribute('id', $this->context->bookmarkId($first->name));
                $children = array_slice($children, 1);
            }

            $this->inlines->write($children, $element, $style);
            $this->closeParagraph($children, $element, $style);
            $this->standPictureOnLineBottom($children, $properties, $element);
        }
    }

    /**
     * Word's line ends at a picture alone on it; a browser's goes on below the
     * baseline the picture stands on. At the line's bottom it leaves no gap
     * (DocumentBuilder adds that gap to a picture that does stand on the baseline).
     *
     * @param  list<Inline>  $children
     */
    private function standPictureOnLineBottom(array $children, ParagraphProperties $properties, Element $element): void
    {
        $image = self::imageOnly($children);

        if ($image === null || $image->float !== null || ($properties->lineSpacing ?? 240) !== 240) {
            return;
        }

        foreach ($element->getElementsByTagName('img') as $img) {
            $img->setAttribute('style', trim($img->getAttribute('style') . ' vertical-align: bottom;'));
        }
    }

    /**
     * An empty paragraph still needs a line, and a trailing line break is
     * dropped by HTML layout unless a second one follows it.
     *
     * @param  list<Inline>  $children
     */
    private function closeParagraph(array $children, Element $element, ComputedStyle $style): void
    {
        $visible = array_values(array_filter($children, static fn(Inline $child): bool => ! self::isMarker($child)));
        $last = $visible === [] ? null : $visible[count($visible) - 1];

        while ($last instanceof Hyperlink) {
            $last = $last->children === [] ? null : $last->children[count($last->children) - 1];
        }

        // SunEditor 3 drops an empty line with only a <br> in a table cell, and the
        // style of a <br> anywhere, so its empty line holds a no-break space instead
        // (which the reader reads as empty, as editors write it).
        if ($visible === [] && $this->context->editor === Editor::SunEditor) {
            $element->append("\u{00A0}");
        } elseif ($visible === [] || $last instanceof BreakRun) {
            $this->inlines->inheritLineHeight($this->context->element('br', $element), $style);
        }
    }

    /**
     * @param  list<Inline>  $children
     */
    private static function isEmpty(array $children): bool
    {
        foreach ($children as $child) {
            if (! self::isMarker($child)) {
                return false;
            }
        }

        return true;
    }

    /** Content that marks a position and takes no room: bookmarks and comment boundaries. */
    private static function isMarker(Inline $inline): bool
    {
        return $inline instanceof Bookmark || $inline instanceof CommentStart || $inline instanceof CommentEnd;
    }

    /**
     * Formatting of one paragraph, as the declarations that differ from what
     * the editor's stylesheet already gives the element.
     *
     * @return array<string, string>
     */
    private function paragraphCss(
        ParagraphProperties $properties,
        ComputedStyle $editor,
        int $top,
        int $bottom,
        bool $pageBreak,
        bool $preserve,
        int $indentBase,
        bool $isItem,
        ?RunProperties $mark,
    ): array {
        $css = [];

        $alignment = match ($properties->alignment) {
            'both' => 'justify',
            'left', 'center', 'right' => $properties->alignment,
            default => null,
        };
        $inherited = $editor->textAlign;

        if ($alignment !== null && $alignment !== $inherited) {
            $css['text-align'] = $alignment;
        } elseif ($alignment === null && $inherited !== null && $inherited !== ($properties->bidi ? 'right' : 'left')) {
            // Word's default: the start of the line, which HTML reads back as left (or right).
            $css['text-align'] = $properties->bidi ? 'right' : 'left';
        }

        if (($css['text-align'] ?? $inherited) === 'justify') {
            // Word (2013 and later) squeezes the spaces of a justified line
            // to fit one more word, where a browser only ever stretches them:
            // narrower spaces let the browser break the lines where Word does.
            // ponytail: one width for every font (about 30% of Calibri's space), per-font space widths if a font breaks differently.
            $css['word-spacing'] = '-0.065em';
        }

        // Word indents to the text edge; CSS margins sit outside the border
        // and the padding the border's spacing becomes.
        $frame = [];

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $border = $properties->borders->{$side};
            $frame[$side] = $border === null ? 0 : Length::pointsToTwips($border->space + ($side === 'top' || $side === 'bottom' ? 0 : $border->size / 8));
        }

        // A border's space is padding in CSS, which takes room above and
        // below the text; Word's does not, so the spacing gives it that room.
        $top = max(0, $top - $frame['top']);
        $bottom = max(0, $bottom - $frame['bottom']);

        // Any editor's CSS may move a block, so every edge is spelled out.
        $css += $this->blockFont($properties, $editor);
        $css['margin'] = implode(' ', array_map(
            fn(int $twips): string => $this->context->css->twips($twips),
            [$top, $properties->indentRight - $frame['right'], $bottom, $properties->indentLeft - $indentBase - $frame['left']],
        ));

        if (! $isItem) {
            $indent = $this->context->css->length($properties->firstLine, $editor->textIndentPt);

            if ($indent !== null) {
                $css['text-indent'] = $indent;
            }
        }

        $css['line-height'] = $this->lineHeight($properties->lineSpacing ?? 240, $properties->lineRule ?? 'auto', $this->blockFamily($properties));

        $background = $editor->backgroundColor();

        if ($properties->shading !== null ? strcasecmp($properties->shading, (string) $background) !== 0 : $background !== null) {
            $css['background-color'] = $properties->shading === null ? 'transparent' : CssFormatter::color($properties->shading);
        }

        $css += $this->context->css->sides($properties->borders, $editor);

        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $border = $properties->borders->{$side};

            if ($border !== null && $border->space > 0) {
                $css["padding-{$side}"] = $this->context->css->points($border->space);
            }
        }

        $direction = $properties->bidi ? 'rtl' : 'ltr';

        if ($direction !== $editor->direction) {
            $css['direction'] = $direction;
        }

        if ($pageBreak) {
            $css['page-break-before'] = 'always';
        }

        // Word's "keep with next" and "keep lines together" decide where its pages break.
        if ($properties->keepNext) {
            $css['break-after'] = 'avoid';
        }

        if ($properties->keepLines) {
            $css['break-inside'] = 'avoid';
        }

        if ($preserve && ! $editor->preservesWhitespace()) {
            $css['white-space'] = 'pre-wrap';

            // Word's default tab stops, rather than eight spaces.
            $css['tab-size'] = $this->context->css->twips(self::WORD_TAB_STOP);
        }

        if ($mark?->size !== null && abs($mark->size / 2 - $editor->fontSizePt) > 0.01) {
            $css['font-size'] = $this->context->css->points($mark->size / 2);
        }

        return $css;
    }

    /**
     * The font a plain block sets for its text: its paragraph mark's, which
     * carries the paragraph style's font; runs formatted otherwise override it.
     *
     * @return array<string, string>
     */
    private function blockFont(ParagraphProperties $properties, ComputedStyle $editor): array
    {
        $mark = $this->blockRun($properties);
        $defaults = $this->context->document->defaultRunProperties;
        $options = $this->context->options;
        $size = $mark->size ?? $defaults->size;

        $css = [
            'font-family' => CssFormatter::fontStack($this->blockFamily($properties)),
            'font-size' => $this->context->css->points($size === null ? $options->baseFontSizePt() : $size / 2),
            'color' => CssFormatter::color($mark->color ?? $defaults->color ?? $options->baseTextColor()),
        ];

        // Headings are bold in a browser, and seldom are in Word. Bold text itself
        // stays in <strong>, which an editor's bold button recognises.
        if ($editor->bold && ! ($mark->bold ?? false)) {
            $css['font-weight'] = 'normal';
        }

        return $css;
    }

    /** The formatting of a paragraph's mark, which carries its style's font. */
    private function blockRun(ParagraphProperties $properties): ?RunProperties
    {
        return $properties->markRunProperties ?? $this->context->document->style($properties->styleId)?->run;
    }

    private function blockFamily(ParagraphProperties $properties): string
    {
        return $this->blockRun($properties)->fontFamily
            ?? $this->context->document->defaultRunProperties->fontFamily
            ?? $this->context->options->baseFontFamily();
    }

    /**
     * Word's line pitch. Word multiplies the font's own single
     * line, CSS the font size, so a multiple is scaled by the font's metrics;
     * a font without them keeps `normal` for single spacing.
     *
     * @param  string  $rule  ST_LineSpacingRule: auto, atLeast or exact
     */
    private function lineHeight(int $lineSpacing, string $rule, string $family): string
    {
        if ($rule !== 'auto') {
            return $this->context->css->twips($lineSpacing);
        }

        $single = FontMetrics::singleLine($family);

        if ($single === null) {
            return $lineSpacing === 240 ? 'normal' : CssFormatter::number($lineSpacing / 240);
        }

        // Three decimals, so the multiple Word stored comes back to the twip.
        return rtrim(rtrim(number_format($lineSpacing / 240 * $single, 3, '.', ''), '0'), '.');
    }

    /**
     * Raises a block's text by `$points`, less what its ancestors already
     * raise it by: `top` of a relatively positioned block moves what it holds.
     *
     * @return array<string, string>
     */
    private function raise(Element $element, float $points): array
    {
        $inherited = 0.0;

        for ($node = $element->parentElement; $node !== null; $node = $node->parentElement) {
            if (isset($this->raised[$node])) {
                $inherited = $this->raised[$node];

                break;
            }
        }

        $this->raised[$element] = $points;
        $top = $this->context->css->points($inherited - $points);

        return $top === '0' ? [] : ['position' => 'relative', 'top' => $top];
    }

    /**
     * Word draws its bullet in Symbol, whose ascent is taller than that of
     * Calibri, Cambria, Arial or Times: a line holds the tallest ascent of its
     * fonts, so the first line of such an item is taller by the difference.
     *
     * @return array<string, string>
     */
    private function bulletLine(ParagraphProperties $properties): array
    {
        $numbering = $properties->numbering;
        $level = $numbering === null ? null : $this->context->document->list($numbering->numId)->levels[$numbering->level] ?? null;
        $ascent = FontMetrics::ascent($this->blockFamily($properties));

        if ($level?->format !== 'bullet' || $level->text !== "\u{2022}" || $ascent === null || ($properties->lineRule ?? 'auto') !== 'auto') {
            return [];
        }

        $size = $this->blockRun($properties)->size ?? $this->context->document->defaultRunProperties->size;
        $points = $size === null ? $this->context->options->baseFontSizePt() : $size / 2;

        return ['padding-top' => $this->context->css->points((FontMetrics::SYMBOL_ASCENT - $ascent) * $points * ($properties->lineSpacing ?? 240) / 240)];
    }

    /**
     * How much lower than Word a browser sets the text of a paragraph, in
     * points (FontMetrics::baselineShift()): the line height written is the
     * multiple of the font's single line that lineHeight() gives.
     */
    private function leadingAbove(ParagraphProperties $properties, ?RunProperties $mark): float
    {
        $family = $this->blockFamily($properties);
        $single = FontMetrics::singleLine($family);

        if ($single === null || ($properties->lineRule ?? 'auto') !== 'auto') {
            return 0;
        }

        // ponytail: measured from the paragraph's own font; Word takes the tallest font on each line.
        $size = $mark->size ?? $this->blockRun($properties)->size ?? $this->context->document->defaultRunProperties->size;
        $points = $size === null ? $this->context->options->baseFontSizePt() : $size / 2;

        return FontMetrics::baselineShift($family, $points, ($properties->lineSpacing ?? 240) / 240 * $single * $points) ?? 0.0;
    }

    /**
     * A paragraph holding nothing but a picture becomes SunEditor's image
     * component, the shape its toolbar can select, resize and align again.
     *
     * @param  list<Inline>  $children
     */
    private function imageComponent(
        ImageRun $image,
        ParagraphProperties $properties,
        array $children,
        Element $parent,
        ComputedStyle $parentStyle,
    ): void {
        $float = $image->float ?? match ($properties->alignment) {
            'center' => 'center',
            'right' => 'right',
            'left' => 'left',
            default => 'none',
        };

        $container = $this->context->element('div', $parent);
        $container->setAttribute('class', "se-component se-image-container __se__float-{$float}");
        $container->setAttribute('contenteditable', 'false');

        $this->context->style($container, $parentStyle, function (ComputedStyle $editor) use ($properties): array {
            $css = [];
            $margins = ['margin-top' => $properties->spacingBefore ?? 0, 'margin-bottom' => $properties->spacingAfter ?? 0];

            foreach ($margins as $property => $twips) {
                $value = $this->context->css->length($twips, $editor->lengthPt($property));

                if ($value !== null) {
                    $css[$property] = $value;
                }
            }

            if ($properties->pageBreakBefore) {
                $css['page-break-before'] = 'always';
            }

            return $css;
        });

        foreach ($children as $child) {
            if ($child instanceof Bookmark) {
                $this->context->element('a', $container)->setAttribute('id', $this->context->bookmarkId($child->name));
            }
        }

        [$width, $height] = InlineWriter::pixelSize($image);
        $figure = $this->context->element('figure', $container);
        // The figure sits where Word puts the picture; SunEditor keeps its margin.
        $margin = match ($float) {
            'center' => 'auto',
            'right' => '0 0 0 auto',
            default => '0',
        };
        $figure->setAttribute('style', "margin: {$margin}; width: {$width}px;");

        $element = $this->inlines->imageElement($image, $figure);

        if ($element === null) {
            $container->remove();

            return;
        }

        $element->setAttribute('data-proportion', 'true');
        $element->setAttribute('data-align', $float);
        $element->setAttribute('data-se-size', "{$width}px,{$height}px");

        if ($image->description !== '') {
            $element->setAttribute('data-se-file-name', $image->description);
        }
    }

    /**
     * Opens, closes and continues the lists around a numbered paragraph, and
     * returns the `li` its content belongs in.
     *
     * @return array{element: Element, list: Element, style: ComputedStyle, indent: int}
     */
    private function listItem(NumberingReference $numbering, ParagraphProperties $properties, Element $parent, ComputedStyle $parentStyle): array
    {
        $definition = $this->context->document->list($numbering->numId);
        $level = $definition->levels[$numbering->level] ?? null;
        [$tag, $marker] = self::markerStyle($level);

        $this->lists->closeTo($numbering->level, $tag, $marker);

        while ($this->lists->depth() < $numbering->level) {
            $open = $this->lists->isEmpty() ? $numbering->level : $this->lists->depth() + 1;
            $isTarget = $open === $numbering->level;
            $openLevel = $definition->levels[$open] ?? null;
            [$openTag, $openMarker] = $isTarget ? [$tag, $marker] : self::markerStyle($openLevel);

            $this->openList(
                $numbering->numId,
                $open,
                $openTag,
                $openMarker,
                $isTarget ? ($numbering->ordinal ?? $openLevel->start ?? 1) : ($openLevel->start ?? 1),
                $isTarget ? $properties->indentLeft : ($openLevel->indentLeft ?? 0),
                $parent,
                $parentStyle,
            );
        }

        $frame = $this->lists->top();
        $ordinal = $numbering->ordinal ?? ($frame->numId === $numbering->numId ? $frame->next : ($level->start ?? 1));
        $item = $this->context->element('li', $frame->list);

        if ($tag === 'ol' && $marker !== null && $ordinal !== $frame->next && $frame->item !== null) {
            $item->setAttribute('value', (string) $ordinal);
        }

        if ($marker === null) {
            $label = $numbering->label ?? self::literalLabel($level, $ordinal);
            $item->setAttribute('style', CssFormatter::declarations([
                'list-style-type' => $label === '' ? 'none' : CssFormatter::string($label . ' '),
            ]));
        }

        $frame->advance($numbering->numId, $ordinal, $item, $this->context->resolver->resolve($item, $frame->style));

        return ['element' => $item, 'list' => $frame->list, 'style' => $frame->style, 'indent' => $frame->indent];
    }

    /**
     * @param  string|null  $marker  CSS `list-style-type`, or null when every item carries its own literal marker
     * @param  int  $indent  where the item's text starts, in twips from the page margin
     */
    private function openList(
        int $numId,
        int $level,
        string $tag,
        ?string $marker,
        int $start,
        int $indent,
        Element $parent,
        ComputedStyle $parentStyle,
    ): void {
        $enclosing = $this->lists->isEmpty() ? null : $this->lists->top();
        $host = $parent;
        $hostStyle = $parentStyle;

        if ($enclosing !== null) {
            if ($enclosing->item === null) {
                $item = $this->context->element('li', $enclosing->list);
                $enclosing->attach($item, $this->context->resolver->resolve($item, $enclosing->style));
            }

            $host = $enclosing->item ?? $enclosing->list;
            $hostStyle = $enclosing->itemStyle ?? $enclosing->style;
        }

        $padding = max(0, $indent - ($enclosing->indent ?? 0));
        $list = $this->context->element($tag, $host);

        if ($tag === 'ol' && $start !== 1) {
            $list->setAttribute('start', (string) $start);
        }

        // Word hangs the marker in the indent unless a space follows it, which sets it inside the first line.
        $inside = ($this->context->document->list($numId)->levels[$level] ?? null)?->suffix === 'space';

        $style = $this->context->style($list, $hostStyle, function (ComputedStyle $editor) use ($marker, $padding, $inside): array {
            $css = [];

            if ($marker !== null) {
                $css['list-style-type'] = $marker;
            }

            if (($editor->value('list-style-position') === 'inside') !== $inside) {
                $css['list-style-position'] = $inside ? 'inside' : 'outside';
            }

            // The items carry Word's spacing themselves; the list box adds none.
            return $css + ['margin' => '0', 'padding' => '0 0 0 ' . $this->context->css->twips($padding)];
        });

        $this->lists->open(new ListFrame($level, $tag, $marker, $numId, $start, $indent, $list, $style));
    }

    /** Footnotes and endnotes, gathered at the end of the document. */
    private function notes(Element $parent, ComputedStyle $parentStyle): void
    {
        foreach ([Note::FOOTNOTE => 'decimal', Note::ENDNOTE => 'lower-roman'] as $type => $marker) {
            $notes = array_values(array_filter(
                $this->context->document->notes,
                static fn(Note $note): bool => $note->type === $type,
            ));

            if ($notes === []) {
                continue;
            }

            $host = $parent;

            // Plain HTML marks its notes the way DPUB-ARIA does, and names the kind in a class.
            if ($this->context->plain) {
                $host = $this->context->element('section', $parent);
                $host->setAttribute('class', "{$type}s");
                $host->setAttribute('role', 'doc-endnotes');
            }

            $this->context->element('hr', $host)->setAttribute('style', 'width: 30%; margin-left: 0;');
            $list = $this->context->element('ol', $host);

            if (! $this->context->plain) {
                $list->setAttribute('class', "se-{$type}s");
            }

            $style = $this->context->style($list, $parentStyle, fn(ComputedStyle $editor): array => ['list-style-type' => $marker]
                + ($editor->value('list-style-position') === 'inside' ? ['list-style-position' => 'outside'] : [])
                + ['margin' => '0', 'padding' => '0 0 0 ' . $this->context->css->twips(360)]);
            $expected = 1;

            foreach ($notes as $note) {
                $item = $this->context->element('li', $list);
                $item->setAttribute('id', $this->context->id("{$type}-{$note->number}"));

                if ($this->context->plain) {
                    $item->setAttribute('role', 'doc-endnote');
                }

                if ($note->number !== $expected) {
                    $item->setAttribute('value', (string) $note->number);
                }

                $expected = $note->number + 1;
                $itemStyle = $this->context->resolver->resolve($item, $style);
                $this->writeBlocks($note->blocks, $item, $itemStyle, 'p', $this->context->document->pageLayout->contentWidthTwips());

                $backlink = $this->context->element('a', $item->lastElementChild ?? $item);
                $backlink->setAttribute('href', '#' . $this->context->id("{$type}-ref-{$note->number}"));

                if ($this->context->plain) {
                    $backlink->setAttribute('role', 'doc-backlink');
                }

                $backlink->append(" \u{21A9}");
            }
        }
    }

    /** Plain HTML has no pages and no margin notes: what only those carry is left out, and said so. */
    private function reportCuts(): void
    {
        if (! $this->context->plain) {
            return;
        }

        $document = $this->context->document;
        $headersFooters = count($document->headersFooters);
        $comments = count($document->comments);

        if ($headersFooters > 0) {
            $this->context->warn("{$headersFooters} page header(s) and footer(s) were left out: plain HTML has no pages");
        }

        if ($comments > 0) {
            $this->context->warn("{$comments} comment(s) were left out: plain HTML has no place for them");
        }
    }

    /**
     * The headers or the footers. SunEditor keeps a classed `div` only as a
     * line of text, so each line is a `div.se-header` (or `se-footer`) of its
     * own, and the lines of one header follow each other; a table or list
     * keeps a wrapping `div`.
     */
    private function headersFooters(string $kind, Element $parent, ComputedStyle $parentStyle): void
    {
        if ($this->context->plain) {
            return;
        }

        foreach ($this->context->document->headersFooters as $headerFooter) {
            if ($headerFooter->kind !== $kind) {
                continue;
            }

            $container = $this->context->element('div', $parent);
            $container->setAttribute('class', "se-{$kind}");

            if ($headerFooter->type !== HeaderFooter::DEFAULT) {
                $container->setAttribute('data-type', $headerFooter->type);
            }

            $style = $this->context->resolver->resolve($container, $parentStyle);
            $this->writeBlocks($headerFooter->blocks, $container, $style, 'div', $this->context->document->pageLayout->contentWidthTwips());

            $children = array_filter(iterator_to_array($container->childNodes), static fn(object $node): bool => $node instanceof Element);

            foreach ($children as $child) {
                $line = $child;

                if ($child->localName !== 'div') {
                    $line = $this->context->dom->createElement('div');
                    $line->append($child);
                }

                foreach ($container->attributes as $attribute) {
                    $line->setAttribute($attribute->name, $attribute->value);
                }

                $container->before($line);
            }

            $container->remove();
        }
    }

    /**
     * Comments, gathered after the notes. Who wrote one and when rides on
     * data attributes, so it survives the trip back without becoming text.
     */
    private function comments(Element $parent, ComputedStyle $parentStyle): void
    {
        $comments = $this->context->document->comments;

        if ($comments === [] || $this->context->plain) {
            return;
        }

        $list = $this->context->element('ol', $parent);
        $list->setAttribute('class', 'se-comments');
        $style = $this->context->resolver->resolve($list, $parentStyle);

        foreach ($comments as $comment) {
            $item = $this->context->element('li', $list);
            $item->setAttribute('id', $this->context->id("comment-{$comment->id}"));

            foreach (self::commentAttributes($comment) as $name => $value) {
                $item->setAttribute($name, $value);
            }

            $itemStyle = $this->context->resolver->resolve($item, $style);
            $this->writeBlocks($comment->blocks, $item, $itemStyle, 'p', $this->context->document->pageLayout->contentWidthTwips());
        }
    }

    /**
     * @return array<string, string>
     */
    private static function commentAttributes(Comment $comment): array
    {
        $attributes = ['data-comment' => (string) $comment->id];

        if ($comment->author !== null) {
            $attributes['data-author'] = $comment->author;
        }

        if ($comment->initials !== null) {
            $attributes['data-initials'] = $comment->initials;
        }

        if ($comment->date !== null) {
            $attributes['data-date'] = $comment->date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        }

        if ($comment->parentId !== null) {
            $attributes['data-parent'] = (string) $comment->parentId;
        }

        if ($comment->resolved) {
            $attributes['data-resolved'] = 'true';
        }

        return $attributes;
    }

    private function fullDocument(string $body): string
    {
        $options = $this->context->options;
        $metadata = $this->context->document->metadata;
        $language = $metadata->language ?? $options->language;
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $head = ['<meta charset="utf-8">'];
        $head[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';

        if ($metadata->title !== null) {
            $head[] = '<title>' . $escape($metadata->title) . '</title>';
        }

        if ($metadata->author !== null) {
            $head[] = '<meta name="author" content="' . $escape($metadata->author) . '">';
        }

        // Plain HTML needs no stylesheet: every block carries its own formatting.
        if (! $this->context->plain) {
            $environment = CssFormatter::declarations([
                'font-family' => CssFormatter::fontFamily($options->baseFontFamily()),
                'font-size' => $this->context->css->points($options->baseFontSizePt()),
                'color' => CssFormatter::color($options->baseTextColor()),
            ]);

            $stylesheet = trim($options->stylesheet($this->context->editor) . "\n" . $options->extraStylesheet) . "\nbody { {$environment} }";
            $head[] = "<style>\n" . str_replace('</style', '<\/style', $stylesheet) . "\n</style>";
        }

        return '<!DOCTYPE html>' . "\n"
            . '<html' . ($language === null ? '' : ' lang="' . $escape($language) . '"') . '>' . "\n"
            . '<head>' . "\n" . implode("\n", $head) . "\n" . '</head>' . "\n"
            . ($this->context->plain ? '<body>' : '<body class="sun-editor-editable">') . "\n" . $body . "\n" . '</body>' . "\n"
            . '</html>' . "\n";
    }

    /** Headings come from the outline level, which a named style may carry instead of the paragraph. */
    private function headingTag(ParagraphProperties $properties): ?string
    {
        $level = $properties->outlineLevel
            ?? $this->context->document->style($properties->styleId)?->paragraph->outlineLevel;

        return $level !== null && $level >= 0 && $level <= 5 ? 'h' . ($level + 1) : null;
    }

    /**
     * A page break inside a paragraph has no HTML equivalent, so the
     * paragraph is split and the remainder starts a new page.
     *
     * @param  list<Inline>  $children
     * @return non-empty-list<list<Inline>>
     */
    private static function splitOnPageBreaks(array $children): array
    {
        $segments = [[]];

        foreach ($children as $child) {
            if ($child instanceof BreakRun && $child->type === BreakRun::PAGE) {
                $segments[] = [];

                continue;
            }

            $segments[count($segments) - 1][] = $child;
        }

        return $segments;
    }

    /**
     * @param  list<Inline>  $children
     */
    private static function imageOnly(array $children): ?ImageRun
    {
        $image = null;

        foreach ($children as $child) {
            if ($child instanceof ImageRun) {
                if ($image !== null) {
                    return null;
                }

                $image = $child;

                continue;
            }

            if (! $child instanceof Bookmark) {
                return null;
            }
        }

        return $image;
    }

    /**
     * Whether the paragraph's text only reads correctly with `white-space`
     * preserved: HTML collapses runs of spaces and has no tab stops.
     *
     * @param  list<Inline>  $children
     */
    private static function preservesWhitespace(array $children): bool
    {
        $text = self::plainText($children);

        return $text !== '' && (str_contains($text, "\t") || str_contains($text, '  ')
            || str_starts_with($text, ' ') || str_ends_with($text, ' '));
    }

    /**
     * @param  list<Inline>  $children
     */
    private static function plainText(array $children): string
    {
        $text = '';

        foreach ($children as $child) {
            $text .= match (true) {
                $child instanceof TextRun => $child->text,
                $child instanceof TabRun => "\t",
                $child instanceof BreakRun => "\n",
                $child instanceof Hyperlink => self::plainText($child->children),
                self::isMarker($child) => '',
                // Anything else occupies a position between the spaces around it.
                default => "\u{FFFC}",
            };
        }

        return $text;
    }

    /**
     * The list element and CSS marker one numbering level needs.
     *
     * @return array{0: string, 1: ?string} tag and `list-style-type`; a null
     *                                      marker means the level's text is
     *                                      one CSS cannot generate, so every
     *                                      item spells its own marker out
     */
    private static function markerStyle(?ListLevel $level): array
    {
        if ($level === null) {
            return ['ul', 'disc'];
        }

        if ($level->text === '' || $level->format === 'bullet') {
            return ['ul', match ($level->text) {
                '' => 'none',
                "\u{2022}" => 'disc',
                "\u{25E6}", 'o' => 'circle',
                "\u{25AA}", "\u{25A0}" => 'square',
                default => null,
            }];
        }

        $keyword = match ($level->format) {
            'decimal' => 'decimal',
            'decimalZero' => 'decimal-leading-zero',
            'lowerLetter' => 'lower-alpha',
            'upperLetter' => 'upper-alpha',
            'lowerRoman' => 'lower-roman',
            'upperRoman' => 'upper-roman',
            default => null,
        };

        return ['ol', $keyword !== null && $level->text === '%' . ($level->level + 1) . '.' ? $keyword : null];
    }

    /** The marker of one item, for levels CSS has no counter style for. */
    private static function literalLabel(?ListLevel $level, int $ordinal): string
    {
        if ($level === null) {
            return (string) $ordinal;
        }

        if ($level->format === 'bullet' || $level->format === 'none') {
            return $level->text;
        }

        return (string) preg_replace_callback(
            '/%([1-9])/',
            static fn(array $match): string => (int) $match[1] - 1 === $level->level
                ? NumberFormat::format($ordinal, $level->format)
                : '',
            $level->text,
        );
    }
}
