<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use Kovami\HtmlDocx\Model\ImageData;

/**
 * Stores each distinct image once, however many times it is used. The parts
 * that show an image (the document, a notes part) each need a relationship
 * of their own, so the relationship set is given per registration.
 */
final class MediaRegistry
{
    /** @var array<string, array{path: string, image: ImageData}> */
    private array $media = [];

    private int $drawings = 0;

    /** Drawing ids are unique across every part of the package. */
    public function nextDrawingId(): int
    {
        return ++$this->drawings;
    }

    public function register(ImageData $image, Relationships $relationships): string
    {
        $hash = $image->hash();

        $this->media[$hash] ??= [
            'path' => 'media/image'.(count($this->media) + 1).'.'.$image->extension,
            'image' => $image,
        ];

        return $relationships->add(Relationships::IMAGE, $this->media[$hash]['path']);
    }

    /**
     * @return array<string, string> part name inside the package => bytes
     */
    public function parts(): array
    {
        $parts = [];

        foreach ($this->media as $entry) {
            $parts['word/'.$entry['path']] = $entry['image']->bytes;
        }

        return $parts;
    }

    /**
     * @return array<string, string> extension => content type
     */
    public function contentTypes(): array
    {
        $types = [];

        foreach ($this->media as $entry) {
            $types[$entry['image']->extension] = $entry['image']->contentType;
        }

        return $types;
    }
}
