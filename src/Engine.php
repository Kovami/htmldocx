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
use Kovami\HtmlDocx\Image\ImageHandler;
use Kovami\HtmlDocx\Image\ImageInspector;
use Kovami\HtmlDocx\Image\ImageSourceResolver;
use Kovami\HtmlDocx\Model\Document;

/**
 * Reads and writes both formats for one configuration. Both directions meet
 * in the document model: HTML → CSS cascade → model → OOXML, and OOXML →
 * model → HTML.
 *
 * @internal use {@see HtmlDocx}
 */
final readonly class Engine
{
    /**
     * @param  Editor|null  $editor  the editor the HTML is written for; null for plain HTML
     * @param  Closure(string): void|null  $warningHandler
     */
    public function __construct(
        public ?Editor $editor,
        public Options $options,
        public ImageSourceResolver $imageResolver,
        public ImageHandler $imageHandler,
        public ?Closure $warningHandler,
    ) {}

    public function readHtml(string $html, ?PageLayout $pageLayout = null): Document
    {
        $document = HtmlDocument::fromString($html);

        $resolver = StyleResolver::fromStylesheets(
            $this->options->stylesheet($this->editor),
            $this->options->extraStylesheet,
            $document->stylesheets(),
        );

        $builder = new DocumentBuilder(
            $resolver,
            new PropertyMapper(),
            new ImageFactory($this->imageResolver, new ImageInspector()),
            $this->options,
        );

        return FeatureFilter::apply($builder->build($document, $pageLayout ?? $this->options->page()), $this->options);
    }

    public function readDocx(string $bytes): Document
    {
        return FeatureFilter::apply((new DocxReader(
            $this->options->includeHiddenText,
            $this->options->maxDocxEntryBytes,
            $this->options->maxDocxTotalBytes,
            $this->warningHandler,
            $this->options->includeHeadersFooters,
            $this->options->includeComments,
        ))->read($bytes), $this->options);
    }

    public function writeHtml(Document $document): string
    {
        return (new HtmlWriter($document, $this->editor, $this->options, $this->imageHandler, $this->warningHandler))->toHtml();
    }

    /**
     * @param  resource  $stream
     */
    public function writeDocx(Document $document, mixed $stream): void
    {
        if (! is_resource($stream)) {
            throw HtmlDocxException::writerFailure('the output is not a writable stream');
        }

        (new DocxWriter())->write($document, $stream);
    }
}
