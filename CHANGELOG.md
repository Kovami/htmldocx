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
- The CKEditor, TinyMCE and TipTap profiles build on plain HTML, and each
  writes formulas in the shape its editor's math plugin reads: `\(…\)` in a
  `math-tex` span for CKEditor (ckeditor5-math) and TinyMCE (MathJax), TipTap's
  `inline-math` node for TipTap. Without a plugin the LaTeX stays readable
  text. The HTML reader reads all of these back as formulas.
- Plain HTML and the profiles built on it write table cell lines as `<p>`,
  which editors keep, and give pictures `width` and `height` attributes as well
  as their style. The TipTap profile keeps a list item's text in a paragraph
  of its own and sizes columns with TipTap's `colwidth`.
- The HTML reader understands what editors make of the library's HTML: a note
  list stripped of its section (found by its items' ids), a note mark stripped
  of its id, `<p>&nbsp;</p>` as an empty paragraph, and CKEditor's
  `<figure class="table">`. HTML of the CKEditor profile is read against the
  table borders CKEditor's content stylesheet draws.
- In plain HTML and the profiles built on it, a bookmark at the start of a
  paragraph becomes the paragraph's `id`, which editors keep, and tables say
  `border: none` so an editor's CSS draws no frame Word does not.
- The CKEditor profile writes tables in CKEditor's `<figure class="table">`,
  which carries the width and none of its stylesheet's margins. The SunEditor
  profile marks tables `se-table-layout-fixed` so SunEditor keeps Word's column
  widths, rules no rows or header of its own, and places image components
  where Word placed the picture.
- The SunEditor profile writes each line of a page header or footer as a
  `div.se-header` (or `se-footer`) of its own, since SunEditor keeps a classed
  div only as a line of text; the reader joins consecutive lines into one.

### Changed

- **Breaking:** a new entry point replaces the 1.x methods. Choose the HTML
  with `HtmlDocx::plain()` or `HtmlDocx::for(Editor::SunEditor)` (an `Editor`
  case or its name, e.g. `'tinymce'`), start from `fromDocxFile()`,
  `fromDocx()`, `fromDocxStream()`, `fromHtml()`, `fromHtmlFile()` or
  `fromDocument()`, and finish with `toHtml()`, `saveHtml()`, `toDocx()`,
  `saveDocx()` or `streamDocx()`; `document()` returns the model.
  `new HtmlDocx`, `htmlToDocx()`, `docxToHtml()`, `readHtml()`, `writeDocx()`
  and the rest of the 1.x methods are gone.
- **Breaking:** every profile writes self-contained HTML: each block spells
  out its font, size, colour, margins and line height, whatever the editor's
  stylesheet already says, so content looks like the document wherever it is
  shown. The SunEditor profile keeps its own components (image components,
  KaTeX formulas, headers, footers, comments, fields) on top.
  `Options::$keepDocumentDefaults`, which chose between the document's and the
  editor's base formatting, is gone.
- HTML line heights are read as Word's: a multiple of the font size becomes
  the matching multiple of the font's own single line (Calibri's is 1.22 times
  its size), so a paragraph keeps the height the browser showed.
- Paragraph spacing collapses the way Word does it: between two paragraphs
  Word leaves the larger of space after and space before, exactly as CSS
  margins collapse. Both directions used to add the two, which made every
  change of spacing (before each heading, for instance) too tall.
- Justified paragraphs narrow their spaces (`word-spacing: -0.065em`): Word
  squeezes the spaces of a justified line to fit one more word, a browser
  only stretches them, so long justified text broke its lines (and pages)
  later than Word.
- Text with more than single line spacing is raised (`position: relative`
  with a negative `top`) to where Word sets it: Word adds the extra space of
  a multiple below each line, CSS half above and half below.
- Superscripts and subscripts are drawn as Word draws them: `<sup>` and
  `<sub>` get `font-size: 65%; line-height: 0`, where a browser's are larger
  and make their line taller. A `sub` or `super` element sized relatively
  (`%`, `em`, `smaller`) is read back at its text's size, so Word does not
  shrink it twice.
- The first line of a bulleted item is as tall as Word makes it: Word draws
  its bullet in Symbol, whose ascent is taller than Calibri's, Cambria's,
  Arial's or Times New Roman's, so the item gets the difference as
  `padding-top`, which the HTML reader takes back off.
- `Options::$defaultStylesheet` defaults to `null`, meaning the profile's own
  defaults: a browser's for plain HTML, SunEditor's for an editor profile.

### Fixed

- A table cell without its own vertical alignment takes its row's, as in a
  browser, where rows are middle-aligned; `inherit` used to fall back to top.
- A floated picture keeps text wrapping around it on the way to Word: the
  HTML reader reads `float` (on the `img` or its figure) and the DOCX writer
  anchors the picture to that side of the column with square wrapping. It
  used to become an inline picture, even in a DOCX → HTML → DOCX round trip.
- A picture set apart by auto margins (TinyMCE's centred image, CKEditor's
  figures) aligns its paragraph the same way, and a box with its own `width`
  or `max-width` holds its pictures and tables to it. The CKEditor profile
  reads CKEditor's image classes: resized, side, wrapped and block-aligned.
- Table columns HTML leaves unsized share the table the way a browser shares
  it, by how wide their content is, instead of equally; a table without a
  width is as wide as its content (up to the page) rather than the page.
- A quotation keeps the indent a browser gives it (`blockquote`: 40px on both
  sides); CKEditor's gets its rule, padding and italics. Plain HTML also reads
  a browser's defaults for `pre`, `dl` and `dd`.
- Bullets from HTML are Word's own: `disc`, `circle` and `square` become the
  Symbol, Courier New and Wingdings bullets Word draws, instead of the
  Unicode characters in the text font, which Word drew smaller and lower.
- A formula on a line of its own (Word's display math) stays one: plain HTML
  writes `<math display="block">`, CKEditor and TinyMCE get `\[…\]`, and the
  HTML reader reads those and TipTap's `block-math` back as display math.
  It used to become an inline formula at the start of the line.
- The stylesheet embedded in a full HTML document no longer hides `colgroup`
  and `col` (`display: none`), which made a browser ignore the column widths.
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
