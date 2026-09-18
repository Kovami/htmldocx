<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Dom\Element;
use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Docx\Reader\Format\ParagraphFormat;
use Kovami\HtmlDocx\Docx\Reader\Format\RunFormat;
use Kovami\HtmlDocx\Docx\Reader\Format\TableStyleCondition;
use Kovami\HtmlDocx\Model\Block;
use Kovami\HtmlDocx\Model\Bookmark;
use Kovami\HtmlDocx\Model\Border;
use Kovami\HtmlDocx\Model\BorderSet;
use Kovami\HtmlDocx\Model\BreakRun;
use Kovami\HtmlDocx\Model\CommentEnd;
use Kovami\HtmlDocx\Model\CommentStart;
use Kovami\HtmlDocx\Model\Field;
use Kovami\HtmlDocx\Model\Formula;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\Note;
use Kovami\HtmlDocx\Model\NoteReference;
use Kovami\HtmlDocx\Model\NumberingReference;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\TabRun;
use Kovami\HtmlDocx\Model\TextRun;

/**
 * Reads the block content of one part (the document body, a note, a text
 * box, a table cell) into the document model, resolving Word's formatting
 * hierarchy so every paragraph and run carries its effective formatting.
 *
 * Revisions are shown as accepted (insertions kept, deletions dropped),
 * fields show their current result (page numbers stay fields), content
 * controls and smart tags are transparent, and hidden text is dropped
 * unless requested.
 */
final class BodyReader
{
    private const string CHECKED = "\u{2612}";

    private const string UNCHECKED = "\u{2610}";

    /**
     * Open complex fields, outermost first.
     *
     * @var list<array{instruction: string, result: bool, url: ?string, anchor: ?string, checkbox: ?bool, page: ?string, shown: string, shownAs: ?RunProperties}>
     */
    private array $fields = [];

    /** @var list<Block> blocks of text boxes met inside the current paragraph */
    private array $lifted = [];

    /** @var list<Bookmark|CommentStart|CommentEnd> met between paragraphs; they open the next one */
    private array $pendingMarkers = [];

    private bool $pageBreakPending = false;

    private readonly TableReader $tables;

    private readonly DrawingReader $drawings;

    public function __construct(
        private readonly ReaderContext $context,
        private readonly string $part,
    ) {
        $this->tables = new TableReader($context, $this->blocks(...));
        $this->drawings = new DrawingReader($context, $part, fn(Element $content): array => $this->nested($content));
    }

    /**
     * @return list<Block>
     */
    public function blocks(Element $container, ?TableStyleCondition $table = null, ?int $availableWidth = null): array
    {
        $blocks = [];
        /** @var list<array{style: ?string, contextual: bool}> $meta */
        $meta = [];

        foreach ($this->blockElements($container) as $element) {
            if (Xml::is($element, 'p')) {
                foreach ($this->paragraph($element, $table) as [$block, $style, $contextual]) {
                    $blocks[] = $block;
                    $meta[] = ['style' => $style, 'contextual' => $contextual];
                }
            } elseif (Xml::is($element, 'tbl')) {
                $tableBlock = $this->tables->read($element, $availableWidth ?? $this->context->contentWidth);

                if ($tableBlock !== null) {
                    $blocks[] = $tableBlock;
                    $meta[] = ['style' => null, 'contextual' => false];
                }
            } elseif (Xml::is($element, 'bookmarkStart')) {
                $this->bookmark($element);
            } elseif (Xml::is($element, 'commentRangeStart')) {
                array_push($this->pendingMarkers, ...$this->commentMarkers($element));
            } elseif (Xml::is($element, 'commentRangeEnd')) {
                // A range closed between paragraphs ends with the previous one.
                $last = $blocks === [] ? null : $blocks[array_key_last($blocks)];

                if ($last instanceof Paragraph) {
                    array_push($last->children, ...$this->commentMarkers($element));
                } else {
                    array_push($this->pendingMarkers, ...$this->commentMarkers($element));
                }
            }
        }

        self::applyContextualSpacing($blocks, $meta);

        return $blocks;
    }

