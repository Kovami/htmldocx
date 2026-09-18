<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Package;

use Kovami\HtmlDocx\Exceptions\HtmlDocxException;

/**
 * In-memory ZIP (PKWARE APPNOTE) reader for untrusted archives: stored and
 * deflated entries, ZIP64, CRC verification. Needs only ext-zlib.
 *
 * Decompression is bounded: an entry may not inflate past its declared size
 * or {@see self::$maxEntryBytes}, all reads together may not exceed
 * {@see self::$maxTotalBytes}, and the archive may not declare more than
 * {@see self::$maxEntries} entries, so zip bombs fail fast instead of
 * exhausting memory.
 */
final class ZipReader
{
    private const int EOCD_SIGNATURE = 0x06054B50;

    private const int ZIP64_EOCD_SIGNATURE = 0x06064B50;

    private const int ZIP64_LOCATOR_SIGNATURE = 0x07064B50;

    private const int CENTRAL_SIGNATURE = 0x02014B50;

    private const int LOCAL_SIGNATURE = 0x04034B50;

    private const int FLAG_ENCRYPTED = 0x0001;

    private const int METHOD_STORED = 0;

    private const int METHOD_DEFLATED = 8;

    /** Small input chunks bound how much a single inflate call can expand (deflate peaks near 1032:1). */
    private const int CHUNK = 4096;

    /** @var array<string, array{name: string, method: int, flags: int, crc: int, compressed: int, size: int, offset: int}> keyed by lowercase name */
    private array $entries = [];

    private int $inflatedTotal = 0;

    private function __construct(
        private readonly string $bytes,
        private readonly int $maxEntryBytes,
        private readonly int $maxTotalBytes,
        private readonly int $maxEntries,
    ) {
        $this->readCentralDirectory();
    }

    public static function fromString(
        string $bytes,
        int $maxEntryBytes = 128 * 1024 * 1024,
        int $maxTotalBytes = 512 * 1024 * 1024,
        int $maxEntries = 10000,
    ): self {
        return new self($bytes, $maxEntryBytes, $maxTotalBytes, $maxEntries);
    }

    /** Entry names are compared case-insensitively, as OPC part names are. */
    public function has(string $name): bool
    {
        return isset($this->entries[strtolower(ltrim($name, '/'))]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_map(static fn (array $entry): string => $entry['name'], $this->entries));
    }

    public function read(string $name): string
    {
        $entry = $this->entries[strtolower(ltrim($name, '/'))] ?? throw self::corrupt("entry \"{$name}\" does not exist");

        if (($entry['flags'] & self::FLAG_ENCRYPTED) !== 0) {
            throw self::corrupt("entry \"{$entry['name']}\" is encrypted");
        }

        if ($entry['size'] > $this->maxEntryBytes || $this->inflatedTotal + $entry['size'] > $this->maxTotalBytes) {
            throw self::tooLarge($entry['name']);
        }

        $data = $this->readData($entry);

        if (strlen($data) !== $entry['size'] || (crc32($data) & 0xFFFFFFFF) !== $entry['crc']) {
            throw self::corrupt("entry \"{$entry['name']}\" failed the integrity check");
        }

        $this->inflatedTotal += $entry['size'];

        return $data;
    }

    /**
     * @param  array{name: string, method: int, flags: int, crc: int, compressed: int, size: int, offset: int}  $entry
     */
    private function readData(array $entry): string
    {
        $header = $this->unpack('Vsignature/x22/vnameLength/vextraLength', $entry['offset'], 30);

        if ($header['signature'] !== self::LOCAL_SIGNATURE) {
            throw self::corrupt("entry \"{$entry['name']}\" has no local header");
        }

        $start = $entry['offset'] + 30 + $header['nameLength'] + $header['extraLength'];

        if ($start + $entry['compressed'] > strlen($this->bytes)) {
            throw self::corrupt("entry \"{$entry['name']}\" is truncated");
        }

        $compressed = substr($this->bytes, $start, $entry['compressed']);

        return match ($entry['method']) {
            self::METHOD_STORED => $compressed,
            self::METHOD_DEFLATED => $this->inflate($compressed, $entry),
            default => throw self::corrupt("entry \"{$entry['name']}\" uses unsupported compression method {$entry['method']}"),
        };
    }

