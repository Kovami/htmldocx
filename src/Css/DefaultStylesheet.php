<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Css;

/**
 * The built-in stylesheets HTML is read and written against.
 *
 * {@see self::CSS}: browser defaults for structural HTML plus the rules
 * SunEditor's own editor CSS applies to its content (`.sun-editor-editable`
 * and its `__se__*` classes). Because SunEditor emits bare HTML without its
 * stylesheet, the document only looks like it did in the editor if these
 * rules are re-applied during conversion.
 *
 * {@see self::BROWSER}: a browser's defaults alone, for plain HTML.
 *
 * {@see self::CKEDITOR}: a browser's defaults plus what CKEditor's content
 * stylesheet gives tables, which its HTML leaves out wherever it matches.
 */
final class DefaultStylesheet
{
    private const string DISPLAY = <<<'CSS'
        html, body, div, p, h1, h2, h3, h4, h5, h6, blockquote, pre, ul, ol, dl, dt, dd,
        address, article, aside, footer, header, main, nav, section, figure, figcaption,
        details, summary, fieldset, legend, form, hr, center, hgroup, search { display: block; }
        li { display: list-item; }
        table { display: table; }
        caption { display: table-caption; }
        thead { display: table-header-group; }
        tbody { display: table-row-group; }
        tfoot { display: table-footer-group; }
        tr { display: table-row; }
        td, th { display: table-cell; }
        head, script, style, template, title, meta, link, base, noscript, rp, colgroup, col,
        datalist, param, source, track, [hidden] { display: none; }

        CSS;

    public const string CSS = self::DISPLAY . "\n" . <<<'CSS'
        body { line-height: 1.5; }

        p { margin: 0 0 10px 0; }
        h1 { font-size: 2em; font-weight: bold; margin: 0.67em 0; }
        h2 { font-size: 1.5em; font-weight: bold; margin: 0.83em 0; }
        h3 { font-size: 1.17em; font-weight: bold; margin: 1em 0; }
        h4 { font-size: 1em; font-weight: bold; margin: 1.33em 0; }
        h5 { font-size: 0.83em; font-weight: bold; margin: 1.67em 0; }
        h6 { font-size: 0.67em; font-weight: bold; margin: 2.33em 0; }

        b, strong { font-weight: bold; }
        i, em, cite, dfn, var, address { font-style: italic; }
        u, ins { text-decoration: underline; }
        s, strike, del { text-decoration: line-through; }
        sub { vertical-align: sub; }
        sup { vertical-align: super; }
        small { font-size: smaller; }
        big { font-size: larger; }
        mark { background-color: yellow; }
        code, kbd, samp, tt { font-family: monospace; }
        a { color: #004cff; text-decoration: underline; }
        center { text-align: center; }

        pre {
            font-family: monospace; white-space: pre-wrap; color: #666; line-height: 1.45;
            margin: 0 0 10px; padding: 8px; background-color: #f9f9f9; border: 1px solid #e1e1e1;
        }
        blockquote {
            color: #999; margin: 0 0 10px; padding: 0 5px 0 20px;
            border-left: 5px solid #b1b1b1;
        }

        ul, ol { margin: 0 0 10px; padding-left: 40px; }
        li ul, li ol { margin: 0; }
        ul { list-style-type: disc; }
        ol { list-style-type: decimal; }
        ul ul, ol ul { list-style-type: circle; }
        ul ul ul, ul ol ul, ol ul ul, ol ol ul { list-style-type: square; }
        dl { margin: 0 0 10px; }
        dt { font-weight: bold; }
        dd { margin-left: 40px; }

        hr { border: 0; border-top: 1px solid #000; margin: 10px 0; }
        hr.__se__dotted { border-top-style: dotted; }
        hr.__se__dashed { border-top-style: dashed; }

        table { width: 100%; margin: 0 0 10px; }
        td, th { border: 1px solid #e1e1e1; padding: 0.4em; vertical-align: middle; }
        th { font-weight: bold; text-align: center; background-color: #f3f3f3; }
        caption { text-align: center; }

        figure { margin: 0; }
        figcaption { text-align: center; padding: 1em 0.5em; background-color: #f9f9f9; }
        .se-component { margin: 0 0 10px; }
        .__se__float-left { text-align: left; }
        .__se__float-center { text-align: center; }
        .__se__float-right { text-align: right; }

        .__se__p-spaced { letter-spacing: 1px; }
        .__se__p-bordered { border-top: 1px solid #b1b1b1; border-bottom: 1px solid #b1b1b1; padding: 4px 0; }
        .__se__p-neon {
            font-weight: 200; font-style: italic; color: #fff; background-color: #000;
            border: 1px solid #fff; padding: 6px 4px;
        }
        .__se__t-shadow { text-shadow: -0.2rem -0.2rem 1rem #fff, 0.2rem 0.2rem 1rem #fff, 0 0 0.2rem #999; }
        .__se__t-code { font-family: monospace; color: #666; background-color: rgba(27, 31, 35, 0.05); }
        CSS;

    /**
     * What a browser gives HTML before any editor or site CSS: the baseline
     * plain HTML is written against, which spells out everything else.
     * Scripts keep their size: Word already shrinks superscript and subscript.
     */
    public const string BROWSER = self::DISPLAY . <<<'CSS'
        p { margin: 1em 0; }
        h1 { font-size: 2em; font-weight: bold; margin: 0.67em 0; }
        h2 { font-size: 1.5em; font-weight: bold; margin: 0.83em 0; }
        h3 { font-size: 1.17em; font-weight: bold; margin: 1em 0; }
        h4 { font-size: 1em; font-weight: bold; margin: 1.33em 0; }
        h5 { font-size: 0.83em; font-weight: bold; margin: 1.67em 0; }
        h6 { font-size: 0.67em; font-weight: bold; margin: 2.33em 0; }

        b, strong { font-weight: bold; }
        i, em, cite, dfn, var, address { font-style: italic; }
        u, ins { text-decoration: underline; }
        s, strike, del { text-decoration: line-through; }
        sub { vertical-align: sub; }
        sup { vertical-align: super; }
        small { font-size: smaller; }
        big { font-size: larger; }
        mark { background-color: yellow; }
        code, kbd, samp, tt { font-family: monospace; }
        a { color: #0000ee; text-decoration: underline; }
        center { text-align: center; }

        ul, ol { margin: 1em 0; padding-left: 40px; }
        li ul, li ol { margin: 0; }
        ul { list-style-type: disc; }
        ol { list-style-type: decimal; }
        ul ul, ol ul { list-style-type: circle; }
        ul ul ul, ul ol ul, ol ul ul, ol ol ul { list-style-type: square; }

        hr { border: 1px inset; margin: 0.5em 0; }
        thead, tbody, tfoot, tr { vertical-align: middle; }
        td, th { padding: 1px; vertical-align: inherit; }
        th { font-weight: bold; text-align: center; }
        figure { margin: 1em 40px; }
        CSS;

    public const string CKEDITOR = self::BROWSER . <<<'CSS'
        table { border: 1px double #b3b3b3; border-collapse: collapse; }
        td, th { border: 1px solid #bfbfbf; padding: 0.4em; vertical-align: middle; }
        th { background-color: #f2f2f2; }
        CSS;
}
