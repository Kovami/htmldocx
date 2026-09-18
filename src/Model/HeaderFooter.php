<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

/**
 * A page header or footer. Word shows the `first` one on the first page and
 * the `even` one on even pages only when the document has one of that type;
 * every other page shows the `default` one.
 */
final readonly class HeaderFooter
{
    public const string HEADER = 'header';

    public const string FOOTER = 'footer';

    public const string DEFAULT = 'default';

    public const string FIRST = 'first';

    public const string EVEN = 'even';

    /**
     * @param  string  $kind  {@see self::HEADER} or {@see self::FOOTER}
     * @param  string  $type  {@see self::DEFAULT}, {@see self::FIRST} or {@see self::EVEN}
     * @param  list<Block>  $blocks
     */
    public function __construct(
        public string $kind,
        public string $type,
        public array $blocks,
    ) {}
}
