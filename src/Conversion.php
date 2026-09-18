<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx;

use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\Model\Document;

/**
 * A document that has been read, ready to be written in either format.
 *
 *     $html = HtmlDocx::for(Editor::SunEditor)->fromDocxFile('report.docx')->toHtml();
 *     HtmlDocx::plain()->fromHtml($html)->saveDocx('report.docx');
 *
 * Reading happens when the conversion is created, so a broken input fails
 * there; each target can then be produced any number of times.
 */
final readonly class Conversion
{
    /**
     * @internal created by {@see HtmlDocx}
     */
    public function __construct(
        private Document $document,
        private Engine $engine,
    ) {}

    /** The document model both formats are written from. */
    public function document(): Document
    {
        return $this->document;
    }

    public function toHtml(): string
    {
        return $this->engine->writeHtml($this->document);
    }

    /** @return string the path written to */
    public function saveHtml(string $path): string
    {
        $html = $this->toHtml();
        $stream = Files::openForWriting($path);

        try {
            if (fwrite($stream, $html) !== strlen($html)) {
                throw HtmlDocxException::writerFailure("could not write {$path}");
            }
        } finally {
            fclose($stream);
        }

        return $path;
    }

    /** The .docx package, as bytes. */
    public function toDocx(): string
    {
        $stream = Files::memoryStream();

        try {
            $this->engine->writeDocx($this->document, $stream);
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /** @return string the path written to */
    public function saveDocx(string $path): string
    {
        $stream = Files::openForWriting($path);

        try {
            $this->engine->writeDocx($this->document, $stream);
        } finally {
            fclose($stream);
        }

        return $path;
    }

    /**
     * Writes the .docx package into an open stream, e.g. an HTTP response body.
     *
     * @param  resource  $stream
     */
    public function streamDocx(mixed $stream): void
    {
        $this->engine->writeDocx($this->document, $stream);
    }
}
