<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Kovami\HtmlDocx\Docx\Reader\Format\ParagraphFormat;
use Kovami\HtmlDocx\Docx\Reader\Format\RunFormat;

/** One `w:lvl` of a numbering definition. */
final readonly class NumberingLevel
{
    /**
     * @param  string|null  $text  `w:lvlText` with symbol-font characters already mapped to Unicode; null hides the marker
     * @param  int|null  $restartAfter  `w:lvlRestart`: 0 never restarts, n restarts after level n-1 (1-based)
     */
    public function __construct(
        public int $level,
        public int $start = 0,
        public string $format = 'decimal',
        public ?string $text = null,
        public ?int $restartAfter = null,
        public bool $legal = false,
        public ?string $paragraphStyleId = null,
        public ParagraphFormat $paragraph = new ParagraphFormat,
        public RunFormat $run = new RunFormat,
    ) {}
}