    /**
     * Block-level children, looking through content controls, custom XML,
     * accepted revisions and markup-compatibility wrappers.
     *
     * @return list<Element>
     */
    private function blockElements(Element $container): array
    {
        $elements = [];

        foreach (Xml::children($container) as $child) {
            $transparent = match (true) {
                Xml::is($child, 'sdt') => Xml::child($child, 'sdtContent'),
                Xml::is($child, 'customXml'), Xml::is($child, 'ins'), Xml::is($child, 'moveTo'), Xml::is($child, 'smartTag') => $child,
                Xml::is($child, 'AlternateContent', Namespaces::MC) => self::alternateContent($child),
                default => null,
            };

            if ($transparent !== null) {
                array_push($elements, ...$this->blockElements($transparent));
            } elseif (Xml::is($child, 'altChunk')) {
                $this->context->warn('Embedded alternative-format content (w:altChunk) is not supported and was skipped');
            } elseif (! Xml::is($child, 'del') && ! Xml::is($child, 'moveFrom')) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * @return list<array{0: Block, 1: ?string, 2: bool}> the paragraph, then blocks lifted out of its text boxes
     */
    private function paragraph(Element $element, ?TableStyleCondition $table): array
    {
        $pPr = Xml::child($element, 'pPr');
        $direct = $this->context->parser->paragraph($pPr);
        $styles = $this->context->styles;
        $styleId = $styles->effectiveId(Xml::val($pPr, 'pStyle'), 'paragraph');
        $isDefaultStyle = $styleId === null || $styleId === $styles->defaultStyleId('paragraph');
        $style = $styles->paragraphStyle($styleId);
        $tableParagraph = $table === null ? new ParagraphFormat() : $table->paragraph;

        $format = $styles->defaultParagraph->over($isDefaultStyle ? $style->over($tableParagraph) : $tableParagraph->over($style));
        [$numbering, $level] = $this->numbering($direct, $style, $styleId);

        if ($level !== null) {
            $format = $format->over($level->paragraph);
        }

        $format = $format->over($direct);

        $paragraphRun = $styles->runStyle($styleId, 'paragraph');
        $tableRun = $table === null ? new RunFormat() : $table->run;
        $runLayers = $isDefaultStyle ? [$paragraphRun, $tableRun] : [$tableRun, $paragraphRun];

        $inlines = $this->inlines($element, $runLayers, null);
        $lifted = $this->takeLifted();
        $mark = RunFormat::resolve($styles->defaultRun, $runLayers, $format->mark);

        if ($numbering !== null && $level !== null) {
            $numbering = $numbering->label === '' && $level->text === null ? null : $numbering;
        }

        $sectionBreak = Xml::child($pPr, 'sectPr');
        $hidden = ! $this->context->includeHiddenText && $mark->hidden === true && $inlines === [];

        $results = [];

        if (! $hidden) {
            $properties = $this->paragraphProperties($format, $styleId, $numbering, $mark->toProperties());
            $results[] = [new Paragraph($properties, [...$this->takeMarkers(), ...self::normalizeInlines($inlines)]), $styleId, $format->contextualSpacing ?? false];
        }

        foreach ($lifted as $block) {
            $results[] = [$block, null, false];
        }

        if ($sectionBreak !== null && in_array(Xml::val($sectionBreak, 'type') ?? 'nextPage', ['nextPage', 'oddPage', 'evenPage'], true)) {
            $this->pageBreakPending = true;
        }

        return $results;
    }

    /**
     * @return array{0: ?NumberingReference, 1: ?NumberingLevel}
     */
    private function numbering(ParagraphFormat $direct, ParagraphFormat $style, ?string $styleId): array
    {
        $numId = $direct->numId ?? $style->numId;

        if ($numId === null || $numId === '0' || ! $this->context->numbering->exists($numId)) {
            return [null, null];
        }

        $level = $direct->level
            ?? ($direct->numId === null ? $style->level : null)
            ?? ($styleId === null ? null : $this->context->numbering->levelForParagraphStyle($numId, $styleId))
            ?? 0;

        $level = max(0, min(8, $level));

        return [$this->context->numbering->next($numId, $level), $this->context->numbering->levelDefinition($numId, $level)];
    }

    private function paragraphProperties(ParagraphFormat $format, ?string $styleId, ?NumberingReference $numbering, RunProperties $mark): ParagraphProperties
    {
        $borders = [];

        foreach (['top', 'left', 'bottom', 'right'] as $side) {
            $border = $format->borders[$side] ?? null;
            $borders[$side] = $border === null || $border->style === 'none' ? null : self::solidColor($border);
        }

        $outline = $format->outlineLevel;

        if ($outline === null && preg_match('/^heading\s*([1-9])$/i', (string) $this->context->styles->name($styleId), $match) === 1) {
            $outline = (int) $match[1] - 1;
        }

        $pageBreak = ($format->pageBreakBefore ?? false) || $this->pageBreakPending;
        $this->pageBreakPending = false;

        return new ParagraphProperties(
            styleId: $styleId,
            alignment: $format->alignment,
            indentLeft: $format->indentLeft ?? 0,
            indentRight: $format->indentRight ?? 0,
            firstLine: $format->firstLine ?? 0,
            spacingBefore: $format->beforeAutospacing === true ? 280 : ($format->spacingBefore ?? 0),
            spacingAfter: $format->afterAutospacing === true ? 280 : ($format->spacingAfter ?? 0),
            lineSpacing: $format->lineSpacing,
            lineRule: $format->lineSpacing === null ? null : ($format->lineRule ?? 'auto'),
            keepNext: $format->keepNext ?? false,
            keepLines: $format->keepLines ?? false,
            pageBreakBefore: $pageBreak,
            shading: $format->shading === null || $format->shading === 'auto' ? null : $format->shading,
            borders: new BorderSet($borders['top'], $borders['left'], $borders['bottom'], $borders['right']),
            numbering: $numbering,
            bidi: $format->bidi ?? false,
            outlineLevel: $outline,
            markRunProperties: $mark,
        );
    }

    /**
     * Inline content of a paragraph or of an inline container.
     *
     * @param  list<RunFormat>  $runLayers  style layers under character styles and direct formatting
     * @param  array{url: ?string, anchor: ?string}|null  $link  the enclosing w:hyperlink
     * @return list<Inline>
     */
    private function inlines(Element $container, array $runLayers, ?array $link): array
    {
        $inlines = [];

        foreach (Xml::children($container, null) as $child) {
            if ($child->namespaceURI === Namespaces::M) {
                if (in_array($child->localName, ['oMath', 'oMathPara'], true) && ! $this->suppressed()) {
                    // A formula keeps one set of run properties; the first math
                    // run says what the equation as a whole looks like.
                    $mathRun = Xml::descendants($child, 'r', Namespaces::M)[0] ?? null;
                    $direct = $this->context->parser->run(Xml::child($mathRun, 'rPr'));

                    $inlines[] = $this->wrap(new Formula(
                        OmmlToLatex::convert($child),
                        $child->localName === 'oMathPara',
                        self::withFont(RunFormat::resolve($this->context->styles->defaultRun, $runLayers, $direct)->toProperties()),
                    ), $link);
                }

                continue;
            }

            if (Xml::is($child, 'AlternateContent', Namespaces::MC)) {
                $choice = self::alternateContent($child);

                if ($choice !== null) {
                    array_push($inlines, ...$this->inlines($choice, $runLayers, $link));
                }

                continue;
            }

            if ($child->namespaceURI !== Namespaces::W) {
                continue;
            }

            match ($child->localName) {
                'r' => array_push($inlines, ...$this->run($child, $runLayers, $link)),
                'hyperlink' => array_push($inlines, ...$this->inlines($child, $runLayers, $this->hyperlinkTarget($child) ?? $link)),
                'fldSimple' => array_push($inlines, ...$this->simpleField($child, $runLayers, $link)),
                'sdt' => array_push($inlines, ...$this->inlines(Xml::child($child, 'sdtContent') ?? $child, $runLayers, $link)),
                'smartTag', 'customXml', 'ins', 'moveTo', 'dir', 'bdo', 'sdtContent' => array_push($inlines, ...$this->inlines($child, $runLayers, $link)),
                'bookmarkStart' => $this->bookmark($child),
                'commentRangeStart', 'commentRangeEnd' => array_push($inlines, ...$this->commentMarkers($child)),
                default => null,
            };

            if ($inlines !== [] && Xml::is($child, 'bookmarkStart')) {
                array_push($inlines, ...$this->takeMarkers());
            }
        }

        return $inlines;
    }

    /**
     * @param  list<RunFormat>  $runLayers
     * @param  array{url: ?string, anchor: ?string}|null  $link
     * @return list<Inline>
     */
    private function run(Element $run, array $runLayers, ?array $link): array
    {
        $rPr = Xml::child($run, 'rPr');
        $styles = $this->context->styles;
        $format = RunFormat::resolve(
            $styles->defaultRun,
            [...$runLayers, $styles->runStyle(Xml::val($rPr, 'rStyle'), 'character')],
            $this->context->parser->run($rPr),
        );
        $hidden = $format->hidden === true && ! $this->context->includeHiddenText;
        $properties = self::withFont($format->toProperties());

        $inlines = [];
        $text = '';

        foreach (Xml::children($run, null) as $child) {
            if (Xml::is($child, 'AlternateContent', Namespaces::MC)) {
                $choice = self::alternateContent($child);

                foreach ($choice === null ? [] : Xml::children($choice, null) as $alternative) {
                    if (Xml::is($alternative, 'drawing') || Xml::is($alternative, 'pict')) {
                        $this->flushText($text, $inlines, $properties, $link, $hidden);
                        array_push($inlines, ...$this->drawing($alternative, $properties, $link, $hidden));
                    }
                }

                continue;
            }

            if ($child->namespaceURI !== Namespaces::W) {
                continue;
            }

            switch ($child->localName) {
                case 't':
                    $text .= $child->textContent;
                    break;
                case 'sym':
                    $text .= self::symbol($child);
                    break;
                case 'noBreakHyphen':
                    $text .= "\u{2011}";
                    break;
                case 'softHyphen':
                    $text .= "\u{00AD}";
                    break;
                case 'tab':
                case 'ptab':
                    $this->flushText($text, $inlines, $properties, $link, $hidden);
                    $this->emit($inlines, new TabRun($properties), $link, $hidden);
                    break;
                case 'br':
                case 'cr':
                    $this->flushText($text, $inlines, $properties, $link, $hidden);
                    $type = Xml::attr($child, 'type') === 'page' ? BreakRun::PAGE : BreakRun::LINE;
                    $this->emit($inlines, new BreakRun($properties, $type), $link, $hidden);
                    break;
                case 'drawing':
                case 'pict':
                case 'object':
                    $this->flushText($text, $inlines, $properties, $link, $hidden);
                    array_push($inlines, ...$this->drawing($child, $properties, $link, $hidden));
                    break;
                case 'fldChar':
                    $this->flushText($text, $inlines, $properties, $link, $hidden);
                    array_push($inlines, ...$this->fieldCharacter($child, $properties, $link));
                    break;
                case 'instrText':
                    if ($this->fields !== [] && ! $this->fields[array_key_last($this->fields)]['result']) {
                        $this->fields[array_key_last($this->fields)]['instruction'] .= $child->textContent;
                    }
                    break;
                case 'footnoteReference':
                case 'endnoteReference':
                    $this->flushText($text, $inlines, $properties, $link, $hidden);
                    $reference = $this->note($child, $properties);

                    if ($reference !== null) {
                        $this->emit($inlines, $reference, $link, $hidden);
                    }
                    break;
                case 'commentReference':
                    $this->flushText($text, $inlines, $properties, $link, $hidden);
                    array_push($inlines, ...$this->pointComment($child));
                    break;
                case 'ruby':
                    $this->flushText($text, $inlines, $properties, $link, $hidden);
                    array_push($inlines, ...$this->inlines(Xml::child($child, 'rubyBase') ?? $child, $runLayers, $link));
                    break;
            }
        }

        $this->flushText($text, $inlines, $properties, $link, $hidden);

        return $inlines;
    }

    /**
     * @param  list<Inline>  $inlines
     * @param  array{url: ?string, anchor: ?string}|null  $link
     */
    private function flushText(string &$text, array &$inlines, RunProperties $properties, ?array $link, bool $hidden): void
    {
        if ($text !== '') {
            $this->emit($inlines, new TextRun($text, $properties), $link, $hidden);
        }

        $text = '';
    }

    /**
     * @param  list<Inline>  $inlines
     * @param  array{url: ?string, anchor: ?string}|null  $link
     */
    private function emit(array &$inlines, Inline $inline, ?array $link, bool $hidden): void
    {
        $index = array_key_last($this->fields);

        if ($index !== null && $this->fields[$index]['page'] !== null && $this->fields[$index]['result']) {
            // The value a page-number field shows now is kept on the field itself.
            if (! $hidden && $inline instanceof TextRun) {
                $this->fields[$index]['shown'] .= $inline->text;
                $this->fields[$index]['shownAs'] ??= $inline->properties;
            }

            return;
        }

        if (! $hidden && ! $this->suppressed()) {
            $inlines[] = $this->wrap($inline, $link);
        }
    }

    /**
     * @param  array{url: ?string, anchor: ?string}|null  $link
     * @return list<Inline>
     */
    private function drawing(Element $element, RunProperties $properties, ?array $link, bool $hidden): array
    {
        if ($hidden || $this->suppressed()) {
            return [];
        }

        $result = $this->drawings->read($element, $properties);
        array_push($this->lifted, ...$result['blocks']);

        return array_map(fn(Inline $inline): Inline => $this->wrap($inline, $link), $result['inlines']);
    }

    /**
     * Blocks of a text box or other nested container, read with independent field state.
     *
     * @return list<Block>
     */
    private function nested(Element $content): array
    {
        $reader = new self($this->context, $this->part);

        return $reader->blocks($content);
    }

    /**
     * @param  array{url: ?string, anchor: ?string}|null  $link
     * @return list<Inline>
     */
    private function fieldCharacter(Element $character, RunProperties $properties, ?array $link): array
    {
        $type = Xml::attr($character, 'fldCharType');

        if ($type === 'begin') {
            $checkBox = Xml::child(Xml::child($character, 'ffData'), 'checkBox');
            $state = Xml::child($checkBox, 'checked') ?? Xml::child($checkBox, 'default');

            $this->fields[] = [
                'instruction' => '',
                'result' => false,
                'url' => null,
                'anchor' => null,
                'checkbox' => $checkBox === null ? null : ($state !== null && ! in_array(Xml::attr($state, 'val'), ['0', 'false', 'off'], true)),
                'page' => null,
                'shown' => '',
                'shownAs' => null,
            ];

            return [];
        }

        if ($this->fields === []) {
            return [];
        }

        $index = array_key_last($this->fields);

        if ($type === 'separate' && ! $this->fields[$index]['result']) {
            $this->fields[$index] = [
                ...$this->fields[$index],
                'result' => true,
                'page' => self::pageField($this->fields[$index]['instruction']),
                ...self::fieldTarget($this->fields[$index]['instruction']),
            ];

            return [];
        }

        if ($type !== 'end') {
            return [];
        }

        $field = array_pop($this->fields);

        if ($field['page'] !== null && ! $this->suppressed()) {
            return [$this->wrap(new Field($field['page'], $field['shown'], $field['shownAs'] ?? $properties), $link)];
        }

        if ($field['checkbox'] !== null && ! $this->suppressed() && preg_match('/^\s*FORMCHECKBOX/i', $field['instruction']) === 1) {
            return [$this->wrap(new TextRun($field['checkbox'] ? self::CHECKED : self::UNCHECKED, $properties), $link)];
        }

        return [];
    }

    /**
     * @param  list<RunFormat>  $runLayers
     * @param  array{url: ?string, anchor: ?string}|null  $link
     * @return list<Inline>
     */
    private function simpleField(Element $field, array $runLayers, ?array $link): array
    {
        $instruction = (string) Xml::attr($field, 'instr');
        $page = self::pageField($instruction);

        if ($page !== null) {
            $shown = array_values(array_filter($this->inlines($field, $runLayers, null), static fn(Inline $inline): bool => $inline instanceof TextRun));
            $properties = $shown === [] ? self::withFont(RunFormat::resolve($this->context->styles->defaultRun, $runLayers, new RunFormat())->toProperties()) : $shown[0]->properties;

            return [$this->wrap(new Field($page, implode('', array_map(static fn(TextRun $run): string => $run->text, $shown)), $properties), $link)];
        }

        $target = self::fieldTarget($instruction);

        return $this->inlines($field, $runLayers, $target['url'] !== null || $target['anchor'] !== null ? $target : $link);
    }

    /**
     * @return array{url: ?string, anchor: ?string}
     */
    private static function fieldTarget(string $instruction): array
    {
        $tokens = [];
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|(\S+)/', $instruction, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        foreach ($matches as $match) {
            $quoted = $match[2] === null;
            $tokens[] = ['text' => $quoted ? stripslashes((string) $match[1]) : $match[2], 'quoted' => $quoted];
        }

        $name = strtoupper($tokens[0]['text'] ?? '');
        $arguments = array_slice($tokens, 1);
        $url = null;
        $anchor = null;

        if ($name === 'HYPERLINK') {
            for ($i = 0; $i < count($arguments); $i++) {
                $token = $arguments[$i];

                if (! $token['quoted'] && str_starts_with($token['text'], '\\')) {
                    $switch = strtolower(substr($token['text'], 1, 1));

                    if ($switch === 'l') {
                        $anchor = $arguments[++$i]['text'] ?? null;
                    } elseif (in_array($switch, ['o', 't', 'm'], true)) {
                        $i++;
                    }

                    continue;
                }

                $url ??= $token['text'];
            }
        } elseif (in_array($name, ['REF', 'PAGEREF', 'NOTEREF'], true) && isset($arguments[0])) {
            $switches = array_map(static fn(array $token): string => strtolower($token['text']), array_slice($arguments, 1));

            if (in_array('\h', $switches, true)) {
                $anchor = $arguments[0]['text'];
            }
        }

        $url = $url === '' ? null : $url;
        $anchor = $anchor === '' ? null : $anchor;

        // A HYPERLINK field with both a target and a \l switch points into that
        // document, exactly like a w:hyperlink carrying an id and an anchor.
        if ($url !== null && $anchor !== null) {
            return ['url' => explode('#', $url, 2)[0] . '#' . $anchor, 'anchor' => null];
        }

        return ['url' => $url, 'anchor' => $anchor];
    }

    /**
     * @return array{url: ?string, anchor: ?string}|null
     */
    private function hyperlinkTarget(Element $hyperlink): ?array
    {
        $anchor = Xml::attr($hyperlink, 'anchor');
        $id = Xml::attr($hyperlink, 'id', Namespaces::R);
        $url = null;

        if ($id !== null) {
            $relationship = $this->context->package->relationship($this->part, $id);
            $url = $relationship !== null && $relationship->external ? $relationship->target : null;
        }

        if ($url !== null && $anchor !== null) {
            return ['url' => explode('#', $url, 2)[0] . '#' . $anchor, 'anchor' => null];
        }

        return $url === null && $anchor === null ? null : ['url' => $url, 'anchor' => $anchor];
    }

    /** PAGE or NUMPAGES when the field instruction computes one of them. */
    private static function pageField(string $instruction): ?string
    {
        return preg_match('/^\s*(PAGE|NUMPAGES)\b/i', $instruction, $match) === 1 ? strtoupper($match[1]) : null;
    }

    /**
     * @return list<CommentStart|CommentEnd>
     */
    private function commentMarkers(Element $boundary): array
    {
        $id = $this->context->commentIds[(string) Xml::attr($boundary, 'id')] ?? null;

        if ($id === null) {
            return [];
        }

        return [Xml::is($boundary, 'commentRangeStart') ? new CommentStart($id) : new CommentEnd($id)];
    }

    /**
     * A comment Word anchored to a point rather than to a range.
     *
     * @return list<CommentStart|CommentEnd>
     */
    private function pointComment(Element $reference): array
    {
        $wordId = (string) Xml::attr($reference, 'id');
        $id = $this->context->commentIds[$wordId] ?? null;

        return $id === null || isset($this->context->rangedComments[$wordId]) ? [] : [new CommentStart($id), new CommentEnd($id)];
    }

    private function note(Element $reference, RunProperties $properties): ?NoteReference
    {
        $type = $reference->localName === 'endnoteReference' ? Note::ENDNOTE : Note::FOOTNOTE;
        $element = $this->context->noteElements[$type][(string) Xml::attr($reference, 'id')] ?? null;

        if ($element === null || in_array(Xml::attr($reference, 'customMarkFollows'), ['1', 'true', 'on'], true)) {
            return null;
        }

        $number = $this->context->nextNoteNumber($type);
        $reader = new self($this->context, $this->context->noteParts[$type]);
        $this->context->addNote(new Note($type, $number, self::trimLeadingSpace($reader->blocks($element))));

        return new NoteReference($type, $number, $properties);
    }

    private function bookmark(Element $element): void
    {
        $name = (string) Xml::attr($element, 'name');

        if (isset($this->context->linkedBookmarks[$name])) {
            $this->pendingMarkers[] = new Bookmark($this->context->nextBookmarkId(), $name);
        }
    }

    /**
     * @return list<Block>
     */
    private function takeLifted(): array
    {
        $lifted = $this->lifted;
        $this->lifted = [];

        return $lifted;
    }

    /**
     * @return list<Bookmark|CommentStart|CommentEnd>
     */
    private function takeMarkers(): array
    {
        $bookmarks = $this->pendingMarkers;
        $this->pendingMarkers = [];

        return $bookmarks;
    }

    /** True while inside the instruction part of a complex field. */
    private function suppressed(): bool
    {
        foreach ($this->fields as $field) {
            if (! $field['result']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{url: ?string, anchor: ?string}|null  $link
     */
    private function wrap(Inline $inline, ?array $link): Inline
    {
        foreach (array_reverse($this->fields) as $field) {
            if ($field['result'] && ($field['url'] !== null || $field['anchor'] !== null)) {
                $link = ['url' => $field['url'], 'anchor' => $field['anchor']];

                break;
            }
        }

        if ($link === null || $inline instanceof Hyperlink || $inline instanceof Bookmark) {
            return $inline;
        }

        return new Hyperlink($link['url'], $link['anchor'], [$inline]);
    }

    /**
     * Merges neighbouring links to the same target and neighbouring text
     * runs with identical formatting (Word splits runs for revision ids).
     *
     * @param  list<Inline>  $inlines
     * @return list<Inline>
     */
    private static function normalizeInlines(array $inlines): array
    {
        $result = [];

        foreach ($inlines as $inline) {
            $previous = $result === [] ? null : $result[array_key_last($result)];

            if ($inline instanceof Hyperlink && $previous instanceof Hyperlink
                && $inline->url === $previous->url && $inline->anchor === $previous->anchor) {
                $result[array_key_last($result)] = new Hyperlink($previous->url, $previous->anchor, [...$previous->children, ...$inline->children]);

                continue;
            }

            if ($inline instanceof TextRun && $previous instanceof TextRun && $inline->properties->equals($previous->properties)) {
                $result[array_key_last($result)] = new TextRun($previous->text . $inline->text, $previous->properties);

                continue;
            }

            $result[] = $inline;
        }

        return array_map(
            static fn(Inline $inline): Inline => $inline instanceof Hyperlink
                ? new Hyperlink($inline->url, $inline->anchor, self::normalizeInlines($inline->children))
                : $inline,
            $result,
        );
    }

    /**
     * `w:contextualSpacing`: no spacing between paragraphs of the same style.
     *
     * @param  list<Block>  $blocks
     * @param  list<array{style: ?string, contextual: bool}>  $meta
     */
    private static function applyContextualSpacing(array $blocks, array $meta): void
    {
        for ($i = 1, $count = count($blocks); $i < $count; $i++) {
            $previous = $blocks[$i - 1];
            $current = $blocks[$i];

            if (! $previous instanceof Paragraph || ! $current instanceof Paragraph || $meta[$i]['style'] !== $meta[$i - 1]['style']) {
                continue;
            }

            if ($meta[$i - 1]['contextual']) {
                $previous->properties->spacingAfter = 0;
            }

            if ($meta[$i]['contextual']) {
                $current->properties->spacingBefore = 0;
            }
        }
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<Block>
     */
    private static function trimLeadingSpace(array $blocks): array
    {
        $first = $blocks[0] ?? null;

        if ($first instanceof Paragraph && ($first->children[0] ?? null) instanceof TextRun) {
            $run = $first->children[0];
            $text = ltrim($run->text);
            $children = $first->children;

            if ($text === '') {
                array_shift($children);
            } else {
                $children[0] = new TextRun($text, $run->properties);
            }

            $blocks[0] = new Paragraph($first->properties, $children);
        }

        return $blocks;
    }

    private static function symbol(Element $symbol): string
    {
        $code = hexdec((string) Xml::attr($symbol, 'char'));

        if (! is_int($code) || $code <= 0) {
            return '';
        }

        return SymbolFonts::toUnicode((string) Xml::attr($symbol, 'font'), $code)
            ?? (string) mb_chr($code >= 0xF000 ? $code - 0xF000 : $code, 'UTF-8');
    }

    /** Word falls back to Times New Roman when a document names no font at all. */
    private static function withFont(RunProperties $properties): RunProperties
    {
        return $properties->fontFamily === null
            ? new RunProperties(...[...get_object_vars($properties), 'fontFamily' => 'Times New Roman'])
            : $properties;
    }

    private static function solidColor(Border $border): Border
    {
        return $border->color === 'auto' ? new Border($border->style, $border->size, '000000', $border->space) : $border;
    }

    /**
     * The branch of `mc:AlternateContent` to read: the fallback, which every
     * producer writes in the widely understood vocabulary (VML text boxes,
     * PNG previews of charts and SmartArt), else the first choice.
     */
    public static function alternateContent(Element $alternate): ?Element
    {
        return Xml::child($alternate, 'Fallback', Namespaces::MC) ?? Xml::child($alternate, 'Choice', Namespaces::MC);
    }
}
