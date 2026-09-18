<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Package;

use DateTimeInterface;
use Kovami\HtmlDocx\Exceptions\HtmlDocxException;

/**
 * Minimal streaming ZIP (PKWARE APPNOTE) writer: deflate or store, UTF-8
 * names, no ZIP64. Needs only ext-zlib, writes to any stream, and is
 * byte-for-byte deterministic for a fixed timestamp.
 */
final class ZipWriter
{
    private const int MAX_UINT32 = 0xFFFFFFFF;

    private const int FLAG_UTF8_NAMES = 0x0800;

    private int $offset = 0;

    /** @var list<string> */
    private array $centralDirectory = [];

    private readonly int $dosTime;

    private readonly int $dosDate;

    /**
     * @param  resource  $stream
     */
    public function __construct(
        private readonly mixed $stream,
        DateTimeInterface $modifiedAt,
    ) {
        $year = max(1980, min(2107, (int) $modifiedAt->format('Y')));
        $this->dosTime = ((int) $modifiedAt->format('G') << 11) | ((int) $modifiedAt->format('i') << 5) | intdiv((int) $modifiedAt->format('s'), 2);
        $this->dosDate = (($year - 1980) << 9) | ((int) $modifiedAt->format('n') << 5) | (int) $modifiedAt->format('j');
    }

    public function addFile(string $name, string $data, bool $compress = true): void
    {
        $method = 0;
        $payload = $data;

        if ($compress && $data !== '') {
            $deflated = gzdeflate($data, 6);

            if ($deflated !== false && strlen($deflated) < strlen($data)) {
                $method = 8;
                $payload = $deflated;
            }
        }

        $size = strlen($data);
        $compressedSize = strlen($payload);

        if ($size > self::MAX_UINT32 || $this->offset + $compressedSize > self::MAX_UINT32 || count($this->centralDirectory) >= 0xFFFF) {
            throw HtmlDocxException::writerFailure('document exceeds the ZIP32 limits');
        }

        $crc = crc32($data);

        $header = pack('VvvvvvVVVvv', 0x04034B50, 20, self::FLAG_UTF8_NAMES, $method, $this->dosTime, $this->dosDate, $crc, $compressedSize, $size, strlen($name), 0);

        $this->centralDirectory[] = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014B50, 20, 20, self::FLAG_UTF8_NAMES, $method, $this->dosTime, $this->dosDate,
            $crc, $compressedSize, $size, strlen($name), 0, 0, 0, 0, 0, $this->offset,
        ).$name;

        $this->write($header.$name);
        $this->write($payload);
    }

    public function finish(): void
    {
        $directory = implode('', $this->centralDirectory);
        $entries = count($this->centralDirectory);

        $this->write($directory);
        $this->write(pack('VvvvvVVv', 0x06054B50, 0, 0, $entries, $entries, strlen($directory), $this->offset - strlen($directory), 0));
    }

    private function write(string $bytes): void
    {
        $length = strlen($bytes);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($this->stream, substr($bytes, $written));

            if ($result === false || $result === 0) {
                throw HtmlDocxException::writerFailure('could not write to the output stream');
            }

            $written += $result;
        }

        $this->offset += $length;
    }
}
