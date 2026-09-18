<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Image\DefaultImageSourceResolver;
use Kovami\HtmlDocx\Image\ImageInspector;
use Kovami\HtmlDocx\Tests\Support\TestImage;

it('decodes base64 and percent-encoded data URIs', function () {
    $resolver = new DefaultImageSourceResolver();

    expect($resolver->resolve('data:image/png;base64,' . base64_encode('bytes')))->toBe('bytes')
        ->and($resolver->resolve("data:image/png;base64,Ynl0\n ZXM="))->toBe('bytes')
        ->and($resolver->resolve('data:image/svg+xml,%3Csvg%3E'))->toBe('<svg>')
        ->and($resolver->resolve('data:image/png;base64,***'))->toBeNull()
        ->and($resolver->resolve('data:'))->toBeNull();
});

it('does not touch the network or the filesystem unless configured', function (string $source) {
    expect((new DefaultImageSourceResolver())->resolve($source))->toBeNull();
})->with(['https://example.com/a.png', '//example.com/a.png', '/etc/hosts', 'file:///etc/hosts', 'ftp://x/a.png', '']);

it('confines local paths to the base directory', function () {
    $base = sys_get_temp_dir() . '/kovami-resolver-' . bin2hex(random_bytes(4));
    mkdir($base);
    file_put_contents("{$base}/a b.png", 'inside');
    $resolver = new DefaultImageSourceResolver(localBaseDirectory: $base);

    try {
        expect($resolver->resolve('a%20b.png'))->toBe('inside')
            ->and($resolver->resolve('/a b.png#frag'))->toBe('inside')
            ->and($resolver->resolve('../' . basename($base) . '/a b.png'))->toBe('inside')
            ->and($resolver->resolve('../../../../etc/hosts'))->toBeNull()
            ->and($resolver->resolve('.'))->toBeNull()
            ->and($resolver->resolve('missing.png'))->toBeNull();
    } finally {
        unlink("{$base}/a b.png");
        rmdir($base);
    }
});

it('detects formats from content, not from names', function () {
    $inspector = new ImageInspector();
    $png = $inspector->inspect(TestImage::png(12, 34));

    expect($png?->extension)->toBe('png')
        ->and($png?->contentType)->toBe('image/png')
        ->and([$png?->widthPx, $png?->heightPx])->toBe([12, 34])
        ->and($inspector->inspect(substr(TestImage::png(12, 34), 0, 20)))->toBeNull()
        ->and($inspector->inspect('GIF89a'))->toBeNull()
        ->and($inspector->inspect('<svg xmlns="http://www.w3.org/2000/svg"></svg>'))->toBeNull()
        ->and($inspector->inspect(''))->toBeNull();
});
