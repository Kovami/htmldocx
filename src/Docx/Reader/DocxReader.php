<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Closure;
use DateTimeImmutable;
use Dom\Element;
use Dom\XMLDocument;
use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Docx\Reader\Format\FormatParser;
use Kovami\HtmlDocx\Docx\Reader\Format\RunFormat;
use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\Image\ImageInspector;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Bookmark;
use Kovami\HtmlDocx\Model\Comment;
use Kovami\HtmlDocx\Model\CommentRanges;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\DocumentMetadata;
use Kovami\HtmlDocx\Model\HeaderFooter;
use Kovami\HtmlDocx\Model\Note;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Package\ZipReader;
use Throwable;

/** Reads a WordprocessingML package (.docx, .docm, .dotx) into the document model. */
final readonly class DocxReader
{
    /**
     * @param  Closure(string): void|null  $warn  receives a message for every piece of content that could not be converted
     */
    public function __construct(
        private bool $includeHiddenText = false,
        private int $maxEntryBytes = 128 * 1024 * 1024,
        private int $maxTotalBytes = 512 * 1024 * 1024,
        private ?Closure $warn = null,
        private bool $includeHeadersFooters = true,
        private bool $includeComments = true,
    ) {}

    public function read(string $bytes): Document
    {
        $package = new OpcPackage(ZipReader::fromString($bytes, $this->maxEntryBytes, $this->maxTotalBytes));
        $mainPart = $package->mainDocumentPart();
        $document = $package->xml($mainPart);
        $body = Xml::child($document->documentElement, 'body');

        if (! Xml::is($document->documentElement, 'document') || $body === null) {
            throw HtmlDocxException::malformedDocx('the main part is not a WordprocessingML document');
        }

        $theme = $this->relatedXml($package, $mainPart, 'theme');
        $parser = new FormatParser($theme === null ? new Theme : Theme::fromXml($theme));
        $styles = new StyleSheet($parser, $this->relatedXml($package, $mainPart, 'styles'));
        $numbering = new Numbering($parser, $styles, $this->relatedXml($package, $mainPart, 'numbering'));
        $pageLayout = self::pageLayout(Xml::child($body, 'sectPr'));

        $noteParts = [];
        $noteElements = [];
        $bookmarkSources = [$document];

        foreach ([Note::FOOTNOTE => 'footnotes', Note::ENDNOTE => 'endnotes'] as $type => $relationshipType) {
            $relationship = $package->relationshipByType($mainPart, $relationshipType);

            if ($relationship === null || $relationship->external || ! $package->has($relationship->target)) {
                continue;
            }

            $notes = $package->xml($relationship->target);
            $noteParts[$type] = $relationship->target;
            $bookmarkSources[] = $notes;

            foreach (Xml::children($notes->documentElement, $type) as $note) {
                if (! in_array(Xml::attr($note, 'type'), ['separator', 'continuationSeparator', 'continuationNotice'], true)) {
                    $noteElements[$type][(string) Xml::attr($note, 'id')] = $note;
                }
            }
        }

        [$commentsPart, $commentElements, $rangedComments] = $this->commentElements($package, $mainPart, $bookmarkSources);
        $commentIds = [];

        foreach (array_keys($commentElements) as $index => $wordId) {
            $commentIds[(string) $wordId] = $index + 1;
        }

        $context = new ReaderContext(
            package: $package,
            parser: $parser,
            styles: $styles,
            numbering: $numbering,
            images: new ImageInspector,
            contentWidth: $pageLayout->contentWidthTwips(),
            includeHiddenText: $this->includeHiddenText,
            linkedBookmarks: self::linkedBookmarks($bookmarkSources),
            noteElements: $noteElements,
            noteParts: $noteParts,
            warn: $this->warn ?? static function (string $message): void {},
            commentIds: $commentIds,
            rangedComments: $rangedComments,
        );

        $blocks = (new BodyReader($context, $mainPart))->blocks($body);
        $headersFooters = $this->includeHeadersFooters ? $this->headersFooters($package, $mainPart, $body, $context) : [];
        $comments = $this->comments($package, $mainPart, $commentsPart, $commentElements, $context);
        $comments = CommentRanges::balance([$blocks, ...array_map(static fn (Note $note): array => $note->blocks, $context->notes)], $comments);
        $normalId = $styles->defaultStyleId('paragraph');
        $normal = $styles->defaultParagraph->over($styles->paragraphStyle($normalId));

        return new Document(
            blocks: $blocks,
            defaultRunProperties: self::withFont(RunFormat::resolve($styles->defaultRun, [$styles->runStyle($normalId, 'paragraph')], new RunFormat)->toProperties()),
            styles: [],
            lists: $numbering->definitions(),
            pageLayout: $pageLayout,
            metadata: $this->metadata($package),
            notes: $context->notes,
            defaultParagraphProperties: new ParagraphProperties(
                styleId: $normalId,
                alignment: $normal->alignment,
                indentLeft: $normal->indentLeft ?? 0,
                indentRight: $normal->indentRight ?? 0,
                firstLine: $normal->firstLine ?? 0,
                spacingBefore: $normal->spacingBefore ?? 0,
                spacingAfter: $normal->spacingAfter ?? 0,
                lineSpacing: $normal->lineSpacing,
                lineRule: $normal->lineSpacing === null ? null : ($normal->lineRule ?? 'auto'),
            ),
            headersFooters: $headersFooters,
            comments: $comments,
        );
    }

    /**
     * The headers and footers of the last section, the one whose page layout
     * the document takes. A section without a reference of some type shows
     * the previous section's, so references are followed through them all.
     *
     * @return list<HeaderFooter>
     */
    private function headersFooters(OpcPackage $package, string $mainPart, Element $body, ReaderContext $context): array
    {
        $targets = [];
        $lastSection = null;

        foreach (Xml::descendants($body, 'sectPr') as $section) {
            if (Xml::is($section->parentElement, 'sectPrChange')) {
                continue;
            }

            foreach ([HeaderFooter::HEADER, HeaderFooter::FOOTER] as $kind) {
                foreach (Xml::children($section, "{$kind}Reference") as $reference) {
                    $relationship = $package->relationship($mainPart, (string) Xml::attr($reference, 'id', Namespaces::R));

                    if ($relationship !== null && ! $relationship->external && $package->has($relationship->target)) {
                        $targets[$kind][Xml::attr($reference, 'type') ?? HeaderFooter::DEFAULT] = $relationship->target;
                    }
                }
            }

            $lastSection = $section;
        }

        // Word only shows the first-page and even-page variants when asked to.
        $shown = [
            HeaderFooter::DEFAULT => true,
            HeaderFooter::FIRST => Xml::onOff($lastSection, 'titlePg') ?? false,
            HeaderFooter::EVEN => Xml::onOff($this->relatedXml($package, $mainPart, 'settings')?->documentElement, 'evenAndOddHeaders') ?? false,
        ];

        $result = [];

        foreach ($targets as $kind => $byType) {
            foreach ($shown as $type => $isShown) {
                $target = $byType[$type] ?? null;

                if (! $isShown || $target === null) {
                    continue;
                }

                try {
                    $root = $package->xml($target)->documentElement;
                } catch (HtmlDocxException $exception) {
                    $context->warn("{$target} was ignored: {$exception->getMessage()}");

                    continue;
                }

                $result[] = new HeaderFooter($kind, $type, (new BodyReader($context, $target))->blocks($root));
            }
        }

        return self::withoutBlankVariants($result);
    }

    /**
     * Word writes a blank part for every variant it has nothing for, and a
     * blank one shows exactly what a missing one would. One only matters when
     * it alone turns the first-page or even-page layout on.
     *
     * @param  list<HeaderFooter>  $headersFooters  headers first
     * @return list<HeaderFooter>
     */
    private static function withoutBlankVariants(array $headersFooters): array
    {
        $typesInUse = [];

        foreach ($headersFooters as $headerFooter) {
            if (! self::isBlank($headerFooter->blocks)) {
                $typesInUse[$headerFooter->type] = true;
            }
        }

        $kept = [];

        foreach ($headersFooters as $headerFooter) {
            if (self::isBlank($headerFooter->blocks)) {
                if ($headerFooter->type === HeaderFooter::DEFAULT || isset($typesInUse[$headerFooter->type])) {
                    continue;
                }

                $typesInUse[$headerFooter->type] = true;
            }

            $kept[] = $headerFooter;
        }

        return $kept;
    }

    /**
     * The comments part and its comments, keyed by their w:id, in the order
     * their anchors appear — Word reorders the part itself when it saves —
     * and the ids of the comments anchored to a range rather than a point.
     *
     * @param  list<XMLDocument>  $sources  the parts comments may be anchored in
     * @return array{0: ?string, 1: array<string, Element>, 2: array<string, true>}
     */
    private function commentElements(OpcPackage $package, string $mainPart, array $sources): array
    {
        $relationship = $package->relationshipByType($mainPart, 'comments');

        if (! $this->includeComments || $relationship === null || $relationship->external || ! $package->has($relationship->target)) {
            return [null, [], []];
        }

        $positions = [];
        $ranged = [];

        foreach ($sources as $source) {
            foreach ($source->documentElement?->getElementsByTagNameNS(Namespaces::W, '*') ?? [] as $element) {
                if ($element->localName === 'commentRangeStart' || $element->localName === 'commentReference') {
                    $id = (string) Xml::attr($element, 'id');
                    $positions[$id] ??= count($positions);

                    if ($element->localName === 'commentRangeStart') {
                        $ranged[$id] = true;
                    }
                }
            }
        }

        $elements = [];

        foreach (Xml::children($package->xml($relationship->target)->documentElement, 'comment') as $comment) {
            $elements[(string) Xml::attr($comment, 'id')] = $comment;
        }

        // Comments anchored nowhere keep their place in the part, after the rest.
        $order = array_flip(array_map('strval', array_keys($elements)));
        uksort($elements, static fn (int|string $a, int|string $b): int => [$positions[(string) $a] ?? PHP_INT_MAX, $order[(string) $a]]
            <=> [$positions[(string) $b] ?? PHP_INT_MAX, $order[(string) $b]]);

        return [$relationship->target, $elements, $ranged];
    }

    /**
     * Comments get ids 1, 2, … in the order of their anchors;
     * replies and the "done" state come from Word 2013's commentsExtended part,
     * which refers to a comment by the w14:paraId of its last paragraph.
     *
     * @param  array<string, Element>  $elements
     * @return list<Comment>
     */
    private function comments(OpcPackage $package, string $mainPart, ?string $part, array $elements, ReaderContext $context): array
    {
        if ($part === null) {
            return [];
        }

        $extended = [];

        foreach (Xml::descendants($this->relatedXml($package, $mainPart, 'commentsExtended')?->documentElement, 'commentEx', Namespaces::W15) as $entry) {
            $extended[(string) Xml::attr($entry, 'paraId', Namespaces::W15)] = [
                'parent' => Xml::attr($entry, 'paraIdParent', Namespaces::W15),
                'done' => in_array(Xml::attr($entry, 'done', Namespaces::W15), ['1', 'true', 'on'], true),
            ];
        }

        $idByParagraph = [];
        $lastParagraphs = [];
        $id = 0;

        foreach ($elements as $element) {
            $id++;
            $paragraphs = Xml::descendants($element, 'p');
            $paraId = $paragraphs === [] ? null : Xml::attr($paragraphs[count($paragraphs) - 1], 'paraId', Namespaces::W14);
            $lastParagraphs[$id] = $paraId;

            if ($paraId !== null) {
                $idByParagraph[$paraId] = $id;
            }
        }

        $comments = [];
        $id = 0;

        foreach ($elements as $element) {
            $id++;
            $thread = $extended[(string) $lastParagraphs[$id]] ?? null;
            $author = Xml::attr($element, 'author');
            $initials = Xml::attr($element, 'initials');

            $comments[] = new Comment(
                id: $id,
                blocks: (new BodyReader($context, $part))->blocks($element),
                author: $author === null || $author === '' ? null : $author,
                initials: $initials === null || $initials === '' ? null : $initials,
                date: self::date(Xml::attr($element, 'date')),
                parentId: $thread === null || $thread['parent'] === null ? null : ($idByParagraph[$thread['parent']] ?? null),
                resolved: $thread['done'] ?? false,
            );
        }

        return $comments;
    }

    /**
     * @param  list<Block>  $blocks
     */
    private static function isBlank(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (! $block instanceof Paragraph) {
                return false;
            }

            foreach ($block->children as $child) {
                if (! $child instanceof Bookmark) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function relatedXml(OpcPackage $package, string $source, string $type): ?XMLDocument
    {
        $relationship = $package->relationshipByType($source, $type);

        if ($relationship === null || $relationship->external || ! $package->has($relationship->target)) {
            return null;
        }

        try {
            return $package->xml($relationship->target);
        } catch (HtmlDocxException $exception) {
            if ($this->warn !== null) {
                ($this->warn)("{$relationship->target} was ignored: {$exception->getMessage()}");
            }

            return null;
        }
    }

    private function metadata(OpcPackage $package): DocumentMetadata
    {
        $relationship = $package->relationshipByType('/', 'core-properties');
        $title = null;
        $author = null;
        $language = null;
        $created = null;

        if ($relationship !== null && ! $relationship->external && $package->has($relationship->target)) {
            try {
                $core = $package->xml($relationship->target)->documentElement;
                $title = self::text(Xml::child($core, 'title', Namespaces::DC));
                $author = self::text(Xml::child($core, 'creator', Namespaces::DC));
                $language = self::text(Xml::child($core, 'language', Namespaces::DC));
                $created = self::text(Xml::child($core, 'created', Namespaces::DCTERMS));
            } catch (HtmlDocxException) {
            }
        }

        try {
            $createdAt = $created === null ? new DateTimeImmutable('@0') : new DateTimeImmutable($created);
        } catch (Throwable) {
            $createdAt = new DateTimeImmutable('@0');
        }

        return new DocumentMetadata($createdAt, $title, $author, $language);
    }

    private static function pageLayout(?Element $section): PageLayout
    {
        $size = Xml::child($section, 'pgSz');
        $margins = Xml::child($section, 'pgMar');
        $default = PageLayout::letterPortrait();

        $width = Xml::twips(Xml::attr($size, 'w')) ?? $default->widthTwips;
        $height = Xml::twips(Xml::attr($size, 'h')) ?? $default->heightTwips;
        $left = abs(Xml::twips(Xml::attr($margins, 'left')) ?? $default->marginLeftTwips);
        $right = abs(Xml::twips(Xml::attr($margins, 'right')) ?? $default->marginRightTwips);

        if ($width <= $left + $right + 360 || $height <= 720) {
            return $default;
        }

        return PageLayout::fromTwips(
            $width,
            $height,
            max(0, min($height - 360, abs(Xml::twips(Xml::attr($margins, 'top')) ?? $default->marginTopTwips))),
            $right,
            max(0, min($height - 360, abs(Xml::twips(Xml::attr($margins, 'bottom')) ?? $default->marginBottomTwips))),
            $left,
        );
    }

    /**
     * Bookmarks worth keeping: those a hyperlink or a cross-reference field targets.
     *
     * @param  list<XMLDocument>  $documents
     * @return array<string, true>
     */
    private static function linkedBookmarks(array $documents): array
    {
        $names = [];

        foreach ($documents as $document) {
            foreach (Xml::descendants($document->documentElement, 'hyperlink') as $hyperlink) {
                $anchor = Xml::attr($hyperlink, 'anchor');

                if ($anchor !== null && $anchor !== '') {
                    $names[$anchor] = true;
                }
            }

            $instructions = array_map(
                static fn (Element $paragraph): string => implode('', array_map(static fn (Element $text): string => $text->textContent, Xml::descendants($paragraph, 'instrText'))),
                Xml::descendants($document->documentElement, 'p'),
            );

            foreach (Xml::descendants($document->documentElement, 'fldSimple') as $field) {
                $instructions[] = (string) Xml::attr($field, 'instr');
            }

            foreach ($instructions as $instruction) {
                if (preg_match_all('/\\\\l\s+"([^"]+)"|\b(?:REF|PAGEREF|NOTEREF)\s+(\S+)/i', $instruction, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) > 0) {
                    foreach ($matches as $match) {
                        $names[(string) ($match[1] ?? $match[2])] = true;
                    }
                }
            }
        }

        return $names;
    }

    private static function withFont(RunProperties $properties): RunProperties
    {
        return $properties->fontFamily === null
            ? new RunProperties(...[...get_object_vars($properties), 'fontFamily' => 'Times New Roman'])
            : $properties;
    }

    private static function text(?Element $element): ?string
    {
        $text = trim((string) $element?->textContent);

        return $text === '' ? null : $text;
    }
}
