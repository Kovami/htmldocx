<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx;

use InvalidArgumentException;
use Closure;
use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\Image\DataUriImageHandler;
use Kovami\HtmlDocx\Image\DefaultImageSourceResolver;
use Kovami\HtmlDocx\Image\ImageHandler;
use Kovami\HtmlDocx\Image\ImageSourceResolver;
use Kovami\HtmlDocx\Model\Document;

/**
 * Converts HTML into DOCX and DOCX back into HTML.
 *
 *     // Plain HTML that looks the same in any browser
 *     $html = HtmlDocx::plain()->fromDocxFile('report.docx')->toHtml();
 *
 *     // HTML tailored to an editor, and back to Word
 *     $html = HtmlDocx::for(Editor::SunEditor)->fromDocxFile('report.docx')->toHtml();
 *     HtmlDocx::for('tinymce')->fromHtml($html)->saveDocx('report.docx');
 *
 * Pick the flavour of HTML with {@see self::plain()} or {@see self::for()},
 * adjust it with the `with…()` methods, then start a conversion with one of
 * the `from…()` methods. Instances are immutable and safe to reuse.
 */
final readonly class HtmlDocx
{
    /**
     * @param  Closure(string): void|null  $warningHandler
     */
    private function __construct(
        private ?Editor $editor,
        private Options $options,
        private ImageSourceResolver $imageResolver,
        private ImageHandler $imageHandler,
        private ?Closure $warningHandler,
    ) {}

    /** Plain HTML, tied to no editor, that looks the same wherever it is shown. */
    public static function plain(?Options $options = null): self
    {
        return self::create(null, $options);
    }

    /**
     * HTML tailored to a rich-text editor: its conventions for pictures,
     * formulas and the parts of a document plain HTML has no element for.
     *
     * @param  Editor|string  $editor  an {@see Editor}, or its name in any case ("SunEditor", "tinymce")
     */
    public static function for(Editor|string $editor, ?Options $options = null): self
    {
        return self::create($editor instanceof Editor ? $editor : Editor::fromName($editor), $options);
    }

    /** The editor the HTML is written for; null for plain HTML. */
    public function editor(): ?Editor
    {
        return $this->editor;
    }

    public function withOptions(Options $options): self
    {
        return new self($this->editor, $options, $this->imageResolver, $this->imageHandler, $this->warningHandler);
    }

    /**
     * The same converter with some options changed, named as in Options:
     * `$converter->with(includeImages: true)->fromDocx($bytes)`.
     */
    public function with(mixed ...$changes): self
    {
        $named = array_filter($changes, static fn(int|string $name): bool => is_string($name), ARRAY_FILTER_USE_KEY);

        if (count($named) !== count($changes)) {
            throw new InvalidArgumentException('Name the options to change, e.g. with(includeImages: false).');
        }

        return $this->withOptions($this->options->with($named));
    }

    /** Where `<img src>` is read from, converting to DOCX. */
    public function withImageResolver(ImageSourceResolver $imageResolver): self
    {
        return new self($this->editor, $this->options, $imageResolver, $this->imageHandler, $this->warningHandler);
    }

    /** Where the pictures of a DOCX document end up in the HTML; data URIs by default. */
    public function withImageHandler(ImageHandler $imageHandler): self
    {
        return new self($this->editor, $this->options, $this->imageResolver, $imageHandler, $this->warningHandler);
    }

    /**
     * Receives a message for every piece of content that could not be converted.
     *
     * @param  Closure(string): void|null  $warningHandler
     */
    public function withWarningHandler(?Closure $warningHandler): self
    {
        return new self($this->editor, $this->options, $this->imageResolver, $this->imageHandler, $warningHandler);
    }

    /**
     * Enables http(s) images through an application-controlled fetcher
     * (apply your own allowlist, timeouts and size limits there).
     *
     * @param  Closure(string): ?string  $fetcher
     */
    public function withRemoteImages(Closure $fetcher): self
    {
        $current = $this->imageResolver instanceof DefaultImageSourceResolver ? $this->imageResolver : new DefaultImageSourceResolver();

        return $this->withImageResolver(new DefaultImageSourceResolver($fetcher, $current->localBaseDirectory));
    }

    /** Resolves relative `<img src>` paths inside the given directory only. */
    public function withLocalImageBaseDir(string $directory): self
    {
        $current = $this->imageResolver instanceof DefaultImageSourceResolver ? $this->imageResolver : new DefaultImageSourceResolver();

        return $this->withImageResolver(new DefaultImageSourceResolver($current->remoteFetcher, $directory));
    }

    /** Reads a .docx package from its bytes. */
    public function fromDocx(string $bytes): Conversion
    {
        $engine = $this->engine();

        return new Conversion($engine->readDocx($bytes), $engine);
    }

    public function fromDocxFile(string $path): Conversion
    {
        return $this->fromDocx(Files::read($path));
    }

    /**
     * @param  resource  $stream
     */
    public function fromDocxStream(mixed $stream): Conversion
    {
        return $this->fromDocx(Files::readStream($stream));
    }

    /**
     * Reads HTML — a fragment or a whole document — laid out on the given
     * page, or on the one the options name.
     */
    public function fromHtml(string $html, ?PageLayout $pageLayout = null): Conversion
    {
        $engine = $this->engine();

        return new Conversion($engine->readHtml($html, $pageLayout), $engine);
    }

    public function fromHtmlFile(string $path, ?PageLayout $pageLayout = null): Conversion
    {
        return $this->fromHtml(Files::read($path), $pageLayout);
    }

    /** Starts from a document model built or changed by hand. */
    public function fromDocument(Document $document): Conversion
    {
        return new Conversion(FeatureFilter::apply($document, $this->options), $this->engine());
    }

    private static function create(?Editor $editor, ?Options $options): self
    {
        return new self($editor, $options ?? new Options(), new DefaultImageSourceResolver(), new DataUriImageHandler(), null);
    }

    private function engine(): Engine
    {
        return new Engine($this->editor, $this->options, $this->imageResolver, $this->imageHandler, $this->warningHandler);
    }
}
