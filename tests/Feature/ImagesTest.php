<?php

declare(strict_types=1);

use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Image\ImageSourceResolver;
use Kovami\HtmlDocx\Tests\Support\Docx;
use Kovami\HtmlDocx\Tests\Support\TestImage;

/**
 * @return array{0: int, 1: int} rendered size in pixels
 */
function imageSizePx(Docx $docx, int $index = 0): array
{
    $extent = $docx->query('//wp:inline/wp:extent')[$index];

    return [(int) round($extent->getAttribute('cx') / 9525), (int) round($extent->getAttribute('cy') / 9525)];
}

it('embeds data URI images with a relationship and content type', function () {
    $png = TestImage::png(40, 20);
    $docx = docx('<p><img src="data:image/png;base64,' . base64_encode($png) . '" alt="A chart"></p>');

    $embed = $docx->first('//a:blip')->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed');
    $relationship = $docx->first("//rel:Relationship[@Id='{$embed}']", null, 'word/_rels/document.xml.rels');

    expect($docx->parts['word/' . $relationship->getAttribute('Target')])->toBe($png)
        ->and($docx->first("//ct:Default[@Extension='png']", null, '[Content_Types].xml')->getAttribute('ContentType'))->toBe('image/png')
        ->and($docx->first('//wp:docPr')->getAttribute('descr'))->toBe('A chart')
        ->and(imageSizePx($docx))->toBe([40, 20]);
});

it('sizes images from CSS, attributes and intrinsic size', function (string $attributes, array $expected) {
    $docx = docx('<p><img src="' . TestImage::pngDataUri(200, 100) . "\" {$attributes}></p>");

    expect(imageSizePx($docx))->toBe($expected);
})->with([
    'intrinsic' => ['', [200, 100]],
    'css width keeps ratio' => ['style="width: 300px; height: auto"', [300, 150]],
    'css height keeps ratio' => ['style="height: 50px"', [100, 50]],
    'both given' => ['style="width: 120px; height: 30px"', [120, 30]],
    'html attributes' => ['width="80" height="80"', [80, 80]],
    'css beats attributes' => ['width="80" style="width: 100px"', [100, 50]],
    'percent of available width' => ['style="width: 50%"', [321, 161]],
]);

it('never makes an image wider than the available width', function () {
    $docx = docx('<p><img src="' . TestImage::pngDataUri(2000, 1000) . '"></p><table><tr><td><img src="' . TestImage::pngDataUri(2000, 1000) . '"></td><td>x</td></tr></table>');

    [$pageWidth, $pageHeight] = imageSizePx($docx, 0);
    [$cellWidth] = imageSizePx($docx, 1);

    expect($pageWidth)->toBe(643)
        ->and($pageHeight)->toBe(321)
        ->and($cellWidth)->toBeLessThan(321);
});

it('stores an image used several times only once', function () {
    $uri = TestImage::pngDataUri(10, 10);
    $docx = docx("<p><img src=\"{$uri}\"><img src=\"{$uri}\"></p><p><img src=\"{$uri}\"></p>");

    expect($docx->count('//w:drawing'))->toBe(3)
        ->and(array_filter(array_keys($docx->parts), static fn(string $name): bool => str_starts_with($name, 'word/media/')))->toHaveCount(1);
});

it('embeds JPEG and GIF, identifying the format from the bytes', function () {
    $image = imagecreatetruecolor(8, 4);
    ob_start();
    imagejpeg($image);
    $jpeg = ob_get_clean();
    ob_start();
    imagegif($image);
    $gif = ob_get_clean();

    $docx = docx('<p><img src="data:image/png;base64,' . base64_encode($jpeg) . '"><img src="data:image/gif;base64,' . base64_encode($gif) . '"></p>');

    expect($docx->has('word/media/image1.jpeg'))->toBeTrue()
        ->and($docx->has('word/media/image2.gif'))->toBeTrue();
})->skip(! function_exists('imagejpeg'), 'ext-gd is required to generate JPEG/GIF fixtures');

