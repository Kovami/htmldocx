# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The TipTap configuration the README recommends keeps `style` on pictures
  and on super- and subscripts, and letter spacing on `textStyle`, and the
  TipTap profile writes for it: a picture alone on a line spaced by a
  multiple gets Word's room below it, as in the other profiles. Through
  TipTap, pictures HTML -> DOCX 26.4% -> 95.7% of ink in place, typography
  DOCX -> HTML 85.5% -> 98.2% (#9).
- The CKEditor profile reads a table the way CKEditor's content stylesheet
  shows it: its figure's 0.9em above and below, centred when narrower than
  the page, and no margin above a cell's first paragraph or below its last.
  CKEditor tables, HTML -> DOCX: 89.3% -> 98.6% of ink in place (#10).

### Fixed

- The SunEditor profile writes an empty paragraph, heading or list item as
  `<br>` again, as 2.1 did and as SunEditor does (#12); 2.2.0 wrote `&nbsp;`,
  so a document of empty lines had text. Only a table cell holding a single
  empty line keeps the no-break space, which SunEditor 3 needs to keep it.

## [2.2.0] - 2026-09-22

A release about SunEditor 3 and fidelity. The API is unchanged. The
SunEditor profile now writes for SunEditor 3: an application still on
SunEditor 2 should read the first entry below before upgrading.

### Changed

- **The SunEditor profile targets SunEditor 3** (3.3), the current release:
  its content stylesheet (Helvetica Neue 16px, links without underline,
  markers inside the first line, a line height on every element, tables
  without margins), its math component (`span.se-math` with
  `data-se-value`), its image attributes (`data-se-size`,
  `data-se-file-name`) and the figure it scrolls tables in. SunEditor 2's
  markup is still read (`__se__katex` with `data-exp`, `data-size`), but
  HTML written for the SunEditor profile is now what SunEditor 3 loads; an
  application still on SunEditor 2 should pass its own stylesheet
  (`Options::$extraStylesheet`) or stay on 2.1.
- The README's recommended SunEditor configuration is SunEditor 3's:
  `externalLibs: { katex: { src: katex } }`, `elementWhitelist`,
  `attributeWhitelist` and `strictMode` without its attribute and style
  filters.

### Added

- Lists whose markers sit inside the first line (`list-style-position:
  inside`) become Word lists with no hanging indent and a space after the
  marker (`w:suff`), and such Word lists are written back that way.

### Fixed

- A browser grows a line to hold a superscript or subscript, Word does not,
  so everything after such a paragraph came out higher in Word. The HTML
  reader now gives the paragraph that room in its spacing (0.23 of the text
  size above for a superscript, 0.20 below for a subscript, as measured in
  Chromium), unless the script has `line-height: 0` and grows nothing.
- The SunEditor profile gives `sub` and `sup` the `line-height: 0` of
  SunEditor's own stylesheet, and footnote and endnote marks are written
  with it too, so they no longer make their line taller in the browser.

- A picture alone on its line stands on the baseline in a browser, which
  leaves the font's descent under it; Word's line ends at the picture. The
  reader adds that room below such a paragraph (single spacing), and the
  writer stands such a picture at the line's bottom (`vertical-align:
  bottom`), where a browser leaves no gap either.

- A table laid out automatically shares its width as a browser does, cells
  spanning columns included: their content widens the columns they span.
- In the SunEditor profile an empty line holds a no-break space instead of a
  `<br>`: SunEditor 3 drops an empty line holding only `<br>` in a table
  cell, with the size and line height that line carried. Runs and line
  breaks get their paragraph's line height where SunEditor 3's stylesheet
  would give them their own.
- A table SunEditor 3 keeps in a figure takes the figure's width (as wide as
  its content when the figure says nothing) and the 10px below it.

- A paragraph border's space takes room in Word, above and below the text,
  on top of the paragraph's spacing, as CSS padding does. The HTML writer no
  longer takes it out of the margins, and the reader turns padding into
  spacing only on sides with no border.
- A bullet keeps the font it was drawn in. Only Word's Symbol bullet makes a
  line taller, so the HTML writer pads a first line only for that one, and a
  plain `•` (a disc from HTML, or a DOCX bullet in the text's font) stays in
  the text's font in Word, where it leaves the line as a browser's.
- A run in another font no longer makes the browser's line taller than
  Word's: a browser gives each run its own half-leading, and Courier New's in
  Calibri reaches 0.075em lower, while Word's line grows only for a font with
  a taller single line. Such a run gets `line-height: 0`.
- A picture alone on its line at a multiple line spacing: Word's line ends
  the multiple's extra below the picture (0.5 of the single line at 1.5),
  on top of the spacing after. The HTML writer stands the picture on the
  line's bottom and holds the extra in `padding-bottom`, which does not
  collapse with the next margin, as SunEditor's image component too; the
  reader takes it back out of the spacing after. SunEditor 3 shows a
  component's picture as a block, and the profile's stylesheet says so.

- The bench's body-only variant leaves comments out, as it does headers,
  footers and notes, and its page lets a line's multiple spacing hang into
  the bottom margin, as Word does. The README images draw SunEditor 3's
  formulas.

Bench: plain-text 43.4% → 96.3%, plain-images 76.3% → 95.7%, plain-tables
98.7% → 99.5%, suneditor-tables 77.1% → 97.3%, suneditor-lists 92.6% →
94.4%, ckeditor-lists 95.2% → 96.9%,
suneditor-images 93.6% → 97.3%, ckeditor-images 94.5% → 98.0% (HTML → DOCX);
notes 35.1% → 60.8% of the whole page; of the body, formatting-ru 89.6% →
93.9% (SunEditor 81.6% → 92.4%), images 97.9% → 100% (DOCX → HTML).

## [2.1.0] - 2026-09-21

A release about fidelity: the text of HTML and DOCX written by the library
sits where the other side shows it. The API is unchanged; the HTML and DOCX
it writes differ, which is why this is a minor release.

### Fixed

- Text sits where the browser shows it. Chromium centres a font's content in
  its line box, Word puts the font's line gap above the text and a multiple's
  extra space below the line, so the two set the same paragraph a point or
  more apart. `FontMetrics::baselineShift()` computes the difference from
  both measured (Chromium's content height and `normal` line height, Word's
  single line); DOCX → HTML raises the text by it, HTML → DOCX lowers the
  text by it through spacing before and gives it back from the spacing that
  follows, so nothing after the paragraph moves. A line holding only a
  picture starts at the picture's top in both and is left alone.
- A line height of a size Word cannot have is kept: Word has only half
  points, so SunEditor's 13px (9.75pt) becomes 10pt, and the multiple is now
  taken of that, not of 9.75pt. SunEditor text no longer grows 2.5% a line.

HTML → DOCX in the bench: mean ink in place 70.9% → 75.1%; suneditor-text
70.2% → 90.8%, suneditor-lists 58.6% → 88.9%, suneditor-tables 85.9% →
98.7%, ckeditor-images 88.2% → 94.5%, ckeditor-text 84.1% → 89.5%,
plain-text 39.3% → 43.4%. DOCX → HTML: 96.4% → 96.5% of body ink.

## [2.0.1] - 2026-09-21

A security release. Everyone on 2.0.0 should upgrade.

### Security

- A font name (`w:rFonts`) or a list marker (`w:lvlText`) carrying a line
  break (`&#10;`, `&#13;`) could close its CSS string and add declarations of
  its own to the HTML's `style` attributes, such as a `background` image
  fetched from anywhere. CSS strings now escape every control character, a raw
  line break in a declaration is refused, and font names lose characters no
  font is named with. (GHSA-frq2-4fmc-7hwq)
- SVG pictures in a document were passed to the `ImageHandler` unchecked; an
  application storing them on its own origin, as the README showed, served
  whatever script they carried. SVG is now skipped with a warning, like Windows
  metafiles. (GHSA-6vgg-c4m4-wgjj)
- A part written in UTF-16 slipped past the `DOCTYPE` check, and libxml then
  expanded the entities it declared (external ones were never loaded). UTF-16
  is now decoded before the check, other encodings that could hide a `DOCTYPE`
  are refused, and a parsed part with a doctype is rejected as well.
  (GHSA-77jg-mwvf-46gp)
- Links from HTML are filtered by the same function as links written to HTML,
  so a scheme split by a tab or a control character (`java&#9;script:`) is
  dropped in both directions. (GHSA-77jg-mwvf-46gp)

### Fixed

- Helvetica's single line is 1.2 times its size, as Word on macOS draws it,
  not the 1.1753 of its Windows metrics: CKEditor text at 1.5 no longer
  drifts a quarter of a point per line in Word (#2).
- The CKEditor profile gives blockquote the `overflow: hidden` of CKEditor's
  own stylesheet, so a quotation keeps its margins apart from its paragraph's
  instead of losing 12 pt above and below.
- `min-width` widens a box past its `width`, as in CSS: a picture SunEditor
  centres at 50% of the page no longer comes out at 25% (#3).
- The bench corpus no longer carries the repository owner's name in its
  document properties, and `build-corpus.sh` keeps it out (#5).

HTML → DOCX in the bench (`npm run html`): mean ink in place 69.7% → 70.9%;
ckeditor-lists 66.5% → 96.1%, ckeditor-tables 83.3% → 88.5%,
suneditor-images 80.7% → 88.5%. ckeditor-images falls from 96.8% to 88.2%:
its lines are now as tall as the browser's, which shows a constant 3 pt
offset of the first line that the taller lines used to hide. DOCX → HTML is
unchanged at 96.4% of body ink.

### Documentation

- The README says that `ImageData::$bytes` are untrusted and how to serve
  pictures safely, that KaTeX should keep `trust: false`, and what TinyMCE's
  system font stack becomes in Word (#1).

## [2.0.0] - 2026-09-20

A release about looking right: the HTML a document becomes is self-contained
and standard, every editor gets the flavour it speaks, and how close the
result is to Word is measured rather than claimed (see "How close it looks"
in the README). The 1.x methods are replaced by a new entry point; the
README's upgrade table maps every one of them.

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

- **Breaking:** each editor profile reads text the editor leaves unformatted
  in the typography that editor shows out of the box: CKEditor's Helvetica at
  the browser's medium size with 1.5 line spacing, TinyMCE's system font stack
  with 1.4, SunEditor's Helvetica Neue 13px in #333. Plain HTML keeps
  Calibri 11pt. `Options::$fontFamily`, `$fontSizePt` and `$textColor` are
  null by default and replace a profile's typography when given, for an
  application whose own content CSS differs.
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

- The SunEditor profile follows the rest of SunEditor's own list and script
  rules: the space it leaves under every item, the letters and roman numerals
  it numbers nested lists with, and its 75% superscripts and subscripts.
- Padding above and below a block takes room in Word too: a bordered box (a
  code block, a quotation) used to lose it, since Word's border space does not
  take room of its own. A box that keeps its content's margins inside it
  (`overflow` other than `visible`, as CKEditor's quotations have) adds them
  rather than collapsing them.
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

[Unreleased]: https://github.com/kovami/htmldocx/compare/v2.2.0...HEAD
[2.2.0]: https://github.com/kovami/htmldocx/compare/v2.1.0...v2.2.0
[2.1.0]: https://github.com/kovami/htmldocx/compare/v2.0.1...v2.1.0
[2.0.1]: https://github.com/kovami/htmldocx/compare/v2.0.0...v2.0.1
[2.0.0]: https://github.com/kovami/htmldocx/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/kovami/htmldocx/releases/tag/v1.0.0
