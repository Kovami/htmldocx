// Editor bench: what survives when a rich-text editor loads the HTML written
// by kovami/htmldocx and saves it again?
//
// For every corpus/<name>.docx and every editor: the library converts the
// document to the editor's profile of HTML, the editor (in headless Chromium,
// with the plugins an application editing Word-like documents enables, in
// their default configuration and in the one the README recommends) loads it
// and hands it back, and two things are measured:
//
//  - survival: every tag, attribute and CSS property (by element) of the
//    library's HTML is counted before and after; what the editor dropped or
//    added is listed;
//  - fidelity: the editor's HTML is printed, the way the editor's docs say to
//    show it (with its content stylesheet), and compared with Word's PDF, as
//    run.mjs does for the library's own HTML.
//
// Usage: npm run editors [-- editor|name ...]
//        PROFILE=plain npm run editors   (one profile's HTML for every editor)
// report/editors/ gets each editor's HTML, the page images and summary.md.

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { EDITORS, body, harnessFor, shown } from './harness.mjs';
import { measure } from './measure.mjs';

const here = resolve(import.meta.dirname);
const report = join(here, 'report', 'editors');
const profile = process.env.PROFILE ?? null;
const CATEGORIES = ['blocks', 'runs', 'lists', 'tables', 'notes', 'math', 'images', 'links'];
mkdirSync(report, { recursive: true });

const args = process.argv.slice(2);
const editors = Object.keys(EDITORS).filter((editor) => !args.some((arg) => arg in EDITORS) || args.includes(editor));
// The corpus, plus samples of what it lacks (formulas), which Word has not printed.
const sources = Object.fromEntries(['corpus', 'editors/samples'].flatMap((folder) => readdirSync(join(here, folder))
    .filter((file) => file.endsWith('.docx'))
    .map((file) => [basename(file, '.docx'), join(here, folder, file)])));
const names = Object.keys(sources)
    .filter((name) => !args.some((arg) => !(arg in EDITORS)) || args.includes(name))
    .sort();

const converted = {};
const convert = (name, as) => (converted[`${as} ${name}`] ??= JSON.parse(execFileSync('php', [join(here, 'convert.php'), sources[name], as], { maxBuffer: 256 << 20 }).toString()));

const browser = await chromium.launch();
const results = [];

for (const [editor, config] of editors.flatMap((editor) => ['default', 'recommended'].map((config) => [editor, config]))) {
    const harness = await harnessFor(editor, report);
    const run = `${editor} ${config}`;
    const dir = join(report, `${editor}-${config}`);
    mkdirSync(dir, { recursive: true });

    for (const name of names) {
        const { html, page: geometry } = convert(name, profile ?? editor);
        const input = body(html);
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', (error) => errors.push(error.message));
        await page.goto(pathToFileURL(harness).href, { waitUntil: 'load' });

        let output;

        try {
            output = await page.evaluate(([fragment, recommended]) => window.roundTrip(fragment, recommended), [input, config === 'recommended']);
        } catch (error) {
            console.warn(`${run} ${name}: ${error.message.split('\n')[0]}`);
            await page.close();
            continue;
        }

        const [before, after] = await page.evaluate(([a, b]) => [window.features(a), window.features(b)], [input, output]);
        await page.close();
        writeFileSync(join(dir, `${name}.html`), output);

        const reference = join(here, 'reference', `${name}.pdf`);
        const scores = existsSync(reference)
            ? await measure(browser, shown(editor, html, output), geometry, new Uint8Array(readFileSync(reference)), dir, `${name}.printed`)
            : null;

        const result = { editor: run, name, before, after, pixels: scores?.pixelSimilarity ?? null, errors };
        results.push(result);
        console.log(`${run} ${name}: survived ${percent(survival([result]).rate)}, ink ${scores ? percent(scores.pixelSimilarity) : '—'}${errors.length ? `, ${errors.length} page error(s)` : ''}`);
    }
}

await browser.close();
writeFileSync(join(report, 'summary.json'), JSON.stringify(results, null, 2));
writeFileSync(join(report, 'summary.md'), summary(results));
console.log(`\n${summary(results)}`);

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
        `Profile: ${profile ?? 'each editor\'s own'}. Survival: share of the library's tags, attributes and CSS properties still there after the editor. Ink match: the editor's HTML printed and compared with Word (share of ink in place within 1.33 pt).`,
        '',
        `| Editor | Survived | ${CATEGORIES.join(' | ')} | Ink match |`,
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
