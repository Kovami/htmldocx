<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\Package\ZipReader;

/** A single-entry archive assembled by hand, so headers can be forged. */
function handmadeZip(string $name, string $payload, int $method, int $crc, int $size, int $flags = 0, bool $zip64 = false): string
{
    $local = pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $method, 0, 0, $crc, strlen($payload), $size, strlen($name), 0) . $name . $payload;

    if (! $zip64) {
        $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $flags, $method, 0, 0, $crc, strlen($payload), $size, strlen($name), 0, 0, 0, 0, 0, 0) . $name;

        return $local . $central . pack('VvvvvVVv', 0x06054B50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
    }

    $extra = pack('vvPPP', 0x0001, 24, $size, strlen($payload), 0);
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 45, 45, $flags, $method, 0, 0, $crc, 0xFFFFFFFF, 0xFFFFFFFF, strlen($name), strlen($extra), 0, 0, 0, 0, 0xFFFFFFFF) . $name . $extra;
    $directoryOffset = strlen($local);
    $zip64Offset = $directoryOffset + strlen($central);
    $zip64Record = pack('VPvvVVPPPP', 0x06064B50, 44, 45, 45, 0, 0, 1, 1, strlen($central), $directoryOffset);
    $locator = pack('VVPV', 0x07064B50, 0, $zip64Offset, 1);

    return $local . $central . $zip64Record . $locator . pack('VvvvvVVv', 0x06054B50, 0, 0, 0xFFFF, 0xFFFF, 0xFFFFFFFF, 0xFFFFFFFF, 0);
}

function expectDocxError(callable $callback, string $message): void
{
    expect($callback)->toThrow(HtmlDocxException::class, $message);
}

it('reads archives written by ZipWriter', function () {
    $files = [
        'plain.txt' => [str_repeat('compressible ', 1000), true],
        'stored.bin' => [random_bytes(2048), false],
        'dir/юникод.xml' => ['<a>é</a>', true],
        'empty.txt' => ['', true],
    ];
    $zip = ZipReader::fromString(zipBytes($files));

    expect($zip->names())->toBe(array_keys($files));

    foreach ($files as $name => [$data]) {
        expect($zip->read($name))->toBe($data);
    }
});

it('reads archives written by ext-zip', function () {
    $placeholder = tempnam(sys_get_temp_dir(), 'zip');
    $path = $placeholder . '.zip';

    try {
        $archive = new ZipArchive();
        $archive->open($path, ZipArchive::CREATE);
        $archive->addEmptyDir('word');
        $archive->addFromString('word/document.xml', str_repeat('<w:p/>', 5000));
        $archive->addFromString('[Content_Types].xml', '<Types/>');
        $archive->setCompressionName('[Content_Types].xml', ZipArchive::CM_STORE);
        $archive->close();

        $zip = ZipReader::fromString((string) file_get_contents($path));

        expect($zip->names())->toBe(['word/document.xml', '[Content_Types].xml'])
            ->and($zip->read('word/document.xml'))->toBe(str_repeat('<w:p/>', 5000))
            ->and($zip->read('[Content_Types].xml'))->toBe('<Types/>');
    } finally {
        @unlink($path);
        @unlink($placeholder);
    }
});

