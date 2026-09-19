// Fidelity bench: how closely does the HTML written by kovami/htmldocx look
// like the same document printed by Microsoft Word?
//
// For every corpus/<name>.docx: the library converts it to a full HTML
// document, Chromium prints that HTML at the document's page size, and the
// result is compared with reference/<name>.pdf, which Word printed
// (export-references.sh). Two measures per document:
//
//  - ink match: pages rendered to images; the share of the ink on either
//    side that has ink on the other side within 2 px (1.33 pt), so a slight
//    shift costs little and a wrong font, size or layout costs what it moves;
//  - body ink match: the same for the body alone, without headers, footers
//    and notes, which differ from Word by design (body.php; Word's print of
//    it is reference/<name>.body.pdf) — the score the release is judged on;
//  - word placement: every word of Word's PDF is matched with the same word
//    in ours, and we report how many sit on the same page and how far they
//    moved, in points.
//
// Usage: npm run bench [-- name ...]   (report/ gets images and summary)
//        PROFILE=suneditor npm run bench   (an editor profile instead of plain HTML)

import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { basename, join, resolve } from 'node:path';
import { chromium } from 'playwright';
import { format, measure, summary } from './measure.mjs';

const here = resolve(import.meta.dirname);
const report = join(here, 'report');
mkdirSync(report, { recursive: true });

const only = process.argv.slice(2);
const profile = process.env.PROFILE ?? 'plain';
const names = readdirSync(join(here, 'corpus'))
    .filter((file) => file.endsWith('.docx'))
    .map((file) => basename(file, '.docx'))
    .filter((name) => only.length === 0 || only.includes(name))
    .sort();

const browser = await chromium.launch();
const results = [];

for (const name of names) {
    const reference = join(here, 'reference', `${name}.pdf`);

    if (!existsSync(reference)) {
        console.warn(`${name}: no reference PDF, run export-references.sh`);
        continue;
    }

    const converted = convert(name);
    const scores = await measure(browser, converted.html, converted.page, new Uint8Array(readFileSync(reference)), report, name);
    const bodyReference = join(here, 'reference', `${name}.body.pdf`);
    let bodyInk = scores.pixelSimilarity;

    if (existsSync(bodyReference)) {
        const body = convert(name, 'body');
        bodyInk = (await measure(browser, body.html, body.page, new Uint8Array(readFileSync(bodyReference)), report, `${name}.body`)).pixelSimilarity;
    }

    const result = { ...scores, bodyInk, warnings: converted.warnings.length };
    results.push(result);
    console.log(format(result));
}

await browser.close();
writeFileSync(join(report, 'summary.json'), JSON.stringify(results, null, 2));
writeFileSync(join(report, 'summary.md'), summary(results));
console.log(`\n${summary(results)}`);

function convert(name, variant = 'full') {
    const args = [join(here, 'convert.php'), join(here, 'corpus', `${name}.docx`), profile, variant];

    return JSON.parse(execFileSync('php', args, { maxBuffer: 256 << 20 }).toString());
}
