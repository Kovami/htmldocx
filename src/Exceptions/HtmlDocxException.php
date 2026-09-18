<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Exceptions;

use RuntimeException;

final class HtmlDocxException extends RuntimeException
{
    public static function malformedHtml(string $reason): self
    {
        return new self("Unable to parse HTML: {$reason}");
    }

    public static function writerFailure(string $reason): self
    {
        return new self("Unable to write DOCX document: {$reason}");
    }

    public static function htmlWriterFailure(string $reason): self
    {
        return new self("Unable to write HTML document: {$reason}");
    }

    public static function unreadableFile(string $reason): self
    {
        return new self("Unable to read the file: {$reason}");
    }

    public static function malformedDocx(string $reason): self
    {
        return new self("Unable to read DOCX document: {$reason}");
    }

    public static function docxTooLarge(string $reason): self
    {
        return new self("Refusing to read DOCX document: {$reason}");
    }
}