it('reads entries streamed with data descriptors', function () {
    $process = proc_open(['/usr/bin/zip', '-q', '-', '-'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    fwrite($pipes[0], str_repeat('streamed ', 500));
    fclose($pipes[0]);
    $bytes = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect(ZipReader::fromString($bytes)->read('-'))->toBe(str_repeat('streamed ', 500));
})->skip(! is_executable('/usr/bin/zip'), 'zip is not installed');

it('reads ZIP64 archives', function () {
    $data = str_repeat('zip64 ', 100);
    $deflated = (string) gzdeflate($data);

    expect(ZipReader::fromString(handmadeZip('big.xml', $deflated, 8, crc32($data), strlen($data), zip64: true))->read('big.xml'))
        ->toBe($data);
});

it('looks entries up case-insensitively, as OPC part names are', function () {
    $zip = ZipReader::fromString(zipBytes(['Word/Document.xml' => ['x', true]]));

    expect($zip->has('word/document.xml'))->toBeTrue()
        ->and($zip->has('/WORD/DOCUMENT.XML'))->toBeTrue()
        ->and($zip->read('word/document.xml'))->toBe('x')
        ->and($zip->has('word/styles.xml'))->toBeFalse();
});

it('rejects data that is not a ZIP archive', function (string $bytes) {
    expectDocxError(fn() => ZipReader::fromString($bytes), 'not a ZIP archive');
})->with([
    'empty' => '',
    'text' => 'This is plainly not a zip file at all.',
    'png' => "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64),
]);

it('rejects missing entries', function () {
    expectDocxError(fn() => ZipReader::fromString(zipBytes(['a' => ['x', true]]))->read('b'), 'entry "b" does not exist');
});

it('rejects entries whose checksum does not match', function () {
    $zip = ZipReader::fromString(handmadeZip('a.txt', 'hello', 0, crc32('HELLO'), 5));

    expectDocxError(fn() => $zip->read('a.txt'), 'failed the integrity check');
});

it('rejects truncated archives', function () {
    $bytes = zipBytes(['a.txt' => [random_bytes(4000), false]]);
    $damaged = substr($bytes, 0, 100) . substr($bytes, 3000);

    expect(fn() => ZipReader::fromString($damaged)->read('a.txt'))->toThrow(HtmlDocxException::class);
});

it('rejects encrypted entries and unknown compression methods', function (string $archive, string $message) {
    expectDocxError(fn() => ZipReader::fromString($archive)->read('a.txt'), $message);
})->with([
    'encrypted' => [handmadeZip('a.txt', 'secret', 0, crc32('secret'), 6, flags: 1), 'is encrypted'],
    'bzip2' => [handmadeZip('a.txt', 'BZh9', 12, 0, 4), 'unsupported compression method 12'],
]);

it('rejects invalid deflate data', function () {
    expectDocxError(
        fn() => ZipReader::fromString(handmadeZip('a.txt', "\xFF\xFF\xFF\xFF garbage", 8, 0, 100))->read('a.txt'),
        'not valid deflate data',
    );
});

it('stops inflating a bomb at its declared size', function () {
    $context = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]);
    $megabyte = str_repeat("\0", 1024 * 1024);
    $bomb = '';

    for ($i = 0; $i < 200; $i++) {
        $bomb .= deflate_add($context, $megabyte, ZLIB_NO_FLUSH);
    }

    $bomb .= deflate_add($context, '', ZLIB_FINISH);
    $zip = ZipReader::fromString(handmadeZip('bomb.xml', $bomb, 8, 0, 1024));
    $before = memory_get_usage();

    expectDocxError(fn() => $zip->read('bomb.xml'), 'exceeds the decompression limit');
    expect(memory_get_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
});

it('enforces the per-entry and total decompression limits', function () {
    $files = ['a' => [str_repeat('a', 6000), true], 'b' => [str_repeat('b', 6000), true]];

    expectDocxError(fn() => ZipReader::fromString(zipBytes($files), maxEntryBytes: 5000)->read('a'), 'entry "a" exceeds');

    $zip = ZipReader::fromString(zipBytes($files), maxTotalBytes: 10000);
    $zip->read('a');

    expectDocxError(fn() => $zip->read('b'), 'entry "b" exceeds');
});

it('limits the number of entries', function () {
    $files = array_fill_keys(array_map(static fn(int $i): string => "f{$i}", range(1, 20)), ['x', false]);

    expectDocxError(fn() => ZipReader::fromString(zipBytes($files), maxEntries: 10), 'declares 20 entries');
});