    /**
     * @param  array{name: string, size: int}  $entry
     */
    private function inflate(string $compressed, array $entry): string
    {
        $context = inflate_init(ZLIB_ENCODING_RAW) ?: throw self::corrupt('zlib is unavailable');
        $length = strlen($compressed);
        $output = '';
        $position = 0;

        do {
            $last = $position + self::CHUNK >= $length;
            set_error_handler(static fn (): bool => true);

            try {
                $chunk = inflate_add($context, substr($compressed, $position, self::CHUNK), $last ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            } finally {
                restore_error_handler();
            }

            if ($chunk === false) {
                throw self::corrupt("entry \"{$entry['name']}\" is not valid deflate data");
            }

            $output .= $chunk;

            if (strlen($output) > $entry['size']) {
                throw self::tooLarge($entry['name']);
            }

            $position += self::CHUNK;
        } while (! $last);

        return $output;
    }

    private function readCentralDirectory(): void
    {
        $searchFrom = max(0, strlen($this->bytes) - 22 - 0xFFFF);
        $found = strrpos(substr($this->bytes, $searchFrom), pack('V', self::EOCD_SIGNATURE));

        if ($found === false) {
            throw self::corrupt('not a ZIP archive');
        }

        $eocd = $searchFrom + $found;

        $record = $this->unpack('x4/x2/x2/x2/ventries/VdirectorySize/VdirectoryOffset', $eocd, 22);
        $entries = $record['entries'];
        $directoryOffset = $record['directoryOffset'];

        if ($entries === 0xFFFF || $directoryOffset === 0xFFFFFFFF || $record['directorySize'] === 0xFFFFFFFF) {
            [$entries, $directoryOffset] = $this->readZip64EndOfCentralDirectory($eocd);
        }

        if ($entries > $this->maxEntries) {
            throw HtmlDocxException::docxTooLarge("the archive declares {$entries} entries");
        }

        $position = $directoryOffset;

        for ($i = 0; $i < $entries; $i++) {
            $header = $this->unpack(
                'Vsignature/x4/vflags/vmethod/x4/Vcrc/Vcompressed/Vsize/vnameLength/vextraLength/vcommentLength/x8/Voffset',
                $position,
                46,
            );

            if ($header['signature'] !== self::CENTRAL_SIGNATURE) {
                throw self::corrupt('the central directory is damaged');
            }

            $name = $this->slice($position + 46, $header['nameLength']);
            $extra = $this->slice($position + 46 + $header['nameLength'], $header['extraLength']);
            $entry = [
                'name' => $name,
                'method' => $header['method'],
                'flags' => $header['flags'],
                'crc' => $header['crc'],
                'compressed' => $header['compressed'],
                'size' => $header['size'],
                'offset' => $header['offset'],
            ];

            $entry = self::applyZip64Extra($entry, $extra);

            if (! str_ends_with($name, '/')) {
                $this->entries[strtolower(ltrim($name, '/'))] ??= $entry;
            }

            $position += 46 + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
        }
    }

    /**
     * @return array{0: int, 1: int} entry count and central directory offset
     */
    private function readZip64EndOfCentralDirectory(int $eocd): array
    {
        $locator = $this->unpack('Vsignature/x4/Poffset', $eocd - 20, 20);

        if ($locator['signature'] !== self::ZIP64_LOCATOR_SIGNATURE) {
            throw self::corrupt('the ZIP64 end of central directory is missing');
        }

        $record = $this->unpack('Vsignature/x28/Pentries/x8/Poffset', $locator['offset'], 56);

        if ($record['signature'] !== self::ZIP64_EOCD_SIGNATURE) {
            throw self::corrupt('the ZIP64 end of central directory is damaged');
        }

        return [$record['entries'], $record['offset']];
    }

    /**
     * Fills the 32-bit fields saturated at 0xFFFFFFFF from the ZIP64 extended
     * information extra field, which lists them in a fixed order.
     *
     * @param  array{name: string, method: int, flags: int, crc: int, compressed: int, size: int, offset: int}  $entry
     * @return array{name: string, method: int, flags: int, crc: int, compressed: int, size: int, offset: int}
     */
    private static function applyZip64Extra(array $entry, string $extra): array
    {
        for ($position = 0; $position + 4 <= strlen($extra);) {
            ['id' => $id, 'length' => $length] = (array) unpack('vid/vlength', $extra, $position);
            $position += 4;

            if ($id === 0x0001) {
                $field = substr($extra, $position, $length);
                $cursor = 0;

                foreach (['size', 'compressed', 'offset'] as $key) {
                    if ($entry[$key] === 0xFFFFFFFF && $cursor + 8 <= strlen($field)) {
                        $entry[$key] = (int) (unpack('P', $field, $cursor) ?: [1 => 0])[1];
                        $cursor += 8;
                    }
                }
            }

            $position += $length;
        }

        return $entry;
    }

    /**
     * @return array<string, int>
     */
    private function unpack(string $format, int $offset, int $length): array
    {
        if ($offset < 0 || $offset + $length > strlen($this->bytes)) {
            throw self::corrupt('unexpected end of archive');
        }

        /** @var array<string, int> */
        return (array) unpack($format, $this->bytes, $offset);
    }

    private function slice(int $offset, int $length): string
    {
        if ($offset + $length > strlen($this->bytes)) {
            throw self::corrupt('unexpected end of archive');
        }

        return substr($this->bytes, $offset, $length);
    }

    private static function corrupt(string $reason): HtmlDocxException
    {
        return HtmlDocxException::malformedDocx($reason);
    }

    private static function tooLarge(string $name): HtmlDocxException
    {
        return HtmlDocxException::docxTooLarge("entry \"{$name}\" exceeds the decompression limit");
    }
}
