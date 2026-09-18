<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx;

use Closure;
use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\Css\StyleResolver;
use Kovami\HtmlDocx\Docx\Reader\DocxReader;
use Kovami\HtmlDocx\Docx\Writer\DocxWriter;
use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\Html\Reader\DocumentBuilder;
use Kovami\HtmlDocx\Html\Reader\HtmlDocument;
use Kovami\HtmlDocx\Html\Reader\ImageFactory;
use Kovami\HtmlDocx\Html\Reader\PropertyMapper;
use Kovami\HtmlDocx\Html\Writer\HtmlWriter;
use Kovami\HtmlDocx\Image\DataUriImageHandler;
use Kovami\HtmlDocx\Image\DefaultImageSourceResolver;
use Kovami\HtmlDocx\Image\ImageHandler;
use Kovami\HtmlDocx\Image\ImageInspector;
use Kovami\HtmlDocx\Image\ImageSourceResolver;
use Kovami\HtmlDocx\Model\Document;

/**
 * Converts rich-text editor HTML into DOCX and DOCX back into HTML.
 *
 *     $converter = new HtmlDocx(new Options(language: 'ru-RU'));
 *     $converter->htmlToDocxFile($html, storage_path('app/report.docx'));
 *     $html = $converter->docxFileToHtml(storage_path('app/report.docx'));
 *
 * Both directions meet in one document model: HTML → CSS cascade → model →
 * OOXML, and OOXML → model → HTML. Instances are immutable and safe to
 * reuse for any number of conversions.
 */
final readonly class HtmlDocx
{
    private ImageSourceResolver $imageResolver;

    private ImageHandler $imageHandler;

    /**
     * @param  ImageSourceResolver|null  $imageResolver  where `<img src>` is read from, converting to DOCX
     * @param  ImageHandler|null  $imageHandler  where pictures go, converting to HTML
     * @param  Closure(string): void|null  $warningHandler  receives a message for every piece of content that could not be converted
     */
    public function __construct(
        private Options $options = new Options(),
        ?ImageSourceResolver $imageResolver = null,
        ?ImageHandler $imageHandler = null,
        private ?Closure $warningHandler = null,
    ) {
        $this->imageResolver = $imageResolver ?? new DefaultImageSourceResolver();
        $this->imageHandler = $imageHandler ?? new DataUriImageHandler();
    }

    public function withOptions(Options $options): self
    {
        return new self($options, $this->imageResolver, $this->imageHandler, $this->warningHandler);
    }

    public function withImageResolver(ImageSourceResolver $imageResolver): self
    {
        return new self($this->options, $imageResolver, $this->imageHandler, $this->warningHandler);
    }

    /** Where the pictures of a DOCX document end up in the HTML; data URIs by default. */
    public function withImageHandler(ImageHandler $imageHandler): self
    {
        return new self($this->options, $this->imageResolver, $imageHandler, $this->warningHandler);
    }

    /**
     * @param  Closure(string): void|null  $warningHandler
     */
    public function withWarningHandler(?Closure $warningHandler): self
    {
        return new self($this->options, $this->imageResolver, $this->imageHandler, $warningHandler);
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

    /** Builds the document model from HTML without serializing it. */
    public function readHtml(string $html, ?PageLayout $pageLayout = null): Document
    {
        $document = HtmlDocument::fromString($html);

        $resolver = StyleResolver::fromStylesheets(
            $this->options->defaultStylesheet,
            $this->options->extraStylesheet,
            $document->stylesheets(),
        );

        $builder = new DocumentBuilder(
            $resolver,
            new PropertyMapper(),
            new ImageFactory($this->imageResolver, new ImageInspector()),
            $this->options,
        );

        return $builder->build($document, $pageLayout ?? $this->options->page());
    }

    /** Builds the document model from the bytes of a .docx package. */
    public function readDocx(string $bytes): Document
    {
        return (new DocxReader(
            $this->options->includeHiddenText,
            $this->options->maxDocxEntryBytes,
            $this->options->maxDocxTotalBytes,
            $this->warningHandler,
            $this->options->includeHeadersFooters,
            $this->options->includeComments,
        ))->read($bytes);
    }

    public function writeDocx(Document $document): string
    {
        $stream = self::memoryStream();

        try {
            $this->writeDocxToStream($document, $stream);
            rewind($stream);

            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    public function writeDocxToStream(Document $document, mixed $stream): void
    {
        if (! is_resource($stream)) {
            throw HtmlDocxException::writerFailure('the output is not a writable stream');
        }

        (new DocxWriter())->write($document, $stream);
    }

    public function writeHtml(Document $document): string
    {
        return (new HtmlWriter($document, $this->options, $this->imageHandler, $this->warningHandler))->toHtml();
    }

    public function htmlToDocx(string $html, ?PageLayout $pageLayout = null): string
    {
        return $this->writeDocx($this->readHtml($html, $pageLayout));
    }

    /**
     * @param  resource  $stream
     */
    public function htmlToDocxStream(string $html, mixed $stream, ?PageLayout $pageLayout = null): void
    {
        $this->writeDocxToStream($this->readHtml($html, $pageLayout), $stream);
    }

    /** @return string the path written to */
    public function htmlToDocxFile(string $html, string $path, ?PageLayout $pageLayout = null): string
    {
        $document = $this->readHtml($html, $pageLayout);
        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $stream = fopen($path, 'wb');
        } finally {
            restore_error_handler();
        }

        if ($stream === false) {
            throw HtmlDocxException::writerFailure($error ?? "could not open {$path} for writing");
        }

        try {
            $this->writeDocxToStream($document, $stream);
        } finally {
            fclose($stream);
        }

        return $path;
    }

    public function docxToHtml(string $bytes): string
    {
        return $this->writeHtml($this->readDocx($bytes));
    }

    public function docxFileToHtml(string $path): string
    {
        return $this->docxToHtml(self::contents($path));
    }

    /**
     * @param  resource  $stream
     */
    public function docxStreamToHtml(mixed $stream): string
    {
        if (! is_resource($stream)) {
            throw HtmlDocxException::unreadableFile('the input is not a readable stream');
        }

        $bytes = stream_get_contents($stream);

        if ($bytes === false) {
            throw HtmlDocxException::unreadableFile('the input stream could not be read');
        }

        return $this->docxToHtml($bytes);
    }

    private static function contents(string $path): string
    {
        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            $bytes = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        if ($bytes === false) {
            throw HtmlDocxException::unreadableFile($error ?? "could not open {$path} for reading");
        }

        return $bytes;
    }

    /**
     * @return resource
     */
    private static function memoryStream(): mixed
    {
        $stream = fopen('php://memory', 'w+b');

        if ($stream === false) {
            throw HtmlDocxException::writerFailure('could not open an in-memory stream');
        }

        return $stream;
    }
}
