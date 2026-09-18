<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use XMLWriter;

/** Streams well-formed XML; strips characters XML 1.0 cannot represent. */
final class XmlBuilder
{
    private readonly XMLWriter $writer;

    public function __construct()
    {
        $this->writer = new XMLWriter();
        $this->writer->openMemory();
        $this->writer->startDocument('1.0', 'UTF-8', 'yes');
    }

    /**
     * @param  array<string, string|int|null>  $attributes  null values are omitted
     */
    public function open(string $name, array $attributes = []): self
    {
        $this->writer->startElement($name);
        $this->attributes($attributes);

        return $this;
    }

    public function close(): self
    {
        $this->writer->endElement();

        return $this;
    }

    /**
     * @param  array<string, string|int|null>  $attributes
     */
    public function leaf(string $name, array $attributes = []): self
    {
        return $this->open($name, $attributes)->close();
    }

    /**
     * @param  array<string, string|int|null>  $attributes
     */
    public function text(string $name, string $text, array $attributes = []): self
    {
        $this->open($name, $attributes);
        $this->writer->text(self::sanitize($text));

        return $this->close();
    }

    public function toString(): string
    {
        $this->writer->endDocument();

        return $this->writer->outputMemory();
    }

    public static function sanitize(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text);
    }

    /**
     * @param  array<string, string|int|null>  $attributes
     */
    private function attributes(array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $this->writer->writeAttribute($name, self::sanitize((string) $value));
            }
        }
    }
}
