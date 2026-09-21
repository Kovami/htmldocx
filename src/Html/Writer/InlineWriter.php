<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Writer;

use Dom\Element;
use Kovami\HtmlDocx\Css\ComputedStyle;
use Kovami\HtmlDocx\Css\FontMetrics;
use Kovami\HtmlDocx\Css\Length;
use Kovami\HtmlDocx\Docx\Reader\NumberFormat;
use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\Model\Bookmark;
use Kovami\HtmlDocx\Model\BreakRun;
use Kovami\HtmlDocx\Model\CommentEnd;
use Kovami\HtmlDocx\Model\CommentStart;
use Kovami\HtmlDocx\Model\Field;
use Kovami\HtmlDocx\Model\Formula;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\Note;
use Kovami\HtmlDocx\Model\NoteReference;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\TabRun;
use Kovami\HtmlDocx\Model\TextRun;

/**
 * Writes paragraph content. Neighbouring runs with the same formatting share
 * one wrapper, and formatting is expressed the way SunEditor produces it:
 * `strong`, `em`, `u`, `del`, `sup`/`sub` tags inside a `span` carrying
 * font, size, colour and background.
 */
final readonly class InlineWriter
{
    public const string COMMENT_CLASS = 'se-comment';

    public const string FIELD_CLASS = 'se-field';

    public function __construct(private WriterContext $context) {}

    /**
     * @param  list<Inline>  $inlines
     * @param  string  $enclosingComments  comment ids the element around $parent already marks
     */
    public function write(array $inlines, Element $parent, ComputedStyle $parentStyle, string $enclosingComments = ''): void
    {
        /** @var array{key: string, element: Element}|null $open */
        $open = null;
        /** @var array{key: string, element: Element}|null $commented */
        $commented = null;

        foreach ($inlines as $inline) {
            if ($inline instanceof CommentStart || $inline instanceof CommentEnd) {
                if (! $this->context->plain) {
                    $this->commentBoundary($inline, $parent);
                }

                $open = null;
                $commented = null;

                continue;
            }

            // Text a comment is about sits in a span naming every open comment.
            $key = $this->context->comments->key();
            $target = $parent;

            if ($key !== '' && $key !== $enclosingComments) {
                if ($commented === null || $commented['key'] !== $key) {
                    $span = $this->context->element('span', $parent);
                    $span->setAttribute('class', self::COMMENT_CLASS);
                    $span->setAttribute('data-comment', $key);
                    $commented = ['key' => $key, 'element' => $span];
                    $open = null;
                }

                $target = $commented['element'];
            }

            if (! $inline instanceof Bookmark) {
                $this->context->comments->cover();
            }

            if ($inline instanceof TextRun || $inline instanceof TabRun || $inline instanceof BreakRun || $inline instanceof Field) {
                $spec = $this->spec($inline->properties, $parentStyle);
                $specKey = serialize($spec);

                if ($open === null || $open['key'] !== $specKey) {
                    $open = ['key' => $specKey, 'element' => $this->wrappers($spec, $target, $parentStyle)];
                }

                match (true) {
                    $inline instanceof BreakRun => $this->inheritLineHeight($this->context->element('br', $open['element']), $parentStyle),
                    $inline instanceof Field => $this->field($inline, $open['element']),
                    default => $open['element']->append($inline instanceof TabRun ? "\t" : $inline->text),
                };

                continue;
            }

            $open = null;

            match (true) {
                $inline instanceof Hyperlink => $this->hyperlink($inline, $target, $parentStyle, $key),
                $inline instanceof ImageRun => $this->image($inline, $target),
                $inline instanceof NoteReference => $this->noteReference($inline, $target),
                $inline instanceof Formula => $this->formula($inline, $target),
                $inline instanceof Bookmark => $this->context->element('a', $target)->setAttribute('id', $this->context->bookmarkId($inline->name)),
                default => null,
            };
        }
    }

    /** A comment anchored to no text still needs a place: an empty span. */
    private function commentBoundary(CommentStart|CommentEnd $boundary, Element $parent): void
    {
        if ($boundary instanceof CommentStart) {
            $this->context->comments->start($boundary->id);

            return;
        }

        if (! $this->context->comments->end($boundary->id)) {
            $span = $this->context->element('span', $parent);
            $span->setAttribute('class', self::COMMENT_CLASS);
            $span->setAttribute('data-comment', (string) $boundary->id);
        }
    }

    private function field(Field $field, Element $parent): void
    {
        // Plain HTML has no pages to count: the value Word last showed stays, as text.
        if ($this->context->plain) {
            $this->context->warnOnce("The {$field->name} field became the text \"{$field->result}\": plain HTML has no pages");
            $parent->append($field->result);

            return;
        }

        $span = $this->context->element('span', $parent);
        $span->setAttribute('class', self::FIELD_CLASS);
        $span->setAttribute('data-field', $field->name);
        $span->append($field->result);
    }

    /**
     * The element an image component or an inline image is drawn with.
     */
    public function imageElement(ImageRun $image, Element $parent): ?Element
    {
        $source = $this->context->images->source($image->image, $image->description);

        if ($source === null) {
            return null;
        }

        [$width, $height] = self::pixelSize($image);
        $element = $this->context->element('img', $parent);
        $element->setAttribute('src', $source);
        $element->setAttribute('alt', $image->description);
        $element->setAttribute('style', "width: {$width}px; height: {$height}px;");

        // Editors that drop a picture's style keep these.
        if ($this->context->plain) {
            $element->setAttribute('width', (string) (int) round((float) $width));
            $element->setAttribute('height', (string) (int) round((float) $height));
        }

        return $element;
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function pixelSize(ImageRun $image): array
    {
        return [
            CssFormatter::number($image->width / Length::EMU_PER_PIXEL),
            CssFormatter::number($image->height / Length::EMU_PER_PIXEL),
        ];
    }

    /**
     * Wrappers and declarations that express $run on top of $parent.
     *
     * @return array{tags: list<string>, css: array<string, string>}
     */
    private function spec(RunProperties $run, ComputedStyle $parent): array
    {
        $css = [];
        $tags = [];

        if ($run->fontFamily !== null && strcasecmp($run->fontFamily, $parent->fontFamily) !== 0) {
            $css['font-family'] = CssFormatter::fontStack($run->fontFamily);
        }

        if ($run->size !== null && abs($run->size / 2 - $parent->fontSizePt) > 0.01) {
            $css['font-size'] = $this->context->css->points($run->size / 2);
        }

        if ($run->color !== null && strcasecmp($run->color, $parent->color) !== 0) {
            $css['color'] = CssFormatter::color($run->color);
        }

        if ($run->shading !== null && strcasecmp($run->shading, (string) $parent->inlineBackground) !== 0) {
            $css['background-color'] = CssFormatter::color($run->shading);
        }

        if (($run->spacing ?? 0) !== 0 && abs($run->spacing / Length::TWIPS_PER_POINT - $parent->letterSpacingPt) > 0.01) {
            $css['letter-spacing'] = $this->context->css->twips($run->spacing ?? 0);
        }

        if (($run->caps ?? false) !== ($parent->textTransform === 'uppercase')) {
            $css['text-transform'] = ($run->caps ?? false) ? 'uppercase' : 'none';
        }

        if (($run->smallCaps ?? false) !== $parent->smallCaps) {
            $css['font-variant'] = ($run->smallCaps ?? false) ? 'small-caps' : 'normal';
        }

        if (($run->bold ?? false) !== $parent->bold) {
            ($run->bold ?? false) ? $tags[] = 'strong' : $css['font-weight'] = 'normal';
        }

        if (($run->italic ?? false) !== $parent->italic) {
            ($run->italic ?? false) ? $tags[] = 'em' : $css['font-style'] = 'normal';
        }

        $underline = $run->underline ?? 'none';

        if ($underline !== 'none' && $parent->underline === null) {
            $tags[] = 'u';

            $style = match ($underline) {
                'double' => 'double',
                'dotted', 'dottedHeavy' => 'dotted',
                'dash', 'dashLong', 'dashedHeavy', 'dashLongHeavy', 'dotDash', 'dotDotDash' => 'dashed',
                'wave', 'wavyHeavy', 'wavyDouble' => 'wavy',
                default => null,
            };

            if ($style !== null) {
                $css['text-decoration-style'] = $style;
            }
        }

        if (($run->strike ?? false) && ! $parent->strike) {
            $tags[] = 'del';
        }

        $position = match ($run->verticalAlign) {
            'superscript' => 'super',
            'subscript' => 'sub',
            default => 'baseline',
        };

        if ($position !== $parent->verticalPosition) {
            if ($position === 'baseline') {
                $css['vertical-align'] = 'baseline';
            } else {
                $tags[] = $position === 'super' ? 'sup' : 'sub';
            }
        }

        return ['tags' => $tags, 'css' => $css];
    }

    /**
     * A stylesheet that sets a line height on every element (SunEditor 3's
     * does) would give a run — or a line break — its own, and a taller one
     * grows the line: the run takes its paragraph's instead, as it does in Word.
     */
    public function inheritLineHeight(Element $element, ComputedStyle $paragraph): void
    {
        if (in_array($element->localName, ['sup', 'sub'], true)) {
            return;
        }

        $style = $this->context->resolver->resolve($element, $paragraph);

        // A run in another font whose box would stick out of the line Word keeps holds no line of its own.
        if (FontMetrics::outgrowsLine($style, $paragraph)) {
            $element->setAttribute('style', trim($element->getAttribute('style') . ' line-height: 0;'));
        } elseif ($style->lineHeight != $paragraph->lineHeight) {
            $element->setAttribute('style', trim($element->getAttribute('style') . ' line-height: inherit;'));
        }
    }

    /**
     * @param  array{tags: list<string>, css: array<string, string>}  $spec
     */
    private function wrappers(array $spec, Element $parent, ComputedStyle $parentStyle): Element
    {
        $current = $parent;
        $css = $spec['css'];
        // The line's style belongs on the element that draws it: it is not inherited.
        $decoration = ['text-decoration-style' => $css['text-decoration-style'] ?? null];
        unset($css['text-decoration-style']);

        if ($css !== []) {
            $span = $this->context->element('span', $current);
            $span->setAttribute('style', CssFormatter::declarations($css));
            $current = $span;
            $this->inheritLineHeight($current, $parentStyle);
        }

        foreach ($spec['tags'] as $tag) {
            $current = $this->context->element($tag, $current);
            $this->inheritLineHeight($current, $parentStyle);

            // Word draws them at about two thirds of the size, without
            // making the line taller; a browser's are larger and push it.
            if ($tag === 'sup' || $tag === 'sub') {
                $current->setAttribute('style', 'font-size: 65%; line-height: 0;');
            }

            if ($tag === 'u' && $decoration['text-decoration-style'] !== null) {
                $current->setAttribute('style', CssFormatter::declarations(array_filter($decoration)));
            }
        }

        return $current;
    }

    private function hyperlink(Hyperlink $hyperlink, Element $parent, ComputedStyle $parentStyle, string $comments): void
    {
        $href = $hyperlink->anchor !== null ? '#' . $this->context->bookmarkId($hyperlink->anchor) : self::safeUrl((string) $hyperlink->url);

        if ($href === null) {
            $this->write($hyperlink->children, $parent, $parentStyle, $comments);

            return;
        }

        $link = $this->context->element('a', $parent);
        $link->setAttribute('href', $href);

        $this->write($hyperlink->children, $link, $this->context->resolver->resolve($link, $parentStyle), $comments);
    }

    private function image(ImageRun $image, Element $parent): void
    {
        $element = $this->imageElement($image, $parent);

        if ($element !== null && $image->float !== null) {
            $element->setAttribute('style', $element->getAttribute('style') . ' float: ' . $image->float . ';');
        }
    }

    private function noteReference(NoteReference $reference, Element $parent): void
    {
        $sup = $this->context->element('sup', $parent);
        // Word's note mark does not make its line taller; a browser's does.
        $sup->setAttribute('style', 'line-height: 0;');
        $link = $this->context->element('a', $sup);
        $link->setAttribute('href', '#' . $this->context->id("{$reference->type}-{$reference->number}"));
        $link->setAttribute('id', $this->context->id("{$reference->type}-ref-{$reference->number}"));

        if ($this->context->plain) {
            $link->setAttribute('role', 'doc-noteref');
        }
        $link->append(self::noteLabel($reference->type, $reference->number));
    }

    /**
     * A formula in the shape its editor's math plugin takes: MathML for plain
     * HTML, the LaTeX in `\(…\)` for CKEditor and TinyMCE (MathJax's and
     * ckeditor5-math's shape), TipTap's inline-math node, SunEditor's KaTeX
     * span. Where no plugin reads it, the LaTeX stays as text.
     */
    private function formula(Formula $formula, Element $parent): void
    {
        if ($this->context->editor === null) {
            (new MathMlWriter($this->context->dom))->write($formula->latex, $parent, $formula->display);

            return;
        }

        if ($this->context->editor !== Editor::SunEditor) {
            $span = $this->context->element('span', $parent);

            if ($this->context->editor === Editor::TipTap) {
                $span->setAttribute('data-type', 'inline-math');
                $span->setAttribute('data-latex', $formula->latex);
            } else {
                $span->setAttribute('class', 'math-tex');
            }

            $span->append($formula->display ? '\\[' . $formula->latex . '\\]' : '\\(' . $formula->latex . '\\)');

            return;
        }

        // SunEditor 3's math component; its KaTeX draws the formula when the editor loads it.
        $span = $this->context->element('span', $parent);
        $span->setAttribute('class', 'se-component se-inline-component se-disable-pointer se-math katex');
        $span->setAttribute('contenteditable', 'false');
        $span->setAttribute('data-se-value', $formula->latex);
        $span->append($formula->latex);
    }

    public static function noteLabel(string $type, int $number): string
    {
        return $type === Note::ENDNOTE ? NumberFormat::format($number, 'lowerRoman') : (string) $number;
    }

    /** The URL, unless its scheme can run script or reach the local file system. */
    public static function safeUrl(string $url): ?string
    {
        $cleaned = strtolower((string) preg_replace('/[\x00-\x20\x7F]+/', '', $url));

        if ($cleaned === '' || preg_match('/^(javascript|vbscript|data|file):/', $cleaned) === 1) {
            return null;
        }

        return trim($url);
    }
}
