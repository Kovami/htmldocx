<?php

declare(strict_types=1);

it('writes archives that ZipArchive reads back byte for byte', function () {
    $files = [
        'plain.txt' => [str_repeat('compressible ', 1000), true],
        'stored.bin' => [random_bytes(2048), false],
        'dir/юникод.xml' => ['<a>é</a>', true],
        'empty.txt' => ['', true],
    ];

    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, zipBytes($files));

    try {
        $archive = new ZipArchive;
        expect($archive->open($path, ZipArchive::CHECKCONS))->toBeTrue()
            ->and($archive->numFiles)->toBe(4);

        foreach ($files as $name => [$data]) {
            expect($archive->getFromName($name))->toBe($data);
        }

        expect($archive->statName('plain.txt')['comp_method'])->toBe(ZipArchive::CM_DEFLATE)
            ->and($archive->statName('stored.bin')['comp_method'])->toBe(ZipArchive::CM_STORE);

        $archive->close();
    } finally {
        unlink($path);
    }
});

it('stamps entries with the given time in MS-DOS format', function () {
    $header = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate', zipBytes(['a' => ['x', true]]));

    expect($header['signature'])->toBe(0x04034B50)
        ->and($header['time'])->toBe((5 << 11) | (6 << 5) | 4)
        ->and($header['date'])->toBe(((2026 - 1980) << 9) | (3 << 5) | 4)
        ->and($header['flags'] & 0x0800)->toBe(0x0800);
});

it('stores data that does not shrink when deflated', function () {
    $path = tempnam(sys_get_temp_dir(), 'zip');
    file_put_contents($path, zipBytes(['noise' => [random_bytes(64), true]]));

    try {
        $archive = new ZipArchive;
        $archive->open($path);

        expect($archive->statIndex(0)['comp_method'])->toBe(ZipArchive::CM_STORE);
        $archive->close();
    } finally {
        unlink($path);
    }
});

it('is deterministic', function () {
    $files = ['a.xml' => ['<a/>', true], 'b.bin' => ['bytes', false]];

    expect(zipBytes($files))->toBe(zipBytes($files));
});

it('passes the system unzip integrity test', function () {
    $placeholder = tempnam(sys_get_temp_dir(), 'zip');
    $path = $placeholder.'.zip';
    file_put_contents($path, zipBytes(['x/y.txt' => [str_repeat('z', 10000), true]]));

    try {
        exec('unzip -t '.escapeshellarg($path).' 2>&1', $output, $exitCode);

        expect($exitCode)->toBe(0)
            ->and(implode("\n", $output))->toContain('No errors detected');
    } finally {
        unlink($path);
        unlink($placeholder);
    }
})->skip(! is_executable('/usr/bin/unzip'), 'unzip is not installed');
