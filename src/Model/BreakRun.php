<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

final readonly class BreakRun implements Inline
{
    public const string LINE = 'textWrapping';

    public const string PAGE = 'page';

    public function __construct(
        public RunProperties $properties = new RunProperties,
        public string $type = self::LINE,
    ) {}
}
