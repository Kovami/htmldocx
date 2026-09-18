<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Closure;
use DateTimeZone;
use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Docx\Reader\NumberFormat;
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
 * Word's vertical spacing is additive and CSS margins collapse, so a
 * paragraph's top margin carries its own spacing plus the previous
 * paragraph's: `max(prevBottom, top)` then equals Word's `after + before`.
 *
 * One instance converts one document.
 */
final class HtmlWriter
{
    /** Lists open around the block currently being written, outermost first. */
    private ListStack $lists;

    private readonly WriterContext $context;

    private readonly InlineWriter $inlines;

    private readonly TableWriter $tables;

    /**
     * @param  Closure(string): void|null  $warn  receives a message for every piece of content that could not be converted
     */
    public function __construct(
        Document $document,
        Options $options = new Options(),
        ?ImageHandler $images = null,
        ?Closure $warn = null,
    ) {
        $this->context = new WriterContext(
            $document,
            $options,
            $images ?? new DataUriImageHandler(),
            $warn ?? static function (string $message): void {},
        );
        $this->inlines = new InlineWriter($this->context);
        $this->tables = new TableWriter($this->context, $this->writeBlocks(...));
        $this->lists = new ListStack();
    }

    public function toHtml(): string
    {
        $document = $this->context->document;
        $options = $this->context->options;

        $root = ComputedStyle::root($options->fontFamily, $options->fontSizePt, strtoupper(ltrim($options->textColor, '#')));
        $body = $this->context->dom->createElement('body');
        $body->setAttribute('class', 'sun-editor-editable');
        $this->context->dom->append($body);

        $style = $this->context->resolver->resolve($body, $root);

        $this->headersFooters(HeaderFooter::HEADER, $body, $style);
        $this->writeBlocks($document->blocks, $body, $style, 'p', $document->pageLayout->contentWidthTwips());
        $this->notes($body, $style);
        $this->comments($body, $style);
        $this->headersFooters(HeaderFooter::FOOTER, $body, $style);

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
        $previousAfter = 0;

        foreach ($blocks as $block) {
            if ($block instanceof Table) {
                $this->lists->clear();
                // A table has no spacing of its own in Word: the model moved it
                // onto the neighbouring paragraphs, which carry it in CSS too.
                $this->tables->write($block, $parent, $parentStyle, $availableWidth, $block->marginTop, $block->marginBottom);
                $previousAfter = 0;

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
                    $this->paragraph($block, $frame->item, $frame->itemStyle, $paragraphTag, $previousAfter, $frame->indent);
                } else {
                    $this->lists->clear();
                    $this->paragraph($block, $parent, $parentStyle, $paragraphTag, $previousAfter, 0);
                }
            } else {
                $item = $this->listItem($numbering, $block->properties, $parent, $parentStyle);
                $this->paragraph($block, $item['list'], $item['style'], $paragraphTag, $previousAfter, $item['indent'], $item['element']);
            }

            $previousAfter = $block->properties->spacingAfter ?? 0;
        }

