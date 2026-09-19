# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `HtmlDocx::plain()` writes self-contained HTML that looks the same under any
  editor's CSS: every block spells out its font, size, colour, margins and line
  height, tables draw their own borders at Word's column widths, and no editor
  classes or stylesheet are needed.
- Plain HTML leaves out what has no HTML equivalent and reports each through
  the warning handler: page headers and footers, comments, and page-number
  fields (which keep the value Word last showed, as text).
- Plain HTML uses standard markup: notes as DPUB-ARIA sections
  (`role="doc-noteref"`, `<section class="footnotes" role="doc-endnotes">`),
  formulas as MathML with the LaTeX in an `application/x-tex` annotation, and
  pictures as ordinary `<img>`. The HTML reader understands this markup as well
  as SunEditor's.
- Plain HTML writes Word's line spacing in Word's terms: a multiple of the
  font's own single line (from a table of common fonts), not of its size.
- Plain HTML names free fonts with the same metrics after Word's own (Calibri,
  Carlito, sans-serif; Cambria, Caladea, serif; Arial and Times New Roman with
  their Liberation equivalents) and keeps Word's 36 pt tab stops.
- Plain HTML keeps Word's "keep with next" and "keep lines together" as
  `break-after: avoid` and `break-inside: avoid`, and the HTML reader maps both
  back, whichever HTML they come from.

### Changed

- **Breaking:** a new entry point replaces the 1.x methods. Choose the HTML
  with `HtmlDocx::plain()` or `HtmlDocx::for(Editor::SunEditor)` (an `Editor`
  case or its name, e.g. `'tinymce'`), start from `fromDocxFile()`,
  `fromDocx()`, `fromDocxStream()`, `fromHtml()`, `fromHtmlFile()` or
  `fromDocument()`, and finish with `toHtml()`, `saveHtml()`, `toDocx()`,
  `saveDocx()` or `streamDocx()`; `document()` returns the model.
  `new HtmlDocx`, `htmlToDocx()`, `docxToHtml()`, `readHtml()`, `writeDocx()`
  and the rest of the 1.x methods are gone.
- Paragraph spacing collapses the way Word does it: between two paragraphs
  Word leaves the larger of space after and space before, exactly as CSS
  margins collapse. Both directions used to add the two, which made every
  change of spacing (before each heading, for instance) too tall.
- `Options::$defaultStylesheet` defaults to `null`, meaning the profile's own
  defaults: a browser's for plain HTML, SunEditor's for an editor profile.

### Fixed

- A double, dotted, dashed or wavy underline is written on the `<u>` that draws
  it; on a span around it the style did not apply and was lost on the way back.
- A paragraph that follows Word's default alignment inside a centred container
  (a header cell) is written `text-align: left` rather than `start`, which the
  HTML reader read back as an explicit left alignment.

## [1.0.0] - 2026-09-18

The first public release.

### Added

- `HtmlDocx` facade converting HTML to DOCX and DOCX to HTML through one
  document model, with strings, files and streams on either side.
- HTML → DOCX: a CSS cascade (selectors, specificity, inheritance, `<style>`
  blocks and inline styles) turned into Word paragraph and run formatting;
  headings, lists with any nesting and marker style, tables with column grids,
  merged cells, borders and shading, images (data URIs, local files and, when
  allowed, remote ones), hyperlinks and bookmarks, page breaks, and LaTeX
  formulas written as editable Office Math.
- DOCX → HTML: Word's formatting hierarchy resolved (document defaults, style
  chains, numbering, table styles, themes) and written as HTML with inline
  styles, emitting only what differs from the editor stylesheet so documents
  survive round trips.
- Footnotes and endnotes in both directions.
- Page headers and footers in both directions, including first-page and
  even-page variants, with `PAGE` and `NUMPAGES` fields.
- Comments in both directions: ranges across paragraphs, overlapping comments,
  authors, initials, dates, replies and the resolved state.
- `Options` for the editor environment (font, size, colour, stylesheets), page
  layout, output format and size limits of untrusted packages.
- Warnings for content that could not be converted, through a handler you
  provide.
- Support for PHP 8.4 and 8.5 with no runtime dependencies beyond bundled
  extensions.

[Unreleased]: https://github.com/kovami/htmldocx/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/kovami/htmldocx/releases/tag/v1.0.0
