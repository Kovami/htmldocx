<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Closure;
use Dom\Element;
use Kovami\HtmlDocx\Docx\Reader\Format\FormatParser;
use Kovami\HtmlDocx\Image\ImageInspector;
use Kovami\HtmlDocx\Model\Note;

/** State shared by every part read from one package: definitions, notes, bookmarks, warnings. */
final class ReaderContext
{
    /** @var list<Note> */
    public array $notes = [];

    /** @var array<string, int> note type => notes numbered so far */
    private array $noteCounts = [];

    private int $nextBookmarkId = 1;

    /**
     * @param  array<string, true>  $linkedBookmarks  bookmark names some link or field points at
     * @param  array<string, array<string, Element>>  $noteElements  note type => id => w:footnote/w:endnote
     * @param  array<string, string>  $noteParts  note type => part name
     * @param  array<string, int>  $commentIds  w:id of a comment => its id in the model
     * @param  array<string, true>  $rangedComments  w:id of every comment with a w:commentRangeStart
     * @param  Closure(string): void  $warn
     */
    public function __construct(
        public readonly OpcPackage $package,
        public readonly FormatParser $parser,
        public readonly StyleSheet $styles,
        public readonly Numbering $numbering,
        public readonly ImageInspector $images,
        public readonly int $contentWidth,
        public readonly bool $includeHiddenText,
        public readonly array $linkedBookmarks,
        public readonly array $noteElements,
        public readonly array $noteParts,
        private readonly Closure $warn,
        public readonly array $commentIds = [],
        public readonly array $rangedComments = [],
    ) {}

    public function warn(string $message): void
    {
        ($this->warn)($message);
    }

    public function nextNoteNumber(string $type): int
    {
        return $this->noteCounts[$type] = ($this->noteCounts[$type] ?? 0) + 1;
    }

    public function addNote(Note $note): void
    {
        $this->notes[] = $note;
    }

    public function nextBookmarkId(): int
    {
        return $this->nextBookmarkId++;
    }
}