it('converts WebP to PNG', function () {
    $image = imagecreatetruecolor(6, 3);
    ob_start();
    imagewebp($image);
    $webp = ob_get_clean();

    $docx = docx('<p><img src="data:image/webp;base64,' . base64_encode($webp) . '"></p>');

    expect($docx->has('word/media/image1.png'))->toBeTrue()
        ->and(imageSizePx($docx))->toBe([6, 3]);
})->skip(! function_exists('imagewebp'), 'ext-gd with WebP support is required');

it('falls back to alt text for images it cannot embed', function (string $src) {
    $docx = docx("<p>before <img src=\"{$src}\" alt=\"[logo]\"> after</p>");

    expect($docx->count('//w:drawing'))->toBe(0)
        ->and($docx->paragraphTexts())->toBe(['before [logo] after']);
})->with([
    'remote without fetcher' => 'https://example.com/logo.png',
    'relative without base dir' => 'images/logo.png',
    'not an image' => 'data:text/plain;base64,aGVsbG8=',
    'svg' => 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22/%3E',
    'broken base64' => 'data:image/png;base64,@@@',
    'javascript' => 'javascript:alert(1)',
]);

it('fetches remote images only through the application callback', function () {
    $requested = [];
    $converter = converter()->withRemoteImages(function (string $url) use (&$requested): ?string {
        $requested[] = $url;

        return str_contains($url, 'allowed') ? TestImage::png(5, 5) : null;
    });

    $docx = docx('<p><img src="https://cdn.example.com/allowed.png"><img src="//cdn.example.com/allowed-too.png"><img src="http://internal/denied.png" alt="denied"></p>', $converter);

    expect($requested)->toBe(['https://cdn.example.com/allowed.png', 'https://cdn.example.com/allowed-too.png', 'http://internal/denied.png'])
        ->and($docx->count('//w:drawing'))->toBe(2)
        ->and($docx->paragraphTexts())->toBe(['denied']);
});

it('reads local images only inside the configured directory', function () {
    $directory = sys_get_temp_dir() . '/kovami-images-' . bin2hex(random_bytes(4));
    mkdir($directory . '/nested', recursive: true);
    file_put_contents($directory . '/nested/pic.png', TestImage::png(3, 3));
    file_put_contents(dirname($directory) . '/kovami-outside.png', TestImage::png(3, 3));

    try {
        $converter = converter()->withLocalImageBaseDir($directory);
        $docx = docx('<p><img src="/nested/pic.png?v=2"><img src="nested/pic.png"><img src="../kovami-outside.png" alt="blocked"></p>', $converter);

        expect($docx->count('//w:drawing'))->toBe(2)
            ->and($docx->paragraphTexts())->toBe(['blocked']);
    } finally {
        @unlink($directory . '/nested/pic.png');
        @rmdir($directory . '/nested');
        @rmdir($directory);
        @unlink(dirname($directory) . '/kovami-outside.png');
    }
});

it('accepts a custom image resolver', function () {
    $resolver = new class implements ImageSourceResolver {
        public function resolve(string $source): ?string
        {
            return $source === 'storage://avatar' ? TestImage::png(7, 7) : null;
        }
    };

    $docx = docx('<p><img src="storage://avatar"></p>', converter()->withImageResolver($resolver));

    expect(imageSizePx($docx))->toBe([7, 7]);
});

it('renders SunEditor image components centered with their caption', function () {
    $html = '<div class="se-component se-image-container __se__float-center" contenteditable="false">'
        . '<figure style="margin: auto; width: 100px;"><img src="' . TestImage::pngDataUri(100, 50) . '" alt="" data-rotate="" data-proportion="true" data-size="100px,auto" data-align="center" style="width: 100px; height: auto;">'
        . '<figcaption>Figure 1</figcaption></figure></div>';

    $docx = docx($html);
    $imageParagraph = $docx->first('//w:p[.//w:drawing]');

    expect($docx->val('w:pPr/w:jc', $imageParagraph))->toBe('center')
        ->and($docx->val('w:pPr/w:pStyle', $docx->paragraph('Figure 1')))->toBe('Caption')
        ->and(imageSizePx($docx))->toBe([100, 50]);
});

