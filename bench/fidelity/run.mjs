// Fidelity bench: how closely does the HTML written by kovami/htmldocx look
// like the same document printed by Microsoft Word?
//
// For every corpus/<name>.docx: the library converts it to a full HTML
// document, Chromium prints that HTML at the document's page size, and the
// result is compared with reference/<name>.pdf, which Word printed
// (export-references.sh). Two measures per document:
//
//  - pixel similarity: pages rendered to images and compared pixel by pixel,
//    counted over the pixels that carry ink on either side;
//  - word placement: every word of Word's PDF is matched with the same word
//    in ours, and we report how many sit on the same page and how far they
//    moved, in points.
//
// Usage: npm run bench [-- name ...]   (report/ gets images and summary)

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createCanvas } from '@napi-rs/canvas';
import pixelmatch from 'pixelmatch';
import { chromium } from 'playwright';
import { PNG } from 'pngjs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import { fontFaces } from './fonts.mjs';

const here = resolve(import.meta.dirname);
const report = join(here, 'report');
const scale = 1.5; // 108 dpi
mkdirSync(report, { recursive: true });

const only = process.argv.slice(2);
const names = readdirSync(join(here, 'corpus'))
    .filter((file) => file.endsWith('.docx'))
    .map((file) => basename(file, '.docx'))
    .filter((name) => only.length === 0 || only.includes(name))
    .sort();

const fontCss = fontFaces();
const browser = await chromium.launch();
const results = [];

for (const name of names) {
    const reference = join(here, 'reference', `${name}.pdf`);

    if (!existsSync(reference)) {
        console.warn(`${name}: no reference PDF, run export-references.sh`);
        continue;
    }

    const converted = JSON.parse(execFileSync('php', [join(here, 'convert.php'), join(here, 'corpus', `${name}.docx`)], { maxBuffer: 256 << 20 }).toString());
    const ours = await print(converted, name);
    const theirs = new Uint8Array(readFileSync(reference));

    const [wordPages, htmlPages] = await Promise.all([rasterize(theirs), rasterize(ours)]);
    const pixels = comparePages(wordPages, htmlPages, name);
    const words = compareWords(await words_(theirs), await words_(ours));

    const result = { name, wordPages: wordPages.length, htmlPages: htmlPages.length, pixelSimilarity: pixels, ...words, warnings: converted.warnings.length };
    results.push(result);
    console.log(format(result));
}

await browser.close();
writeFileSync(join(report, 'summary.json'), JSON.stringify(results, null, 2));
writeFileSync(join(report, 'summary.md'), summary(results));
console.log(`\n${summary(results)}`);

/** Prints the converted HTML to PDF the way the document asks to be printed. */
async function print(converted, name) {
    const { page: geometry } = converted;
    // The page box and margins are print setup, which a document fragment
    // cannot carry; fonts are what Word has and a browser usually does not.
    const setup = `<style>${fontCss}
@page { size: ${geometry.width}pt ${geometry.height}pt; margin: ${geometry.top}pt ${geometry.right}pt ${geometry.bottom}pt ${geometry.left}pt; }
html, body { margin: 0; padding: 0; }
</style>`;
    const html = converted.html.replace('</head>', `${setup}\n</head>`);
    const file = join(report, `${name}.html`);
    writeFileSync(file, html);

    const page = await browser.newPage();
    await page.goto(pathToFileURL(file).href, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts.ready);
    const pdf = await page.pdf({ preferCSSPageSize: true, printBackground: true });
    await page.close();
    writeFileSync(join(report, `${name}.html.pdf`), pdf);

    return new Uint8Array(pdf);
}

async function rasterize(bytes) {
    const pdf = await getDocument({ data: bytes.slice(), disableFontFace: true, useSystemFonts: false, verbosity: 0 }).promise;
    const pages = [];

    for (let number = 1; number <= pdf.numPages; number++) {
        const page = await pdf.getPage(number);
        const viewport = page.getViewport({ scale });
        const canvas = createCanvas(Math.ceil(viewport.width), Math.ceil(viewport.height));
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        await page.render({ canvasContext: context, viewport, canvas }).promise;
        pages.push({ width: canvas.width, height: canvas.height, data: context.getImageData(0, 0, canvas.width, canvas.height).data });
    }

    return pages;
}

/** Mean similarity over pages; a page only one side has counts as 0. */
function comparePages(word, html, name) {
    const count = Math.max(word.length, html.length);
    let total = 0;

    for (let index = 0; index < count; index++) {
        const a = word[index];
        const b = html[index];

        if (!a || !b) {
            continue;
        }

        const width = Math.min(a.width, b.width);
        const height = Math.min(a.height, b.height);
        const left = crop(a, width, height);
        const right = crop(b, width, height);
        const diff = new PNG({ width, height });
        const different = pixelmatch(left, right, diff.data, width, height, { threshold: 0.2, includeAA: false });
        const ink = inkUnion(left, right);
        total += ink === 0 ? 1 : Math.max(0, 1 - different / ink);

        writeFileSync(join(report, `${name}-p${index + 1}-diff.png`), PNG.sync.write(diff));
        writeFileSync(join(report, `${name}-p${index + 1}-word.png`), toPng(left, width, height));
        writeFileSync(join(report, `${name}-p${index + 1}-html.png`), toPng(right, width, height));
    }

    return count === 0 ? 1 : total / count;
}

