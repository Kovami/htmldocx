<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx;

use DateTimeImmutable;
use InvalidArgumentException;
use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\Css\DefaultStylesheet;

final readonly class Options
{
    /**
     * The editor environment (font, size, colour, stylesheets) is shared by
     * both directions: HTML is read against it, and HTML is written relative
     * to it, so a document survives HTML → DOCX → HTML unchanged.
     *
     * @param  string  $textColor  RRGGBB
     * @param  string|null  $language  BCP 47 tag used for proofing, e.g. "ru-RU"
     * @param  string|null  $defaultStylesheet  replaces the built-in defaults: SunEditor's and CKEditor's
     *                                          content CSS for their profiles, a browser's for the
     *                                          others and plain HTML
     * @param  string  $extraStylesheet  applied above the defaults and below the document's own CSS
     * @param  DateTimeImmutable|null  $createdAt  fixed timestamp for reproducible output; defaults to now
     * @param  string  $cssUnit  DOCX → HTML: "px" (SunEditor's unit) or "pt" for lengths and font sizes
     * @param  bool  $includeHiddenText  DOCX → HTML: keep text formatted as hidden
     * @param  bool  $includeHeadersFooters  DOCX → HTML: keep page headers and footers
     * @param  bool  $includeComments  DOCX → HTML: keep reviewers' comments and the text they are anchored to
     * @param  string  $idPrefix  DOCX → HTML: prepended to generated ids (bookmarks, notes) to avoid collisions
     * @param  bool  $fullHtmlDocument  DOCX → HTML: wrap the content in <html><head>…<body> instead of returning a fragment
     * @param  int  $maxDocxEntryBytes  DOCX → HTML: decompressed size limit of one package part
     * @param  int  $maxDocxTotalBytes  DOCX → HTML: decompressed size limit of the whole package
     */
    public function __construct(
        public string $fontFamily = 'Calibri',
        public float $fontSizePt = 11.0,
        public string $textColor = '000000',
        public ?string $language = null,
        public ?PageLayout $pageLayout = null,
        public ?string $defaultStylesheet = null,
        public string $extraStylesheet = '',
        public ?string $title = null,
        public ?string $author = null,
        public ?DateTimeImmutable $createdAt = null,
        public string $cssUnit = 'px',
        public bool $includeHiddenText = false,
        public string $idPrefix = '',
        public bool $fullHtmlDocument = false,
        public int $maxDocxEntryBytes = 128 * 1024 * 1024,
        public int $maxDocxTotalBytes = 512 * 1024 * 1024,
        public bool $includeHeadersFooters = true,
        public bool $includeComments = true,
    ) {
        if (! in_array($cssUnit, ['px', 'pt'], true)) {
            throw new InvalidArgumentException("Unsupported CSS unit \"{$cssUnit}\"; use \"px\" or \"pt\".");
        }
    }

    /**
     * @param  array<string, mixed>  $changes  constructor arguments to replace
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    /** The defaults HTML is read and written against, for an editor or (null) for plain HTML. */
    public function stylesheet(?Editor $editor): string
    {
        return $this->defaultStylesheet ?? match ($editor) {
            Editor::SunEditor => DefaultStylesheet::CSS,
            Editor::CKEditor => DefaultStylesheet::CKEDITOR,
            default => DefaultStylesheet::BROWSER,
        };
    }

    public function page(): PageLayout
    {
        return $this->pageLayout ?? PageLayout::a4Portrait();
    }
}
