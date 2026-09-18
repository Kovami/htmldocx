<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Html\Reader;

/**
 * Maps HTML ids to Word bookmark names: at most 40 characters, word
 * characters only, unique. The leading underscore makes them hidden
 * bookmarks, so they work as link targets without cluttering Word's list.
 */
final class BookmarkRegistry
{
    /** @var array<string, string> */
    private array $names = [];

    private int $nextId = 0;

    public function nameFor(string $htmlId): string
    {
        if (isset($this->names[$htmlId])) {
            return $this->names[$htmlId];
        }

        $base = '_'.substr((string) preg_replace('/[^A-Za-z0-9_]/', '_', $htmlId), 0, 32);
        $name = $base;
        $suffix = 1;

        while (in_array($name, $this->names, true)) {
            $name = $base.'_'.$suffix++;
        }

        return $this->names[$htmlId] = $name;
    }

    public function nextId(): int
    {
        return $this->nextId++;
    }
}
