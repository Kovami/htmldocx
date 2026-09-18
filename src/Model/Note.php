<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/** The body of a footnote or endnote. */
final readonly class Note
{
    public const string FOOTNOTE = 'footnote';

    public const string ENDNOTE = 'endnote';

    /**
     * @param  string  $type  {@see self::FOOTNOTE} or {@see self::ENDNOTE}
     * @param  int  $number  1-based position among notes of the same type
     * @param  list<Block>  $blocks
     */
    public function __construct(
        public string $type,
        public int $number,
        public array $blocks,
    ) {}
}
