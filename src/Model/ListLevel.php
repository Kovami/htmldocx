<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class ListLevel
{
    /**
     * @param  string  $format  ST_NumberFormat value (bullet, decimal, lowerLetter, ...)
     * @param  string  $text  level text, e.g. "%1." or "•"
     * @param  int  $indentLeft  twips
     * @param  int  $hanging  twips
     * @param  string  $suffix  ST_LevelSuffix: what follows the marker — tab (to the text's indent), space or nothing
     * @param  bool  $symbolBullet  a bullet drawn in the Symbol font (Word's own, and what a DOCX written here uses)
     * @param  int|null  $markerTab  twips: where the text after the marker starts, a tab stop of the level's own
     */
    public function __construct(
        public int $level,
        public string $format,
        public string $text,
        public int $start,
        public int $indentLeft,
        public int $hanging,
        public string $suffix = 'tab',
        public bool $symbolBullet = true,
        public ?int $markerTab = null,
    ) {}
}