it('keeps images inside links clickable', function () {
    $docx = docx('<p><a href="https://example.com"><img src="' . TestImage::pngDataUri(5, 5) . '"></a></p>');

    expect($docx->count('//w:hyperlink//w:drawing'))->toBe(1);
});

it('renders embedded media as links to their source', function () {
    $docx = docx('<div class="se-component se-video-container"><figure><iframe src="https://www.youtube.com/embed/abc"></iframe></figure></div><video><source src="https://example.com/v.mp4"></video>');

    expect($docx->count('//w:hyperlink'))->toBe(2)
        ->and($docx->paragraphTexts())->toBe(['https://www.youtube.com/embed/abc', 'https://example.com/v.mp4']);
});

it('does not reserve the aspect-ratio space of an embedded player', function () {
    $docx = docx('<div class="se-component se-video-container"><figure style="width: 100%; height: 56.25%; padding-bottom: 56.25%;"><iframe src="https://www.youtube.com/embed/abc"></iframe></figure></div><p>after</p>');
    $spacing = $docx->first('w:pPr/w:spacing', $docx->paragraph('youtube'));

    expect((int) Docx::attr($spacing, 'after'))->toBe(150)
        ->and(Docx::attr($spacing, 'before'))->toBeNull();
});

it('keeps fixed padding around embedded players', function () {
    $spacing = docx('<div style="padding: 20pt 0; margin: 0"><iframe src="https://example.com/v"></iframe></div>')->first('//w:p/w:pPr/w:spacing');

    expect(Docx::attr($spacing, 'before'))->toBe('400')
        ->and(Docx::attr($spacing, 'after'))->toBe('400');
});

/** The first picture of HTML read with a profile, and the paragraph holding it. */
function pictureOf(string $html, ?Kovami\HtmlDocx\Editor $editor = null): array
{
    $converter = $editor === null ? HtmlDocx::plain(testOptions()) : HtmlDocx::for($editor, testOptions());
    $src = TestImage::pngDataUri(400, 200);

    foreach ($converter->fromHtml(str_replace('SRC', $src, $html))->document()->blocks as $block) {
        foreach ($block instanceof Kovami\HtmlDocx\Model\Paragraph ? $block->children : [] as $child) {
            if ($child instanceof Kovami\HtmlDocx\Model\ImageRun) {
                return [$child, $block->properties, testOptions()->page()->contentWidthTwips() * 635];
            }
        }
    }

    throw new RuntimeException('no picture');
}

it('lets text wrap around a floated picture, and keeps it floated through Word', function () {
    [$image] = pictureOf('<p><img src="SRC" width="100" style="float: right">Text beside it.</p>');
    $html = HtmlDocx::plain(testOptions())->fromDocx(HtmlDocx::plain(testOptions())->fromHtml('<p><img src="' . TestImage::pngDataUri(400, 200) . '" width="100" style="float: left">Text.</p>')->toDocx())->toHtml();

    expect($image->float)->toBe('right')
        ->and($html)->toContain('float: left;');
});

it('aligns a picture set apart by auto margins, as TinyMCE centres one', function () {
    [, $paragraph] = pictureOf('<p><img src="SRC" width="100" style="display: block; margin-left: auto; margin-right: auto"></p>');

    expect($paragraph->alignment)->toBe('center');
});

it('sizes and places CKEditor\'s pictures by their figure', function (string $classes, string $style, float $share, ?string $float, ?string $alignment) {
    [$image, $paragraph, $page] = pictureOf("<figure class=\"image {$classes}\" style=\"{$style}\"><img src=\"SRC\"></figure>", Kovami\HtmlDocx\Editor::CKEditor);

    expect($image->width / $page)->toEqualWithDelta($share, 0.01)
        ->and($image->float)->toBe($float);

    if ($float === null) {
        expect($paragraph->alignment)->toBe($alignment);
    }
})->with([
    'resized, centred' => ['image_resized', 'width: 25%', 0.25, null, 'center'],
    'block, aligned right' => ['image-style-block-align-right image_resized', 'width: 30%', 0.30, null, 'right'],
    'side' => ['image-style-side', '', 0.5, 'right', null],
    'wrapped on the left' => ['image-style-align-left image_resized', 'width: 40%', 0.40, 'left', null],
]);
