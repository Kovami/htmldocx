<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** The in-text mark of a footnote or endnote. */
final readonly class NoteReference implements Inline
{
    /**
     * @param  string  $type  {@see Note::FOOTNOTE} or {@see Note::ENDNOTE}
     */
    public function __construct(
        public string $type,
        public int $number,
        public RunProperties $properties = new RunProperties(),
    ) {}
}
