<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Model;

use DateTimeImmutable;

final readonly class DocumentMetadata
{
    public function __construct(
        public DateTimeImmutable $createdAt,
        public ?string $title = null,
        public ?string $author = null,
        public ?string $language = null,
    ) {}
}
