<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/**
 * A value Word computes while laying the document out: the current page
 * number or the page count. $result is the value last shown.
 */
final readonly class Field implements Inline
{
    public const string PAGE = 'PAGE';

    public const string NUMPAGES = 'NUMPAGES';

    public const array SUPPORTED = [self::PAGE, self::NUMPAGES];

    /**
     * @param  string  $name  {@see self::PAGE} or {@see self::NUMPAGES}
     */
    public function __construct(
        public string $name,
        public string $result,
        public RunProperties $properties = new RunProperties(),
    ) {}
}
