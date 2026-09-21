// HTML → DOCX bench: does the DOCX the library writes from an editor's HTML
// look in Word the way the HTML looks in the editor? (macOS with Microsoft
// Word: Word prints every DOCX, each run.)
//
// For every html/<name>.html (plain, standard HTML) and every editor: the
// editor (recommended configuration) loads the HTML and hands back its own
// markup, which is what an application stores. The reference is that markup
// printed by Chromium the way the editor shows it (its content stylesheet,
// on the library's default page and base font); the result is the DOCX the
// library writes from it with the editor's profile, printed by Word. "plain"
// is the source itself, read as plain HTML. Ink match and word placement as
// in run.mjs.
//
// Usage: npm run html [-- editor|name ...]
// report/html/ gets the HTML, DOCX, PDFs, page images and summary.md.

import { execFileSync } from 'node:child_process';
import { copyFileSync, existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { basename, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { chromium } from 'playwright';
import { EDITORS, harnessFor, shown } from './harness.mjs';
import { format, measure } from './measure.mjs';

const here = resolve(import.meta.dirname);
const sources = join(here, 'html');
const report = join(here, 'report', 'html');
// Word is sandboxed: it may only touch files inside its own container.
const word = join(homedir(), 'Library/Containers/com.microsoft.Word/Data/htmldocx-html');
mkdirSync(report, { recursive: true });
mkdirSync(word, { recursive: true });

const args = process.argv.slice(2);
const profiles = ['plain', ...Object.keys(EDITORS)].filter((p) => !args.some((arg) => arg === 'plain' || arg in EDITORS) || args.includes(p));
const names = readdirSync(sources)
    .filter((file) => file.endsWith('.html'))
    .map((file) => basename(file, '.html'))
    .filter((name) => !args.some((arg) => arg !== 'plain' && !(arg in EDITORS)) || args.includes(name))
    .sort();

const browser = await chromium.launch();
const results = [];

for (const profile of profiles) {
    const harness = profile === 'plain' ? null : await harnessFor(profile, report);

    for (const name of names) {
        const source = readFileSync(join(sources, `${name}.html`), 'utf8');
        const html = harness === null ? source : await throughEditor(harness, source);
        const id = `${profile}-${name}`;
        // Next to the source, so its pictures resolve the same way.
        const file = join(sources, `.${id}.html`);
        writeFileSync(file, html);
        writeFileSync(join(report, `${id}.html`), html);

        const page = JSON.parse(execFileSync('php', [join(here, 'convert-html.php'), file, profile, join(report, `${id}.docx`)]).toString());
        execFileSync('rm', [file]);
        const pdf = printWithWord(join(report, `${id}.docx`), id);
        const scores = await measure(browser, asShown(profile, html, page), page, pdf, report, id);
        const { dy, ...result } = { ...scores, name: id };
        results.push(result);
        console.log(format(result));
    }
}

await browser.close();

const mean = (key) => results.reduce((sum, r) => sum + r[key], 0) / Math.max(1, results.length);
const rows = results.map((r) => `| ${r.name} | ${r.wordPages}/${r.htmlPages} | ${(r.pixelSimilarity * 100).toFixed(1)} | ${(r.samePage * 100).toFixed(1)} | ${r.dyMedian?.toFixed(1) ?? '—'} |`);
const summary = [
    'Reference: the editor\'s HTML in Chromium. Result: the DOCX the library writes from it, in Word.',
    '',
    '| Profile-document | Pages (DOCX/HTML) | Ink match % | Same page % | dy median pt |',
    '| --- | --- | --- | --- | --- |',
    ...rows,
    `| **Mean** | | **${(mean('pixelSimilarity') * 100).toFixed(1)}** | **${(mean('samePage') * 100).toFixed(1)}** | |`,
    '',
].join('\n');
writeFileSync(join(report, 'summary.md'), summary);
// Every result so far, this run's replacing earlier ones: npm run table reads it.
const kept = existsSync(join(report, 'summary.json')) ? JSON.parse(readFileSync(join(report, 'summary.json'), 'utf8')) : [];
writeFileSync(join(report, 'summary.json'), JSON.stringify([...kept.filter((r) => !results.some((n) => n.name === r.name)), ...results], null, 2));
console.log(`\n${summary}`);

/** The editor's own markup for an HTML fragment. */
async function throughEditor(harness, html) {
    const page = await browser.newPage();
    await page.goto(pathToFileURL(harness).href, { waitUntil: 'load' });
    const output = await page.evaluate((fragment) => window.roundTrip(fragment, true), html);
    await page.close();

    return output;
}

/** The HTML as the editor shows it, on the library's page, in its base font. */
function asShown(profile, html, page) {
    const base = pathToFileURL(sources).href;
    const head = `<!DOCTYPE html><html><head><meta charset="utf-8"><base href="${base}/">`
        + `<style>body { font-family: ${page.font.family}; font-size: ${page.font.size}pt; }</style></head><body></body></html>`;

    return profile === 'plain' ? head.replace('<body></body>', `<body>${html}</body>`) : shown(profile, head, html);
}

/** Word's PDF of a DOCX. */
function printWithWord(docx, id) {
    copyFileSync(docx, join(word, `${id}.docx`));
    execFileSync('osascript', ['-e', `with timeout of 180 seconds
    tell application "Microsoft Word"
        open POSIX file "${join(word, `${id}.docx`)}"
        save as active document file name "${join(word, `${id}.pdf`)}" file format format PDF
        close active document saving no
    end tell
end timeout`]);
    copyFileSync(join(word, `${id}.pdf`), join(report, `${id}.word.pdf`));

    return new Uint8Array(readFileSync(join(word, `${id}.pdf`)));
}