        $this->lists = $enclosing;
    }

    /**
     * @param  Element  $parent  where new elements go
     * @param  ComputedStyle  $parentStyle  the computed style of $parent
     * @param  int  $previousAfter  spacing the previous block leaves behind, in twips
     * @param  int  $indentBase  indentation the container already provides, in twips
     * @param  Element|null  $item  the `li` to fill, when the paragraph is a list item
     */
    private function paragraph(
        Paragraph $paragraph,
        Element $parent,
        ComputedStyle $parentStyle,
        string $tag,
        int $previousAfter,
        int $indentBase,
        ?Element $item = null,
    ): void {
        $properties = $paragraph->properties;
        $heading = $this->headingTag($properties);
        $segments = self::splitOnPageBreaks($paragraph->children);

        if ($item === null && count($segments) === 1) {
            $image = self::imageOnly($segments[0]);

            if ($image !== null) {
                $this->imageComponent($image, $properties, $segments[0], $parent, $parentStyle, $previousAfter);

                return;
            }
        }

        $itemStyle = $item === null ? null : $this->context->resolver->resolve($item, $parentStyle);
        $last = count($segments) - 1;

        foreach ($segments as $index => $children) {
            $fills = $item !== null && $index === 0 && $heading === null;

            if ($fills) {
                $element = $item;
                $baseline = $parentStyle;
            } else {
                $element = $this->context->element($heading ?? ($item === null ? $tag : 'div'), $item ?? $parent);
                $baseline = $item === null ? $parentStyle : $itemStyle;
            }

            $top = $index === 0 ? ($properties->spacingBefore ?? 0) + $previousAfter : 0;
            $bottom = $index === $last ? ($properties->spacingAfter ?? 0) : 0;
            $pageBreak = $index === 0 ? $properties->pageBreakBefore : true;
            $preserve = self::preservesWhitespace($children);
            // An empty paragraph is as tall as its paragraph mark, the only
            // formatting it has left.
            $mark = self::isEmpty($children) ? $properties->markRunProperties : null;

            $style = $this->context->style(
                $element,
                $baseline ?? $parentStyle,
                fn(ComputedStyle $editor): array => $this->paragraphCss($properties, $editor, $top, $bottom, $pageBreak, $preserve, $indentBase, $item !== null, $mark),
            );

            $this->inlines->write($children, $element, $style);
            $this->closeParagraph($children, $element);
        }
    }

    /**
     * An empty paragraph still needs a line, and a trailing line break is
     * dropped by HTML layout unless a second one follows it.
     *
     * @param  list<Inline>  $children
     */
    private function closeParagraph(array $children, Element $element): void
    {
        $visible = array_values(array_filter($children, static fn(Inline $child): bool => ! self::isMarker($child)));
        $last = $visible === [] ? null : $visible[count($visible) - 1];

        while ($last instanceof Hyperlink) {
            $last = $last->children === [] ? null : $last->children[count($last->children) - 1];
        }

        if ($visible === [] || $last instanceof BreakRun) {
            $this->context->element('br', $element);
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
        $defaults = $this->context->document->defaultParagraphProperties;
        $css = [];

        $alignment = match ($properties->alignment) {
            'both' => 'justify',
            'left', 'center', 'right' => $properties->alignment,
            default => null,
        };
        $inherited = $editor->textAlign;

        if ($alignment !== null && $alignment !== $inherited) {
            $css['text-align'] = $alignment;
        } elseif ($alignment === null && $inherited !== null && $inherited !== 'start') {
            $css['text-align'] = 'start';
        }

        $adoptsSpacing = $this->context->adoptsEditorDefault($properties->spacingBefore, $defaults->spacingBefore)
            && $this->context->adoptsEditorDefault($properties->spacingAfter, $defaults->spacingAfter)
            && $this->context->adoptsEditorDefault($properties->lineSpacing, $defaults->lineSpacing);

        // Word indents to the text edge; CSS margins sit outside the border
        // and the padding the border's spacing becomes.
        $frame = [];

        foreach (['left', 'right'] as $side) {
            $border = $properties->borders->{$side};
            $frame[$side] = $border === null ? 0 : Length::pointsToTwips($border->space + $border->size / 8);
        }

        $lengths = [
            'margin-left' => $properties->indentLeft - $indentBase - $frame['left'],
            'margin-right' => $properties->indentRight - $frame['right'],
        ];

        if (! $adoptsSpacing) {
            $lengths['margin-top'] = $top;
            $lengths['margin-bottom'] = $bottom;
        }

        if (! $isItem) {
            $lengths['text-indent'] = $properties->firstLine;
        }

        foreach ($lengths as $property => $twips) {
            $value = $this->context->css->length($twips, $property === 'text-indent' ? $editor->textIndentPt : $editor->lengthPt($property));

            if ($value !== null) {
                $css[$property] = $value;
            }
        }

        if ($properties->lineSpacing !== null && ! $adoptsSpacing) {
            $lineHeight = $this->lineHeight($properties->lineSpacing, $properties->lineRule ?? 'auto', $editor);

            if ($lineHeight !== null) {
                $css['line-height'] = $lineHeight;
            }
        }

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

        if ($preserve && ! $editor->preservesWhitespace()) {
            $css['white-space'] = 'pre-wrap';
        }

        if ($mark?->size !== null && abs($mark->size / 2 - $editor->fontSizePt) > 0.01
            && ! $this->context->adoptsEditorDefault($mark->size, $this->context->document->defaultRunProperties->size)) {
            $css['font-size'] = $this->context->css->points($mark->size / 2);
        }

        return $css;
    }

    /**
     * @param  string  $rule  ST_LineSpacingRule: auto, atLeast or exact
     */
    private function lineHeight(int $lineSpacing, string $rule, ComputedStyle $editor): ?string
    {
        if ($rule === 'auto') {
            $multiple = $lineSpacing / 240;
            $current = $editor->lineHeight?->multiple;

            return $current !== null && abs($current - $multiple) < 0.005 ? null : CssFormatter::number($multiple);
        }

        $value = $this->context->css->twips($lineSpacing);
        $current = $editor->lineHeight?->points;

        return $current !== null && $this->context->css->points($current) === $value ? null : $value;
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
        int $previousAfter,
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

        $this->context->style($container, $parentStyle, function (ComputedStyle $editor) use ($properties, $previousAfter): array {
            $css = [];
            $margins = ['margin-top' => ($properties->spacingBefore ?? 0) + $previousAfter, 'margin-bottom' => $properties->spacingAfter ?? 0];

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
        $figure->setAttribute('style', "margin: auto; width: {$width}px;");

        $element = $this->inlines->imageElement($image, $figure);

        if ($element === null) {
            $container->remove();

            return;
        }

        $element->setAttribute('data-proportion', 'true');
        $element->setAttribute('data-align', $float);
        $element->setAttribute('data-size', "{$width}px,{$height}px");

        if ($image->description !== '') {
            $element->setAttribute('data-file-name', $image->description);
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

        $style = $this->context->style($list, $hostStyle, function (ComputedStyle $editor) use ($marker, $padding): array {
            $css = [];

            if ($marker !== null && $marker !== $editor->listStyleType) {
                $css['list-style-type'] = $marker;
            }

            // The items carry Word's spacing themselves; the list box adds none.
            foreach (['margin-top' => 0, 'margin-bottom' => 0, 'padding-left' => $padding] as $property => $twips) {
                $value = $this->context->css->length($twips, $editor->lengthPt($property));

                if ($value !== null) {
                    $css[$property] = $value;
                }
            }

            return $css;
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

            $this->context->element('hr', $parent)->setAttribute('style', 'width: 30%; margin-left: 0;');
            $list = $this->context->element('ol', $parent);
            $list->setAttribute('class', "se-{$type}s");
            $style = $this->context->style($list, $parentStyle, static fn(): array => ['list-style-type' => $marker]);
            $expected = 1;

            foreach ($notes as $note) {
                $item = $this->context->element('li', $list);
                $item->setAttribute('id', $this->context->id("{$type}-{$note->number}"));

                if ($note->number !== $expected) {
                    $item->setAttribute('value', (string) $note->number);
                }

                $expected = $note->number + 1;
                $itemStyle = $this->context->resolver->resolve($item, $style);
                $this->writeBlocks($note->blocks, $item, $itemStyle, 'p', $this->context->document->pageLayout->contentWidthTwips());

                $backlink = $this->context->element('a', $item->lastElementChild ?? $item);
                $backlink->setAttribute('href', '#' . $this->context->id("{$type}-ref-{$note->number}"));
                $backlink->append(" \u{21A9}");
            }
        }
    }

    /** The headers or the footers, each in a `div` of its own. */
    private function headersFooters(string $kind, Element $parent, ComputedStyle $parentStyle): void
    {
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
            $this->writeBlocks($headerFooter->blocks, $container, $style, 'p', $this->context->document->pageLayout->contentWidthTwips());
        }
    }

    /**
     * Comments, gathered after the notes. Who wrote one and when rides on
     * data attributes, so it survives the trip back without becoming text.
     */
    private function comments(Element $parent, ComputedStyle $parentStyle): void
    {
        $comments = $this->context->document->comments;

        if ($comments === []) {
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

        $environment = CssFormatter::declarations([
            'font-family' => CssFormatter::fontFamily($options->fontFamily),
            'font-size' => $this->context->css->points($options->fontSizePt),
            'color' => CssFormatter::color(ltrim($options->textColor, '#')),
        ]);

        $stylesheet = trim($options->defaultStylesheet . "\n" . $options->extraStylesheet) . "\nbody { {$environment} }";
        $head[] = "<style>\n" . str_replace('</style', '<\/style', $stylesheet) . "\n</style>";

        return '<!DOCTYPE html>' . "\n"
            . '<html' . ($language === null ? '' : ' lang="' . $escape($language) . '"') . '>' . "\n"
            . '<head>' . "\n" . implode("\n", $head) . "\n" . '</head>' . "\n"
            . '<body class="sun-editor-editable">' . "\n" . $body . "\n" . '</body>' . "\n"
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
