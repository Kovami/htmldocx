// Editor bench: what survives when a rich-text editor loads the HTML written
// by kovami/htmldocx and saves it again?
//
// For every corpus/<name>.docx and every editor: the library converts the
// document to HTML, the editor (in headless Chromium, with the plugins an
// application editing Word-like documents enables) loads it and hands it
// back, and two things are measured:
//
//  - survival: every tag, attribute and CSS property (by element) of the
//    library's HTML is counted before and after; what the editor dropped or
//    added is listed;
//  - fidelity: the editor's HTML is printed and compared with Word's PDF, as
//    run.mjs does for the library's own HTML.
//
// Usage: npm run editors [-- editor|name ...]
//        PROFILE=suneditor npm run editors   (another profile's HTML)
// report/editors/ gets each editor's HTML, the page images and summary.md.

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';
import { chromium } from 'playwright';
import { measure } from './measure.mjs';

const here = resolve(import.meta.dirname);
const report = join(here, 'report', 'editors');
const profile = process.env.PROFILE ?? 'plain';
const CATEGORIES = ['blocks', 'runs', 'lists', 'tables', 'notes', 'math', 'images', 'links'];
mkdirSync(report, { recursive: true });

/** How each editor is put on a page: scripts in order, stylesheets, and the element it takes over. */
const EDITORS = {
    ckeditor: {
        scripts: ['node_modules/ckeditor5/dist/browser/ckeditor5.umd.js', 'editors/ckeditor.js'],
        styles: ['node_modules/ckeditor5/dist/browser/ckeditor5.css'],
        element: 'div',
    },
    tinymce: { scripts: ['node_modules/tinymce/tinymce.min.js', 'editors/tinymce.js'], styles: [], element: 'textarea' },
    tiptap: { bundle: 'editors/tiptap.js', styles: [], element: 'div' },
    suneditor: { bundle: 'editors/suneditor.js', styles: ['node_modules/suneditor/dist/css/suneditor.min.css'], element: 'textarea' },
};

const args = process.argv.slice(2);
const editors = Object.keys(EDITORS).filter((editor) => !args.some((arg) => arg in EDITORS) || args.includes(editor));
// The corpus, plus samples of what it lacks (formulas), which Word has not printed.
const sources = Object.fromEntries(['corpus', 'editors/samples'].flatMap((folder) => readdirSync(join(here, folder))
    .filter((file) => file.endsWith('.docx'))
    .map((file) => [basename(file, '.docx'), join(here, folder, file)])));
const names = Object.keys(sources)
    .filter((name) => !args.some((arg) => !(arg in EDITORS)) || args.includes(name))
    .sort();

const converted = Object.fromEntries(names.map((name) => [
    name,
    JSON.parse(execFileSync('php', [join(here, 'convert.php'), sources[name], profile], { maxBuffer: 256 << 20 }).toString()),
]));

const browser = await chromium.launch();
const results = [];

for (const editor of editors) {
    const harness = await harnessFor(editor);
    const dir = join(report, editor);
    mkdirSync(dir, { recursive: true });

    for (const name of names) {
        const { html, page: geometry } = converted[name];
        const input = body(html);
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto(pathToFileURL(harness).href, { waitUntil: 'load' });

        let output;

        try {
            output = await page.evaluate((fragment) => window.roundTrip(fragment), input);
        } catch (error) {
            console.warn(`${editor} ${name}: ${error.message.split('\n')[0]}`);
            await page.close();
            continue;
        }

        const [before, after] = await page.evaluate(([a, b]) => [window.features(a), window.features(b)], [input, output]);
        await page.close();
        writeFileSync(join(dir, `${name}.html`), output);

        const reference = join(here, 'reference', `${name}.pdf`);
        const scores = existsSync(reference)
            ? await measure(browser, html.replace(/<body>[\s\S]*<\/body>/, `<body>\n${output}\n</body>`), geometry, new Uint8Array(readFileSync(reference)), dir, `${name}.printed`)
            : null;

        const result = { editor, name, before, after, pixels: scores?.pixelSimilarity ?? null, errors };
        results.push(result);
        console.log(`${editor} ${name}: survived ${percent(survival([result]).rate)}, pixels ${scores ? percent(scores.pixelSimilarity) : '—'}${errors.length ? `, ${errors.length} page error(s)` : ''}`);
    }
}

await browser.close();
writeFileSync(join(report, 'summary.json'), JSON.stringify(results, null, 2));
writeFileSync(join(report, 'summary.md'), summary(results));
console.log(`\n${summary(results)}`);

/** A page that loads the editor, plus the feature counter the bench runs in the browser. */
async function harnessFor(editor) {
    const { scripts = [], styles, bundle, element } = EDITORS[editor];
    const files = [...scripts.map((script) => join(here, script))];

    if (bundle) {
        const outfile = join(report, 'build', `${editor}.js`);
        await build({ entryPoints: [join(here, bundle)], bundle: true, format: 'iife', outfile, logLevel: 'error' });
        files.push(outfile);
    }

    const file = join(report, `${editor}.harness.html`);
    writeFileSync(file, [
        '<!DOCTYPE html><html><head><meta charset="utf-8">',
        ...styles.map((style) => `<link rel="stylesheet" href="${pathToFileURL(join(here, style)).href}">`),
        `<script>${countFeatures.toString()}\nwindow.features = countFeatures;</script>`,
        ...files.map((script) => `<script src="${pathToFileURL(script).href}"></script>`),
        `</head><body><${element} id="editor"></${element}></body></html>`,
    ].join('\n'));

    return file;
}