function crop(image, width, height) {
    const out = new Uint8ClampedArray(width * height * 4);

    for (let y = 0; y < height; y++) {
        out.set(image.data.subarray(y * image.width * 4, y * image.width * 4 + width * 4), y * width * 4);
    }

    return out;
}

function inkUnion(a, b) {
    let ink = 0;

    for (let i = 0; i < a.length; i += 4) {
        if (a[i] + a[i + 1] + a[i + 2] < 720 || b[i] + b[i + 1] + b[i + 2] < 720) {
            ink++;
        }
    }

    return ink;
}

function toPng(data, width, height) {
    const png = new PNG({ width, height });
    png.data = Buffer.from(data);

    return PNG.sync.write(png);
}

/** Every word of a PDF with its page and position (points, from the top left). */
async function words_(bytes) {
    const pdf = await getDocument({ data: bytes.slice(), verbosity: 0 }).promise;
    const words = [];

    for (let number = 1; number <= pdf.numPages; number++) {
        const page = await pdf.getPage(number);
        const height = page.getViewport({ scale: 1 }).height;
        const content = await page.getTextContent();

        for (const item of content.items) {
            const text = item.str ?? '';

            if (text.trim() === '') {
                continue;
            }

            // Split runs into words, placing each proportionally along the run.
            const perChar = text.length === 0 ? 0 : item.width / text.length;

            for (const match of text.matchAll(/\S+/g)) {
                words.push({
                    text: match[0].normalize('NFC'),
                    page: number,
                    x: item.transform[4] + perChar * match.index,
                    y: height - item.transform[5],
                });
            }
        }
    }

    return words;
}

/** Longest common subsequence of the two word streams, then placement stats. */
function compareWords(word, html) {
    const n = word.length;
    const m = html.length;
    const matches = [];

    if (n > 0 && m > 0 && n * m <= 25_000_000) {
        const width = m + 1;
        const table = new Uint32Array((n + 1) * width);

        for (let i = n - 1; i >= 0; i--) {
            for (let j = m - 1; j >= 0; j--) {
                table[i * width + j] = word[i].text === html[j].text
                    ? table[(i + 1) * width + j + 1] + 1
                    : Math.max(table[(i + 1) * width + j], table[i * width + j + 1]);
            }
        }

        for (let i = 0, j = 0; i < n && j < m;) {
            if (word[i].text === html[j].text) {
                matches.push([word[i], html[j]]);
                i++;
                j++;
            } else if (table[(i + 1) * width + j] >= table[i * width + j + 1]) {
                i++;
            } else {
                j++;
            }
        }
    }

    const samePage = matches.filter(([a, b]) => a.page === b.page);
    const dx = samePage.map(([a, b]) => Math.abs(a.x - b.x)).sort((a, b) => a - b);
    const dy = samePage.map(([a, b]) => Math.abs(a.y - b.y)).sort((a, b) => a - b);
    const quantile = (values, q) => (values.length === 0 ? null : values[Math.min(values.length - 1, Math.floor(q * values.length))]);

    return {
        words: n,
        matched: n === 0 ? 1 : matches.length / n,
        samePage: matches.length === 0 ? 0 : samePage.length / matches.length,
        dxMedian: quantile(dx, 0.5),
        dyMedian: quantile(dy, 0.5),
        dyP90: quantile(dy, 0.9),
    };
}

function format(r) {
    const pt = (value) => (value === null ? '—' : `${value.toFixed(1)}pt`);
    const pct = (value) => `${(value * 100).toFixed(1)}%`;

    return `${r.name}: pages ${r.htmlPages}/${r.wordPages}, pixels ${pct(r.pixelSimilarity)}, words matched ${pct(r.matched)}, same page ${pct(r.samePage)}, dx ${pt(r.dxMedian)}, dy ${pt(r.dyMedian)} (p90 ${pt(r.dyP90)})`;
}

function summary(results) {
    const pt = (value) => (value === null ? '—' : value.toFixed(1));
    const pct = (value) => (value * 100).toFixed(1);
    const rows = results.map((r) => `| ${r.name} | ${r.htmlPages}/${r.wordPages} | ${pct(r.pixelSimilarity)} | ${pct(r.matched)} | ${pct(r.samePage)} | ${pt(r.dxMedian)} | ${pt(r.dyMedian)} | ${pt(r.dyP90)} |`);
    const mean = (key) => results.reduce((sum, r) => sum + r[key], 0) / Math.max(1, results.length);

    return [
        '| Document | Pages (HTML/Word) | Pixels % | Words matched % | Same page % | dx median pt | dy median pt | dy p90 pt |',
        '| --- | --- | --- | --- | --- | --- | --- | --- |',
        ...rows,
        `| **Mean** | | **${pct(mean('pixelSimilarity'))}** | **${pct(mean('matched'))}** | **${pct(mean('samePage'))}** | | | |`,
        '',
    ].join('\n');
}
