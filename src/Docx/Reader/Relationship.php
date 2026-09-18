<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

final readonly class Relationship
{
    /**
     * @param  string  $target  an absolute part name, or the raw URI of an external target
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $target,
        public bool $external,
    ) {}
}
