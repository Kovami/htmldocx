<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx;

use Kovami\HtmlDocx\Exceptions\HtmlDocxException;

/**
 * File and stream access that reports failures as {@see HtmlDocxException}
 * with PHP's own message instead of a warning.
 *
 * @internal
 */
final class Files
{
    public static function read(string $path): string
    {
        $bytes = self::quietly(static fn(): string|false => file_get_contents($path), $error);

        if ($bytes === false) {
            throw HtmlDocxException::unreadableFile($error ?? "could not open {$path} for reading");
        }

        return $bytes;
    }

    /**
     * @param  resource  $stream
     */
    public static function readStream(mixed $stream): string
    {
        if (! is_resource($stream)) {
            throw HtmlDocxException::unreadableFile('the input is not a readable stream');
        }

        $bytes = stream_get_contents($stream);

        if ($bytes === false) {
            throw HtmlDocxException::unreadableFile('the input stream could not be read');
        }

        return $bytes;
    }

    /**
     * @return resource
     */
    public static function openForWriting(string $path): mixed
    {
        $stream = self::quietly(static fn(): mixed => fopen($path, 'wb'), $error);

        if (! is_resource($stream)) {
            throw HtmlDocxException::writerFailure($error ?? "could not open {$path} for writing");
        }

        return $stream;
    }

    /**
     * @return resource
     */
    public static function memoryStream(): mixed
    {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            throw HtmlDocxException::writerFailure('could not open an in-memory stream');
        }

        return $stream;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @param-out  string|null  $error
     * @return T
     */
    private static function quietly(callable $operation, ?string &$error): mixed
    {
        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
