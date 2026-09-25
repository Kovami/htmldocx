# kovami/htmldocx

[![CI](https://github.com/kovami/htmldocx/actions/workflows/ci.yml/badge.svg)](https://github.com/kovami/htmldocx/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/kovami/htmldocx)](https://packagist.org/packages/kovami/htmldocx)
[![PHP version](https://img.shields.io/packagist/dependency-v/kovami/htmldocx/php)](https://packagist.org/packages/kovami/htmldocx)
[![License](https://img.shields.io/github/license/kovami/htmldocx)](LICENSE)

**English** · [Русский](README.ru.md)

Convert HTML into Word documents, and Word documents back into HTML — with one library, no runtime dependencies, and no Word, LibreOffice or headless browser anywhere in sight.

```php
use Kovami\HtmlDocx\HtmlDocx;

$docx = HtmlDocx::plain()->fromHtml('<h1>Report</h1><p>Hello <b>world</b></p>')->toDocx();
$html = HtmlDocx::plain()->fromDocx($docx)->toHtml();
```

The HTML it writes is self-contained — every paragraph carries the font, size, colour, spacing and line height the document gives it — so a document looks like itself wherever it is shown. Pick the flavour your editor speaks when you have one:

```php
use Kovami\HtmlDocx\Editor;

$html = HtmlDocx::for(Editor::CKEditor)->fromDocxFile('report.docx')->toHtml();
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
- [Profiles](#profiles)
- [How close it looks](#how-close-it-looks)
- [Options](#options)
- [Images](#images)
- [Warnings](#warnings)
- [Wiring it into an application](#wiring-it-into-an-application)
- [What it converts](#what-it-converts)
- [What it does not do](#what-it-does-not-do)
- [Round trips](#round-trips)
- [Safety](#safety)
- [Upgrading from 1.x](#upgrading-from-1x)
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
use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

$converter = HtmlDocx::plain(new Options(
    language: 'en-GB',
    pageLayout: PageLayout::a4Portrait(marginCm: 2.0),
));

$bytes = $converter->fromHtml($html)->toDocx();            // the .docx as a string
$converter->fromHtml($html)->saveDocx('/tmp/out.docx');    // straight to a file
$converter->fromHtml($html)->streamDocx($stream);          // straight to a stream
$converter->fromHtmlFile('/tmp/in.html')->toDocx();        // from a file
```

Paper size has no HTML equivalent, so it comes from configuration — either once through `Options`, or per call:

```php
$converter->fromHtml($html, PageLayout::a4Landscape())->toDocx();
```

### DOCX to HTML

```php
$html = $converter->fromDocx($bytes)->toHtml();
$html = $converter->fromDocxFile('/tmp/report.docx')->toHtml();
$html = $converter->fromDocxStream($stream)->toHtml();
$converter->fromDocxFile('/tmp/report.docx')->saveHtml('/tmp/report.html');
```

The result is a fragment by default (no `<html>`, no `<body>`), ready to drop into a page or an editor. Ask for a whole document when you need one:

```php
$html = HtmlDocx::plain(new Options(fullHtmlDocument: true))->fromDocxFile('/tmp/report.docx')->toHtml();
```

A source (`fromHtml`, `fromHtmlFile`, `fromDocx`, `fromDocxFile`, `fromDocxStream`, `fromDocument`) returns a `Conversion`, which reads the document once and can be asked for anything: `toHtml()`, `saveHtml()`, `toDocx()`, `saveDocx()`, `streamDocx()` and `document()`.

### Working with the model directly

Every conversion is a read and a write, and you can stop in between — to inspect a document, to change it, or to build one yourself:

```php
$document = $converter->fromDocx($bytes)->document();   // .docx → Kovami\HtmlDocx\Model\Document
$document = $converter->fromHtml($html)->document();    // HTML  → Document

$converter->fromDocument($document)->toDocx();          // Document → .docx bytes
$converter->fromDocument($document)->toHtml();          // Document → HTML
```

The model is plain PHP objects: `Document` holds a list of `Paragraph` and `Table` blocks, paragraphs hold `TextRun`, `ImageRun`, `Hyperlink`, `Formula`, `NoteReference`, `BreakRun`, `TabRun` and `Bookmark` inlines, and every one of them carries fully resolved formatting — no style lookups left to do.

### Instances are immutable

A converter is safe to build once and reuse for any number of documents. The `with*` methods return a new instance:

```php
$converter = HtmlDocx::plain()
    ->withOptions(new Options(language: 'de-DE'))
    ->withLocalImageBaseDir(__DIR__.'/public')
    ->withWarningHandler(static fn (string $message) => Log::info($message));
```

## Profiles

Editors disagree about markup: one wants a `<figure>` around a picture, another a `<div>`; one keeps MathML, another its own span. A profile decides which flavour of HTML is written, and teaches the reader that editor's idioms and the typography it shows text in.

```php
HtmlDocx::plain();                   // standard HTML, for any editor or none
HtmlDocx::for(Editor::CKEditor);     // CKEditor 5
HtmlDocx::for(Editor::TinyMce);      // TinyMCE
HtmlDocx::for(Editor::TipTap);       // TipTap / ProseMirror
HtmlDocx::for(Editor::SunEditor);    // SunEditor 3
HtmlDocx::for('tinymce');            // by name, case-insensitive, aliases included
```

Whatever profile writes the HTML, **the reader understands all of them**: HTML from any of these editors (and from this library's 1.x output) is read correctly by every profile.

| | plain | CKEditor | TinyMCE | TipTap | SunEditor |
| --- | --- | --- | --- | --- | --- |
| Text, lists, tables, pictures, links | ✓ | ✓ | ✓ | ✓ | ✓ |
| Footnotes and endnotes | ✓ `<section class="footnotes">` | ✓ | ✓ | partly¹ | ✓ `se-footnotes` |
| Formulas | ✓ MathML | ✓ `\(…\)` | ✓ `\(…\)` | ✓ inline-math | ✓ KaTeX span |
| Headers and footers | — cut, with a warning | — | — | — | ✓ `se-header` / `se-footer` |
| Comments | — cut, with a warning | — | — | — | ✓ `se-comment` |
| Page number fields | — become plain text | — | — | — | ✓ `se-field` |

¹ The notes are written, but TipTap's schema keeps only their text: the section and the links back do not survive a round trip through it.

**Base typography.** Each editor shows text it has no formatting for in its own content stylesheet, and a profile assumes exactly that, so a document made in the editor reads in Word the way it looked: CKEditor's Helvetica at the browser's medium size with 1.5 line spacing, TinyMCE's system font stack with 1.4, SunEditor 3's Helvetica Neue 16px in #333 with 1.5 line spacing. Plain HTML assumes Calibri 11pt. TinyMCE's stack starts with "whatever this system calls its own font", which a DOCX cannot ask for: Word gets Segoe UI, which is what Windows shows, while macOS shows San Francisco with other metrics, so text runs longer or shorter there. If your application styles its editor differently — most do — or its users are on a Mac, say so:

```php
new Options(fontFamily: 'Times New Roman', fontSizePt: 12.0, textColor: '222222');
new Options(extraStylesheet: 'body { font-family: Georgia; } p { margin: 0 0 12px; }');
```

**Editor configuration.** Editors drop what their schema does not know: out of the box CKEditor, TipTap and SunEditor keep a quarter to a half of the formatting this library writes (TinyMCE nearly all of it). With the plugins and allow-lists below they keep 97.9–99.7%, and what they hand back prints at 96.1–97.3% of the ink Word puts on the page (see the bench below). The exact configurations the bench uses live in [`bench/fidelity/editors`](bench/fidelity/editors).

| Editor | What it needs |
| --- | --- |
| CKEditor 5 | The `GeneralHtmlSupport` plugin, allowing every element with its styles, classes and attributes: `htmlSupport: { allow: [{ name: /.*/, styles: true, classes: true, attributes: true }] }`, next to the table, list, image, link, block-quote and code-block plugins |
| TinyMCE | The `lists`, `advlist`, `table`, `link` and `image` plugins; it keeps the rest of the markup as it is |
| TipTap | `StarterKit` with `TableKit`, `TextStyleKit`, `TextAlign`, `Image` (`inline: true`, `allowBase64: true`), `Subscript`, `Superscript`, `Highlight`, `Mathematics` — and an extension that keeps `style` and `id` on blocks, pictures and scripts, and letter spacing on `textStyle` (`addGlobalAttributes`), which TipTap drops unless a node or mark declares them |
| SunEditor 3 | All of its plugins, KaTeX as `externalLibs: { katex: { src: katex } }`, `elementWhitelist: 'section\|colgroup\|col'`, `attributeWhitelist: { '*': 'style\|id\|role\|start\|value\|data-[^\\s]+' }`, and `strictMode` with `attrFilter: false` and `styleFilter: false` (its other filters stay on) |

## How close it looks

The repository carries a bench that answers the question with numbers instead of adjectives ([`bench/fidelity`](bench/fidelity), macOS with Microsoft Word):

- **DOCX → HTML**: Word prints the document, Chromium prints the library's HTML at the same paper size, and the two are compared page by page — the share of the ink on either side that has ink on the other within 1.33 pt, plus where every word landed.
- **HTML → DOCX**: the reverse. An editor's HTML is shown in a browser with that editor's content stylesheet, and compared with Word's print of the DOCX the library writes from it.

Plain HTML and SunEditor are the profiles tuned first; the other editors follow.

<!-- bench:start (npm run table in bench/fidelity) -->
Share of the ink in place within 1.33 pt, higher is better.

**DOCX → HTML**: Word prints the document; the library's HTML — passed through the editor with the configuration below, and shown with its content stylesheet — is printed by Chromium on the same page, body text only (headers, footers, notes and comments differ by design).

| Document | Plain HTML | SunEditor | CKEditor | TinyMCE | TipTap |
| --- | ---: | ---: | ---: | ---: | ---: |
| Text and character formatting | 94.3% | 92.8% | 92.2% | 94.2% | 92.8% |
| Fonts and typography | 98.1% | 98.1% | 98.2% | 98.1% | 98.2% |
| Lists | 96.2% | 96.2% | 96.2% | 96.2% | 96.3% |
| Tables | 92.4% | 92.4% | 92.4% | 92.4% | 92.4% |
| Pictures | 100.0% | 100.0% | 100.0% | 100.0% | 100.0% |
| A six-page report | 97.7% | 97.7% | 97.7% | 97.7% | 97.7% |
| Footnotes and endnotes | 100.0% | 100.0% | 100.0% | 100.0% | 100.0% |
| Headers, footers, comments | 100.0% | 100.0% | 100.0% | 100.0% | 100.0% |
| **Mean** | **97.3%** | **97.2%** | **97.1%** | **97.3%** | **97.2%** |

**HTML → DOCX**: the editor's own HTML shown in Chromium with its content stylesheet, against Word's print of the DOCX the library writes from it.

| Document | Plain HTML | SunEditor | CKEditor | TinyMCE | TipTap |
| --- | ---: | ---: | ---: | ---: | ---: |
| Text and character formatting | 96.5% | 94.2% | 97.0% | 19.4% | 96.5% |
| Lists | 93.5% | 99.9% | 96.9% | 31.3% | 94.9% |
| Tables | 99.4% | 97.7% | 98.6% | 59.1% | 98.2% |
| Pictures | 99.6% | 97.3% | 98.0% | 69.1% | 99.5% |
| **Mean** | **97.2%** | **97.3%** | **97.6%** | **44.7%** | **97.3%** |
<!-- bench:end -->

See it rather than read about it: the [examples](https://kovami.github.io/htmldocx/) are a report Word wrote, the HTML this library makes of it, the DOCX it makes from editor HTML, and page images of each next to Word's own print.

| | |
| --- | --- |
| Word, plain HTML and the SunEditor profile, side by side | [![showcase](examples/images/showcase-p1.png)](https://kovami.github.io/htmldocx/images/showcase-p1.png) |
| Editor HTML in a browser, and the DOCX made from it in Word | [![editor HTML](examples/images/editor-p1.png)](https://kovami.github.io/htmldocx/images/editor-p1.png) |

## Options

One `Options` object configures both directions. The first block describes the environment a document lives in — the font, size and colour text falls back to, and the stylesheet HTML is interpreted against.

| Option | Default | Applies to | What it does |
| --- | --- | --- | --- |
| `fontFamily` | the profile's | both | Base font of text nothing else formats ([Profiles](#profiles)) |
| `fontSizePt` | the profile's | both | Base size, in points |
| `textColor` | the profile's | both | Base colour, `RRGGBB` |
| `language` | `null` | HTML → DOCX | Proofing language written into the document, e.g. `ru-RU` |
| `pageLayout` | A4 portrait | HTML → DOCX | Paper size, orientation and margins |
| `defaultStylesheet` | the profile's | both | Replaces the built-in defaults: the editor's content CSS for its profile, a browser's for plain HTML |
| `extraStylesheet` | `''` | both | CSS applied above the defaults and below the document's own `<style>` |
| `title`, `author` | `null` | HTML → DOCX | Document properties (the title falls back to `<title>`) |
| `createdAt` | now | HTML → DOCX | Fixed timestamp, for byte-reproducible output |
| `cssUnit` | `px` | DOCX → HTML | Unit for lengths and font sizes: `px` or `pt` |
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

Every block of the HTML this library writes carries its own formatting, whatever profile writes it, so the document looks like itself in an editor, in an email, or on a page with a stylesheet of its own.

## Images

**Into a document.** `<img src>` values are resolved by an `ImageSourceResolver`. Out of the box, `data:` URIs work and nothing else does — a converter does not fetch from the network or read the disk unless you say so:

```php
$converter = HtmlDocx::plain()
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

$converter = HtmlDocx::plain()->withImageHandler(new CallbackImageHandler(
    function (ImageData $image, string $description): ?string {
        Storage::put($path = "media/{$image->hash()}.{$image->extension}", $image->bytes);

        return Storage::url($path);   // or null to leave the picture out
    },
));
```

`$image->bytes` come from the document, so treat them as untrusted. Only pictures recognised by their signature (PNG, JPEG, GIF, BMP, TIFF, WebP converted to PNG) reach the handler; SVG, which can carry scripts, and Windows metafiles are skipped with a warning. Store them under the `extension` the library gives, serve them with its `contentType` and `X-Content-Type-Options: nosniff`, and preferably from a separate domain, so that a file served under the wrong type is never interpreted as a page on your own site.

## Warnings

Anything a document carries but the conversion cannot represent is reported rather than silently dropped:

```php
$converter = HtmlDocx::plain()->withWarningHandler(function (string $message): void {
    Log::notice("htmldocx: {$message}");
});
```

You will hear about embedded alternative-format content, pictures whose data is missing or unreadable, and package parts that could not be parsed.

## Wiring it into an application

The library knows nothing about frameworks: it is a handful of plain classes with no dependencies and no global state, so wiring is a few lines wherever your application builds its services. A converter is immutable and safe to share, so build one and reuse it.

```php
// Laravel — app/Providers/AppServiceProvider.php
use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\Editor;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Options;

$this->app->singleton(HtmlDocx::class, fn (): HtmlDocx => HtmlDocx::for(Editor::CKEditor, new Options(
    fontFamily: config('documents.font_family'),
    fontSizePt: config('documents.font_size_pt'),
    language: config('app.locale'),
    pageLayout: PageLayout::fromArray(config('documents.page', [])),
))
    ->withLocalImageBaseDir(public_path())
    ->withWarningHandler(static fn (string $message) => Log::notice("htmldocx: {$message}")));
```

`PageLayout::fromArray()` takes the shape configuration files tend to have, so paper can live in your own config:

```php
PageLayout::fromArray(['size' => 'a4', 'orientation' => 'landscape', 'margin_cm' => 1.5]);
```

Symfony builds it through a factory, which gives you the same control as above:

```yaml
# config/services.yaml
Kovami\HtmlDocx\HtmlDocx:
    factory: ['App\Documents\ConverterFactory', 'create']   # returns HtmlDocx::plain(...) or HtmlDocx::for(...)
```

## What it converts

### HTML → DOCX

**CSS is actually cascaded.** Inline `style` attributes, `<style>` blocks in the document, the configured stylesheets and presentational attributes (`align`, `width`, `bgcolor`, `border`…) are resolved the way a browser resolves them: full selector support through the native DOM (combinators, attribute selectors, structural pseudo-classes), specificity, `!important`, inheritance and shorthand expansion (`margin`, `padding`, `border`, `background`, `font`, `text-decoration`, `list-style`, logical properties). Lengths in `px`, `pt`, `pc`, `in`, `cm`, `mm`, `q`, `em`, `rem`, `ex`, `ch` and `%` are understood, and colours as hex, `rgb()`, `rgba()`, `hsl()` and named colours.

| Content | How it arrives in Word |
| --- | --- |
| Paragraphs, `div`, `section`, `article`, `blockquote`, `pre`, `address`, … | Paragraphs, with margins as spacing, padding and borders as paragraph borders, background as shading |
| `h1`–`h6` | Real *Heading 1–6* styles, so the navigation pane and a table of contents work |
| `b`, `strong`, `i`, `em`, `u`, `s`, `del`, `sup`, `sub`, `mark`, `small`, `code`, `q`, … | Character formatting: weight, style, underline (including its style), strike-through, position, colour, highlight, font, size, letter spacing, caps and small caps, shadow and kerning |
| `ul`, `ol`, nested lists | Real Word numbering: bullet shapes per depth, `start`, `value`, `reversed`, and `list-style-type` — including counter styles Word has no format for, which are written out as literal markers |
| `table` with `thead`/`tbody`/`tfoot`, `caption`, `colgroup` | Tables with a column grid, repeating header rows, `colspan`/`rowspan`, per-cell borders, shading, padding, vertical alignment and widths (fixed or percentage) |
| `img` | Embedded pictures, scaled to their CSS size |
| `a href` | Hyperlinks; `href="#id"` becomes a bookmark link, and only ids something links to become bookmarks |
| `br`, `hr`, tabs | Line breaks, a bordered rule paragraph, tab characters |
| `page-break-before` / `page-break-after` | Page breaks |
| MathML, `\(…\)` and `\[…\]`, TipTap's math nodes, SunEditor's KaTeX span | **Office Math** — the formula is translated into an equation Word lays out and can edit |
| `<section class="footnotes">` / `endnotes` and the superscript marks linking into them (SunEditor's `se-footnotes` too) | Real footnotes and endnotes, so what this library wrote as HTML goes back as notes |
| `<div class="se-header">` / `se-footer`, with `data-type="first"` or `"even"` | Page headers and footers, including a different first page and different even pages |
| `<span class="se-field" data-field="PAGE">` / `NUMPAGES` | Page number and page count fields Word keeps up to date |
| `<span class="se-comment" data-comment="1 2">` and `<ol class="se-comments">` | Comments on the marked text — ranges may cross paragraphs and overlap — with author, initials, date, replies and the resolved state |
| `float: left` / `right` on a picture | A picture anchored to that side of the column, with the text wrapping around it |
| `iframe`, `video`, `audio`, `embed`, `object` | A link to the source, since Word cannot play them |
| `input` | Checkboxes and radios as ☑ / ☐, other controls as their value |
| `dir="rtl"`, `direction` | Right-to-left paragraphs |
| `<title>` | Document title |

Whitespace follows the CSS rules — collapsing, `pre`, `pre-wrap`, `pre-line`, `nowrap` — and `text-transform`, `first-line` indentation, `line-height` (as a multiple or a fixed height) and `text-align` (including `justify`) all survive. Malformed markup is handled the way browsers handle it, because it is parsed by the same kind of parser.

### DOCX → HTML

Formatting is resolved the way Word resolves it — document defaults, style inheritance chains, numbering levels, table styles and direct formatting, each layer in the right order — and then written as HTML that shows the same thing without needing a stylesheet. The rows below describe plain HTML; a profile writes the same content in its editor's idiom ([Profiles](#profiles)).

| Content | How it arrives in HTML |
| --- | --- |
| Paragraphs and their styles | `<p>`, or `<h1>`–`<h6>` when the paragraph has an outline level, with spacing, indentation, alignment, borders, shading and line height as inline styles |
| Character formatting | `<strong>`, `<em>`, `<u>`, `<del>`, `<sup>`, `<sub>` inside a `<span>` carrying font, size, colour, highlight and shadow; kerning only where Word kerns |
| Theme fonts and colours | Resolved through `theme1.xml`, including tints and shades |
| Numbering | Real `<ul>`/`<ol>` nesting, `start` and `value`, CSS counter styles where one fits, and the exact marker text where none does (`1.2.`, `A)`, `α.`) |
| Tables | `<table>` with a `<colgroup>`, `<thead>`, `rowspan`/`colspan`, and the formatting the table style implies — header rows, banded rows, first/last column, corner cells |
| Pictures | `<img>` with its size, alone in its paragraph or in the line; a floating one keeps its `float` and the text around it. The SunEditor profile wraps it in the image component its toolbar can select and resize |
| Footnotes and endnotes | Superscript links to `<section class="footnotes">` / `endnotes` at the end (DPUB-ARIA roles), with links back to the reference |
| Headers and footers | Kept only by the SunEditor profile, as `<div class="se-header">` before the content and `<div class="se-footer">` after it, the first-page and even-page variants carrying `data-type`; they are the ones the last section shows. Other profiles cut them and say so through the warning handler |
| Page numbers | `<span class="se-field" data-field="PAGE">` (SunEditor profile) or the number Word last showed, as text |
| Comments | Kept only by the SunEditor profile: the text each one is about wrapped in `<span class="se-comment" data-comment="…">`, and an `<ol class="se-comments">` after the notes — one `li` per comment with `data-author`, `data-initials`, `data-date`, `data-parent` for a reply and `data-resolved` |
| Hyperlinks and bookmarks | `<a href>` and `<a id>`, including cross-reference and `HYPERLINK` fields |
| Fields | Their current result (dates, references, form checkboxes); page numbers stay fields |
| Tracked changes | Shown accepted: insertions kept, deletions and moved-away text dropped |
| Office Math | MathML with the LaTeX kept as an annotation, or the shape the profile's editor reads |
| Text boxes and shapes | Their text, as paragraphs after the one they hang on |
| Symbols and dingbats | Mapped to real Unicode characters |
| Line and page breaks, tabs | `<br>`, `page-break-before`, preserved whitespace; in an indented paragraph the text after a tab lands on Word's stops, which count from the margin |
| Section geometry | Page size and margins, as the width the content is laid out against |

Content controls, smart tags and alternative-content wrappers are transparent — what is inside them is read normally.

## What it does not do

Being explicit is more useful than a long feature list, so:

- **Only the last section's headers and footers** are kept: a document whose sections have different ones comes out with those of the final section.
- **Tracked changes are flattened**, not preserved as revisions: you get the document as if every change were accepted.
- **Charts, SmartArt and OLE objects** come through only as the picture Word stores alongside them; the live object is lost.
- **`w:altChunk`** (embedded HTML or RTF inside a `.docx`) is skipped, with a warning.
- **Macros, form data and content-control bindings** are not carried over.
- **Layout is Word's, not the browser's.** Flexbox, grid, multi-column, absolute positioning and overlapping boxes have no equivalent in a word-processing document: block boxes become paragraphs, and their indentation, spacing, borders and backgrounds are kept. A floated picture is anchored with the text wrapping around it; floated text is not.
- **External stylesheets are not fetched.** Only `<style>` blocks in the document and the stylesheets you configure take part in the cascade. `@media` and `@supports` blocks are read when they apply to a printed document; a `<style media="screen">` is skipped.
- **No scripting, SVG or canvas.** `script`, `svg`, `canvas`, `select`, `textarea` and `button` are ignored.
- **The old binary `.doc`**, RTF, ODT and PDF are out of scope, in both directions.
- **Fonts are not embedded**, and text is not measured: pagination is Word's business, so page counts and where a page ends are unknown here.
- **HTML output is a fragment of body content**, not a paginated view of the document.

A few differences are the two engines disagreeing, not the conversion losing anything, and they are what keeps the bench above from reading 100%:

- a browser draws a border thinner than a pixel as a whole one, which makes every table row a fraction of a point taller than Word's;
- CSS puts a list marker next to its text, Word at the hanging indent;
- an editor that drops formatting it does not model drops it before the library sees it — TipTap keeps no `style` on pictures or scripts and no letter spacing without the extension above, TinyMCE no tabs inside code blocks.

## Round trips

The HTML writer only writes what differs from the stylesheet the HTML will be read against, and the HTML reader resolves that same stylesheet — so a document survives the loop instead of accumulating markup. Concretely:

- `HTML → DOCX → HTML` is stable after one pass: convert the result again and you get the same bytes.
- `DOCX → HTML → DOCX` keeps the structure, the formatting, the numbering, the tables, the pictures, the links, the notes, the headers and footers and the comments.
- Word's own view is the referee: a document written here opens in Word without a repair prompt, and reading a Word document back, re-saving it in Word and reading it again gives the same result.

Vertical spacing works the way Word works it: between two paragraphs Word leaves the larger of the space after the first and the space before the second, exactly as CSS margins collapse, so the gaps you see in the browser are the gaps Word prints.

## Safety

Documents arrive from users, so the reader treats them as hostile input:

- Decompressed size is capped per part and per package (`maxDocxEntryBytes`, `maxDocxTotalBytes`), the number of entries is limited, and inflation stops at the size an entry declares — a zip bomb runs out of room instead of memory.
- A part that declares a `DOCTYPE` is rejected outright, since OOXML never has one: entity-expansion attacks have nowhere to start, and the parser has no network access. UTF-16 parts are decoded before the check, and any other encoding that could hide one is refused.
- Everything a document puts into a `style` attribute — font names, list markers — is written as a properly escaped CSS string, so a value cannot close its declaration and add another.
- Relationship targets that point outside the package are treated as missing.
- `javascript:`, `vbscript:`, `data:` and `file:` URLs are dropped from hyperlinks in both directions (an image `src` is a different matter: `data:` is exactly how pictures travel).
- Local images are read only below a directory you name, remote images only through a fetcher you write.
- Pictures are passed on only in raster formats recognised by their bytes; SVG is never passed on (see [Images](#images)).
- Formulas are written as LaTeX for the editor to render. If you render them with KaTeX, keep its default `trust: false`, so `\href`, `\url` and `\htmlClass` in a document cannot produce links or markup.
- Everything a document cannot be trusted to contain — characters XML cannot carry, malformed parts, broken relationships — is cleaned or skipped rather than passed through.

Errors that stop a conversion are thrown as `Kovami\HtmlDocx\Exceptions\HtmlDocxException`.

## Upgrading from 1.x

The 1.x methods are gone: a converter is now chosen with `plain()` or `for()`, a source is read once, and the result is asked for from the `Conversion` it returns.

| 1.x | 2.0 |
| --- | --- |
| `new HtmlDocx($options)` | `HtmlDocx::plain($options)`, or `HtmlDocx::for(Editor::SunEditor, $options)` for SunEditor's markup |
| `$c->htmlToDocx($html)` | `$c->fromHtml($html)->toDocx()` |
| `$c->htmlToDocxFile($html, $path)` | `$c->fromHtml($html)->saveDocx($path)` |
| `$c->htmlToDocxStream($html, $stream)` | `$c->fromHtml($html)->streamDocx($stream)` |
| `$c->docxToHtml($bytes)` | `$c->fromDocx($bytes)->toHtml()` |
| `$c->docxFileToHtml($path)` | `$c->fromDocxFile($path)->toHtml()` |
| `$c->docxStreamToHtml($stream)` | `$c->fromDocxStream($stream)->toHtml()` |
| `$c->readDocx($bytes)` | `$c->fromDocx($bytes)->document()` |
| `$c->readHtml($html)` | `$c->fromHtml($html)->document()` |
| `$c->writeDocx($document)` | `$c->fromDocument($document)->toDocx()` |
| `$c->writeDocxToStream($document, $stream)` | `$c->fromDocument($document)->streamDocx($stream)` |
| `$c->writeHtml($document)` | `$c->fromDocument($document)->toHtml()` |

Two more things changed:

- **The HTML is self-contained.** Every block spells out its font, size, colour, margins and line height instead of leaning on the editor's stylesheet, so it looks right wherever it is shown. `Options::$keepDocumentDefaults`, which chose between the two, is gone.
- **`Options::$fontFamily`, `$fontSizePt` and `$textColor` default to the profile's typography** rather than to Calibri 11pt. Pass them to keep the old base, or to match your editor's own CSS.

The markup 1.x wrote (SunEditor 2's) is still read by every profile's reader, so stored HTML keeps working. Since 2.2 `HtmlDocx::for(Editor::SunEditor)` writes for SunEditor 3; an application still on SunEditor 2 should pass SunEditor 2's content stylesheet as `extraStylesheet`, or stay on 2.1.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

MIT — see [LICENSE](LICENSE). Third-party code is listed in [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
