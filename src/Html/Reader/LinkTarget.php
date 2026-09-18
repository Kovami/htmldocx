<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

final readonly class LinkTarget
{
    private function __construct(
        public ?string $url,
        public ?string $anchor,
    ) {}

    public static function url(string $url): self
    {
        return new self($url, null);
    }

    public static function anchor(string $bookmarkName): self
    {
        return new self(null, $bookmarkName);
    }

    public function equals(?self $other): bool
    {
        return $other !== null && $this->url === $other->url && $this->anchor === $other->anchor;
    }
}