/**
 * Runs in the browser: every tag, attribute and CSS property family of an
 * HTML fragment, keyed by element, with how often it occurs. Longhands count
 * once per family (`margin-top` and `margin-left` are one `margin`), since
 * editors rewrite shorthands.
 */
function countFeatures(html) {
    const document = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
    const counts = {};
    const add = (key) => {
        counts[key] = (counts[key] ?? 0) + 1;
    };

    for (const element of document.body.querySelectorAll('*')) {
        const tag = /^h[1-6]$/.test(element.localName) ? 'h*' : element.localName;
        add(`<${tag}>`);

        for (const attribute of element.attributes) {
            if (attribute.name !== 'style') {
                add(`<${tag} ${attribute.name}>`);
            }
        }

        const families = new Set();

        for (let i = 0; i < element.style.length; i++) {
            families.add(element.style[i].replace(/^(border|margin|padding|background|text-decoration|list-style|font-variant|break|page-break)-.*$/, '$1'));
        }

        families.forEach((family) => add(`<${tag} style=${family}>`));
    }

    return counts;
}

function body(html) {
    const match = html.match(/<body[^>]*>([\s\S]*)<\/body>/);

    return (match ? match[1] : html).trim();
}

/** What a feature is about, for the summary's columns. */
function category(key) {
    const tag = key.match(/^<([\w*-]+)/)?.[1] ?? '';

    return [
        ['math', /^(math|semantics|annotation|mrow|mi|mn|mo|mtext|mfrac|msub|msup|msubsup|msqrt|mroot|munder|mover|munderover|menclose|mtable|mtr|mtd)$/.test(tag)],
        ['notes', tag === 'section' || / role>$/.test(key) || /^<(a|li) id>$/.test(key)],
        ['tables', /^(table|colgroup|col|thead|tbody|tr|td|th)$/.test(tag)],
        ['lists', /^(ol|ul)$/.test(tag) || /^<li( (value|style=list-style))?>$/.test(key)],
        ['images', tag === 'img' || tag === 'figure'],
        ['links', tag === 'a'],
        ['blocks', /^(p|h\*|li|div|hr)$/.test(tag)],
        ['runs', true],
    ].find(([, matches]) => matches)[0];
}


/** Of what the library wrote, the share still there after the editor, overall and by category. */
function survival(entries) {
    const totals = {};

    for (const { before, after } of entries) {
        for (const [key, count] of Object.entries(before)) {
            const bucket = (totals[category(key)] ??= { in: 0, kept: 0 });
            bucket.in += count;
            bucket.kept += Math.min(count, after[key] ?? 0);
        }
    }

    const all = Object.values(totals).reduce((sum, t) => ({ in: sum.in + t.in, kept: sum.kept + t.kept }), { in: 0, kept: 0 });

    return { rate: all.in === 0 ? 1 : all.kept / all.in, byCategory: Object.fromEntries(Object.entries(totals).map(([name, t]) => [name, t.kept / t.in])) };
}

/** Keys an editor lost (or added) most, summed over the documents. */
function differences(entries, sign) {
    const totals = {};

    for (const { before, after } of entries) {
        for (const key of new Set([...Object.keys(before), ...Object.keys(after)])) {
            const delta = sign * ((before[key] ?? 0) - (after[key] ?? 0));

            if (delta > 0) {
                totals[key] = (totals[key] ?? 0) + delta;
            }
        }
    }

    return Object.entries(totals).sort((a, b) => b[1] - a[1]);
}

function percent(value) {
    return value === null || value === undefined ? '—' : `${(value * 100).toFixed(1)}%`;
}

function summary(entries) {
    const byEditor = Object.groupBy(entries, (entry) => entry.editor);
    const lines = [
        `Profile: ${profile}. Survival: share of the library's tags, attributes and CSS properties still there after the editor. Pixels: the editor's HTML printed and compared with Word.`,
        '',
        `| Editor | Survived | ${CATEGORIES.join(' | ')} | Pixels |`,
        `| --- | --- | ${CATEGORIES.map(() => '---').join(' | ')} | --- |`,
    ];

    for (const [editor, list] of Object.entries(byEditor)) {
        const { rate, byCategory } = survival(list);
        const pixels = list.filter((entry) => entry.pixels !== null);
        const meanPixels = pixels.length === 0 ? null : pixels.reduce((sum, entry) => sum + entry.pixels, 0) / pixels.length;
        lines.push(`| ${editor} | **${percent(rate)}** | ${CATEGORIES.map((name) => percent(byCategory[name])).join(' | ')} | ${percent(meanPixels)} |`);
    }

    for (const [editor, list] of Object.entries(byEditor)) {
        const lost = differences(list, 1).slice(0, 25).map(([key, count]) => `\`${key}\` ${count}`);
        const added = differences(list, -1).slice(0, 15).map(([key, count]) => `\`${key}\` ${count}`);
        lines.push('', `## ${editor}`, '', `Lost: ${lost.join(', ') || 'nothing'}`, '', `Added: ${added.join(', ') || 'nothing'}`);
    }

    return `${lines.join('\n')}\n`;
}
