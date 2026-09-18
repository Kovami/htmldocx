# kovami/htmldocx

**English** · [Русский](README.ru.md)

Convert HTML into Word documents, and Word documents back into HTML — with one library, no runtime dependencies, and no Word, LibreOffice or headless browser anywhere in sight.

```php
$converter = new Kovami\HtmlDocx\HtmlDocx;

$docx = $converter->htmlToDocx('<h1>Report</h1><p>Hello <b>world</b></p>');
$html = $converter->docxToHtml($docx);
```

Both directions meet in the middle, in one document model:

```
HTML ──parse──► CSS cascade ──► Document model ──► OOXML ──► .docx
.docx ──► OOXML ──resolve styles──► Document model ──► HTML
```

That shared model is why a document can go to Word and come back looking like itself — see [Round trips](#round-trips).

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Options](#options)
- [Images](#images)
- [Warnings](#warnings)
- [Wiring it into an application](#wiring-it-into-an-application)
- [What it converts](#what-it-converts)
- [What it does not do](#what-it-does-not-do)
- [Round trips](#round-trips)
- [Safety](#safety)
- [Contributing](#contributing)
- [License](#license)

## Requirements

- PHP 8.4 or newer (the HTML side uses PHP's spec-compliant HTML5 parser, `Dom\HTMLDocument`)
- `ext-dom`, `ext-mbstring`, `ext-xmlwriter`, `ext-zlib`
- `ext-gd` is optional: with it, WebP images are converted to PNG, which Word can display; without it they are skipped
- No Composer dependencies at runtime

## Installation

```bash
composer require kovami/htmldocx
```

## Usage

### HTML to DOCX

```php
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;
use Kovami\HtmlDocx\Config\PageLayout;

$converter = new HtmlDocx(new Options(
    fontFamily: 'Calibri',
    fontSizePt: 11.0,
    language: 'en-GB',
    pageLayout: PageLayout::a4Portrait(marginCm: 2.0),
));

$bytes = $converter->htmlToDocx($html);            // the .docx as a string
$converter->htmlToDocxFile($html, '/tmp/out.docx'); // straight to a file
$converter->htmlToDocxStream($html, $stream);       // straight to a stream
```

Paper size has no HTML equivalent, so it comes from configuration — either once through `Options`, or per call:

```php
$converter->htmlToDocx($html, PageLayout::a4Landscape());
```

### DOCX to HTML

```php
$html = $converter->docxToHtml($bytes);
$html = $converter->docxFileToHtml('/tmp/report.docx');
$html = $converter->docxStreamToHtml($stream);
```

The result is a fragment by default (no `<html>`, no `<body>`), ready to drop into a page or an editor. Ask for a whole document when you need one:

```php
$html = $converter->withOptions(new Options(fullHtmlDocument: true))->docxFileToHtml('/tmp/report.docx');
```

### Working with the model directly

Every conversion is a read and a write, and you can stop in between — to inspect a document, to change it, or to build one yourself:

```php
$document = $converter->readDocx($bytes);   // .docx  → Kovami\HtmlDocx\Model\Document
$document = $converter->readHtml($html);    // HTML   → Document

$converter->writeDocx($document);           // Document → .docx bytes
$converter->writeDocxToStream($document, $stream);
$converter->writeHtml($document);           // Document → HTML
```

The model is plain PHP objects: `Document` holds a list of `Paragraph` and `Table` blocks, paragraphs hold `TextRun`, `ImageRun`, `Hyperlink`, `Formula`, `NoteReference`, `BreakRun`, `TabRun` and `Bookmark` inlines, and every one of them carries fully resolved formatting — no style lookups left to do.

### Instances are immutable

`HtmlDocx` is safe to build once and reuse for any number of documents. The `with*` methods return a new instance:

```php
$converter = (new HtmlDocx)
    ->withOptions(new Options(language: 'de-DE'))
    ->withLocalImageBaseDir(__DIR__.'/public')
    ->withWarningHandler(static fn (string $message) => Log::info($message));
```

## Options

One `Options` object configures both directions. The first block describes the environment a document lives in — the font, size and colour text falls back to, and the stylesheet HTML is interpreted against and written relative to.

| Option | Default | Applies to | What it does |
| --- | --- | --- | --- |
| `fontFamily` | `Calibri` | both | Default font of the document |
| `fontSizePt` | `11.0` | both | Default size, in points |
| `textColor` | `000000` | both | Default colour, `RRGGBB` |
| `language` | `null` | HTML → DOCX | Proofing language written into the document, e.g. `ru-RU` |
| `pageLayout` | A4 portrait | HTML → DOCX | Paper size, orientation and margins |
| `defaultStylesheet` | built-in | both | Replaces the built-in browser/editor defaults |
| `extraStylesheet` | `''` | both | CSS applied above the defaults and below the document's own `<style>` |
| `title`, `author` | `null` | HTML → DOCX | Document properties (the title falls back to `<title>`) |
| `createdAt` | now | HTML → DOCX | Fixed timestamp, for byte-reproducible output |
| `cssUnit` | `px` | DOCX → HTML | Unit for lengths and font sizes: `px` or `pt` |
| `keepDocumentDefaults` | `true` | DOCX → HTML | Spell out the document's own base formatting where it differs from the environment above, so the HTML looks like the document. `false` adopts the environment's defaults and keeps only deliberate formatting |
| `includeHiddenText` | `false` | DOCX → HTML | Keep text Word marks as hidden |
| `includeHeadersFooters` | `true` | DOCX → HTML | Keep page headers and footers |
| `includeComments` | `true` | DOCX → HTML | Keep reviewers' comments and the text they are anchored to |
| `idPrefix` | `''` | DOCX → HTML | Prepended to generated ids (bookmarks, notes) so several documents can share one page |
| `fullHtmlDocument` | `false` | DOCX → HTML | Wrap the fragment in `<html><head>…<body>` |
| `maxDocxEntryBytes` | 128 MB | DOCX → HTML | Decompressed size limit of one package part |
| `maxDocxTotalBytes` | 512 MB | DOCX → HTML | Decompressed size limit of the whole package |

`Options` is immutable; `with()` copies it with changes:

```php
$options = (new Options(fontFamily: 'Georgia'))->with(['cssUnit' => 'pt', 'idPrefix' => 'doc1-']);
```

## Images

**Into a document.** `<img src>` values are resolved by an `ImageSourceResolver`. Out of the box, `data:` URIs work and nothing else does — a converter does not fetch from the network or read the disk unless you say so:

```php
$converter = (new HtmlDocx)
    // relative paths are resolved inside this directory only, never above it
    ->withLocalImageBaseDir(public_path())
    // http(s) images go through your fetcher, where your allowlist and timeouts live
    ->withRemoteImages(fn (string $url): ?string => Http::timeout(5)->get($url)->body());
```

Implement `ImageSourceResolver` yourself to load from object storage, a CDN or a database. PNG, JPEG, GIF, BMP and TIFF are embedded as they are; WebP is converted to PNG when `ext-gd` is available; anything else is left out (with a warning). Images are scaled to the CSS `width`/`height` you give them, keep their aspect ratio when only one is set, and are capped at the text column width.

**Out of a document.** Pictures found in a `.docx` are placed by an `ImageHandler`. The default embeds them as data URIs; hand them to your own storage instead:

```php
use Kovami\HtmlDocx\Image\CallbackImageHandler;
use Kovami\HtmlDocx\Model\ImageData;

$converter = (new HtmlDocx)->withImageHandler(new CallbackImageHandler(
    function (ImageData $image, string $description): ?string {
        Storage::put($path = "media/{$image->hash()}.{$image->extension}", $image->bytes);

        return Storage::url($path);   // or null to leave the picture out
    },
));
```

## Warnings

Anything a document carries but the conversion cannot represent is reported rather than silently dropped:

```php
$converter = (new HtmlDocx)->withWarningHandler(function (string $message): void {
    Log::notice("htmldocx: {$message}");
});
```

You will hear about embedded alternative-format content, pictures whose data is missing or unreadable, and package parts that could not be parsed.

## Wiring it into an application

The library knows nothing about frameworks: it is a handful of plain classes with no dependencies and no global state, so wiring is a few lines wherever your application builds its services. A converter is immutable and safe to share, so build one and reuse it.

```php
// Laravel — app/Providers/AppServiceProvider.php
use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

$this->app->singleton(HtmlDocx::class, fn (): HtmlDocx => (new HtmlDocx(new Options(
    fontFamily: config('documents.font_family', 'Calibri'),
    fontSizePt: (float) config('documents.font_size_pt', 11),
    language: config('app.locale'),
    pageLayout: PageLayout::fromArray(config('documents.page', [])),
)))
    ->withLocalImageBaseDir(public_path())
    ->withWarningHandler(static fn (string $message) => Log::notice("htmldocx: {$message}")));
```

`PageLayout::fromArray()` takes the shape configuration files tend to have, so paper can live in your own config:

```php
PageLayout::fromArray(['size' => 'a4', 'orientation' => 'landscape', 'margin_cm' => 1.5]);
```

Symfony's autowiring needs nothing at all — every constructor argument has a default — and a factory gives you the same control as above:

```yaml
# config/services.yaml
Kovami\HtmlDocx\HtmlDocx:
    factory: ['App\Documents\ConverterFactory', 'create']
```

## What it converts

### HTML → DOCX

**CSS is actually cascaded.** Inline `style` attributes, `<style>` blocks in the document, the configured stylesheets and presentational attributes (`align`, `width`, `bgcolor`, `border`…) are resolved the way a browser resolves them: full selector support through the native DOM (combinators, attribute selectors, structural pseudo-classes), specificity, `!important`, inheritance and shorthand expansion (`margin`, `padding`, `border`, `background`, `font`, `text-decoration`, `list-style`, logical properties). Lengths in `px`, `pt`, `pc`, `in`, `cm`, `mm`, `q`, `em`, `rem`, `ex`, `ch` and `%` are understood, and colours as hex, `rgb()`, `rgba()`, `hsl()` and named colours.

| Content | How it arrives in Word |
| --- | --- |
| Paragraphs, `div`, `section`, `article`, `blockquote`, `pre`, `address`, … | Paragraphs, with margins as spacing, padding and borders as paragraph borders, background as shading |
| `h1`–`h6` | Real *Heading 1–6* styles, so the navigation pane and a table of contents work |
| `b`, `strong`, `i`, `em`, `u`, `s`, `del`, `sup`, `sub`, `mark`, `small`, `code`, `q`, … | Character formatting: weight, style, underline (including its style), strike-through, position, colour, highlight, font, size, letter spacing, caps and small caps |
| `ul`, `ol`, nested lists | Real Word numbering: bullet shapes per depth, `start`, `value`, `reversed`, and `list-style-type` — including counter styles Word has no format for, which are written out as literal markers |
| `table` with `thead`/`tbody`/`tfoot`, `caption`, `colgroup` | Tables with a column grid, repeating header rows, `colspan`/`rowspan`, per-cell borders, shading, padding, vertical alignment and widths (fixed or percentage) |
| `img` | Embedded pictures, scaled to their CSS size |
| `a href` | Hyperlinks; `href="#id"` becomes a bookmark link, and only ids something links to become bookmarks |
| `br`, `hr`, tabs | Line breaks, a bordered rule paragraph, tab characters |
| `page-break-before` / `page-break-after` | Page breaks |
| `<span class="katex" data-exp="…">` | **Office Math** — the LaTeX is translated into an equation Word lays out and can edit |
| `<ol class="se-footnotes">` / `se-endnotes` and the superscript marks linking into them | Real footnotes and endnotes, so what this library wrote as HTML goes back as notes |
| `<div class="se-header">` / `se-footer`, with `data-type="first"` or `"even"` | Page headers and footers, including a different first page and different even pages |
| `<span class="se-field" data-field="PAGE">` / `NUMPAGES` | Page number and page count fields Word keeps up to date |
| `<span class="se-comment" data-comment="1 2">` and `<ol class="se-comments">` | Comments on the marked text — ranges may cross paragraphs and overlap — with author, initials, date, replies and the resolved state |
| `iframe`, `video`, `audio`, `embed`, `object` | A link to the source, since Word cannot play them |
| `input` | Checkboxes and radios as ☑ / ☐, other controls as their value |
| `dir="rtl"`, `direction` | Right-to-left paragraphs |
| `<title>` | Document title |

Whitespace follows the CSS rules — collapsing, `pre`, `pre-wrap`, `pre-line`, `nowrap` — and `text-transform`, `first-line` indentation, `line-height` (as a multiple or a fixed height) and `text-align` (including `justify`) all survive. Malformed markup is handled the way browsers handle it, because it is parsed by the same kind of parser.

### DOCX → HTML

Formatting is resolved the way Word resolves it — document defaults, style inheritance chains, numbering levels, table styles and direct formatting, each layer in the right order — and then written as HTML that shows the same thing without needing a stylesheet.

| Content | How it arrives in HTML |
| --- | --- |
| Paragraphs and their styles | `<p>`, or `<h1>`–`<h6>` when the paragraph has an outline level, with spacing, indentation, alignment, borders, shading and line height as inline styles |
| Character formatting | `<strong>`, `<em>`, `<u>`, `<del>`, `<sup>`, `<sub>` inside a `<span>` carrying font, size, colour and highlight |
| Theme fonts and colours | Resolved through `theme1.xml`, including tints and shades |
| Numbering | Real `<ul>`/`<ol>` nesting, `start` and `value`, CSS counter styles where one fits, and the exact marker text where none does (`1.2.`, `A)`, `α.`) |
| Tables | `<table>` with a `<colgroup>`, `<thead>`, `rowspan`/`colspan`, and the formatting the table style implies — header rows, banded rows, first/last column, corner cells |
| Pictures | `<img>` with its size; a picture standing alone in a paragraph is wrapped in a `<div><figure>` component so editors can select and align it, and a floating one keeps its `float` |
| Footnotes and endnotes | Superscript links to a numbered list at the end, with links back to the reference |
| Headers and footers | `<div class="se-header">` before the content and `<div class="se-footer">` after it; the first-page and even-page variants carry `data-type`. They are the ones the last section shows, following Word's inheritance between sections |
| Page numbers | `<span class="se-field" data-field="PAGE">` (or `NUMPAGES`) around the value Word last showed |
| Comments | The text each one is about wrapped in `<span class="se-comment" data-comment="…">` (every comment covering it, space-separated), and an `<ol class="se-comments">` after the notes: one `li` per comment with `data-author`, `data-initials`, `data-date`, `data-parent` for a reply and `data-resolved` |
| Hyperlinks and bookmarks | `<a href>` and `<a id>`, including cross-reference and `HYPERLINK` fields |
| Fields | Their current result (dates, references, form checkboxes); page numbers stay fields |
| Tracked changes | Shown accepted: insertions kept, deletions and moved-away text dropped |
| Office Math | `<span class="katex" data-exp="…">` carrying the equation as LaTeX |
| Text boxes and shapes | Their text, as paragraphs after the one they hang on |
| Symbols and dingbats | Mapped to real Unicode characters |
| Line and page breaks, tabs | `<br>`, `page-break-before`, preserved whitespace |
| Section geometry | Page size and margins, as the width the content is laid out against |

Content controls, smart tags and alternative-content wrappers are transparent — what is inside them is read normally.

## What it does not do

Being explicit is more useful than a long feature list, so:

- **Only the last section's headers and footers** are kept: a document whose sections have different ones comes out with those of the final section.
- **Tracked changes are flattened**, not preserved as revisions: you get the document as if every change were accepted.
- **Charts, SmartArt and OLE objects** come through only as the picture Word stores alongside them; the live object is lost.
- **`w:altChunk`** (embedded HTML or RTF inside a `.docx`) is skipped, with a warning.
- **Macros, form data and content-control bindings** are not carried over.
- **Layout is Word's, not the browser's.** Floats, flexbox, grid, multi-column, absolute positioning and overlapping boxes have no equivalent in a word-processing document: block boxes become paragraphs, and their indentation, spacing, borders and backgrounds are kept. Images from HTML are placed inline.
- **External stylesheets are not fetched.** Only `<style>` blocks in the document and the stylesheets you configure take part in the cascade. `@media` and `@supports` blocks are read when they apply to a printed document; a `<style media="screen">` is skipped.
- **No scripting, SVG or canvas.** `script`, `svg`, `canvas`, `select`, `textarea` and `button` are ignored.
- **The old binary `.doc`**, RTF, ODT and PDF are out of scope, in both directions.
- **Fonts are not embedded**, and text is not measured: pagination is Word's business, so page counts and where a page ends are unknown here.
- **HTML output is a fragment of body content**, not a paginated view of the document.

## Round trips

The HTML writer only writes what differs from the stylesheet the HTML will be read against, and the HTML reader resolves that same stylesheet — so a document survives the loop instead of accumulating markup. Concretely:

- `HTML → DOCX → HTML` is stable after one pass: convert the result again and you get the same bytes.
- `DOCX → HTML → DOCX` keeps the structure, the formatting, the numbering, the tables, the pictures, the links, the notes, the headers and footers and the comments.
- Word's own view is the referee: a document written here opens in Word without a repair prompt, and reading a Word document back, re-saving it in Word and reading it again gives the same result.

Vertical spacing deserves a word, because it is the one place the two models genuinely disagree: Word adds the space after one paragraph to the space before the next, while CSS collapses the two into the larger one. The writer inverts that arithmetic (each paragraph's top margin carries its own spacing plus the previous paragraph's), so the gaps you see in the browser are the gaps Word will print.

## Safety

Documents arrive from users, so the reader treats them as hostile input:

- Decompressed size is capped per part and per package (`maxDocxEntryBytes`, `maxDocxTotalBytes`), the number of entries is limited, and inflation stops at the size an entry declares — a zip bomb runs out of room instead of memory.
- A part that declares a `DOCTYPE` is rejected outright, since OOXML never has one: entity-expansion attacks have nowhere to start, and the parser has no network access.
- Relationship targets that point outside the package are treated as missing.
- `javascript:`, `vbscript:`, `data:` and `file:` URLs are dropped from hyperlinks in both directions (an image `src` is a different matter: `data:` is exactly how pictures travel).
- Local images are read only below a directory you name, remote images only through a fetcher you write.
- Everything a document cannot be trusted to contain — characters XML cannot carry, malformed parts, broken relationships — is cleaned or skipped rather than passed through.

Errors that stop a conversion are thrown as `Kovami\HtmlDocx\Exceptions\HtmlDocxException`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT — see [LICENSE](LICENSE). Third-party code is listed in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
