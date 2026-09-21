// Renders the README images from the examples (examples/build-word.sh first):
//
//  - showcase-p<n>.png: each page as Word prints showcase.docx, next to the
//    library's plain HTML and SunEditor-profile HTML printed by Chromium;
//  - roundtrip-p<n>.png: Word's print of the original next to Word's print
//    of roundtrip.docx (DOCX → HTML → DOCX);
//  - editor-p1.png: editor.html in a browser next to Word's print of the
//    editor.docx the library wrote from it.
//
// It also prints the ink match of the plain HTML for the README.
//
// Usage: npm run examples

import { execFileSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { createCanvas } from '@napi-rs/canvas';
import { chromium } from 'playwright';
import { comparePages, print, rasterize } from './measure.mjs';

const examples = resolve(import.meta.dirname, '../../examples');
const images = join(examples, 'images');
const work = mkdtempSync(join(tmpdir(), 'htmldocx-examples-'));
mkdirSync(images, { recursive: true });

const pdf = async (file) => rasterize(new Uint8Array(readFileSync(join(examples, file))));
const { page } = JSON.parse(execFileSync('php', [join(import.meta.dirname, 'convert.php'), join(examples, 'showcase.docx'), 'plain']).toString());

const browser = await chromium.launch();
const printed = async (file, extra = '') => rasterize(await print(browser, readFileSync(join(examples, file), 'utf8').replace('</body>', `${extra}</body>`), page, work, file));
// SunEditor draws its formula spans (SunEditor 3's se-math, 2's __se__katex) with KaTeX; the page shows them as the editor does.
const katex = pathToFileURL(join(import.meta.dirname, 'node_modules/katex/dist/')).href;
const renderKatex = `<link rel="stylesheet" href="${katex}katex.min.css"><script src="${katex}katex.min.js"></script>
<script>document.querySelectorAll('.se-math, .__se__katex').forEach((span) => katex.render(span.dataset.seValue ?? span.dataset.exp, span, { throwOnError: false }));</script>`;
const word = await pdf('showcase.pdf');
const plain = await printed('showcase.html');
const sunEditor = await printed('showcase.suneditor.html', renderKatex);
await browser.close();

const ink = comparePages(word, plain, work, 'showcase');
console.log(`showcase: plain HTML ink match ${(ink * 100).toFixed(1)}%`);

word.forEach((_, index) => save(`showcase-p${index + 1}.png`, [
    ['Word', word[index]],
    ['HTML (plain)', plain[index]],
    ['HTML (SunEditor profile)', sunEditor[index]],
]));

const roundtrip = await pdf('roundtrip.pdf');
word.forEach((_, index) => save(`roundtrip-p${index + 1}.png`, [
    ['Original DOCX', word[index]],
    ['DOCX → HTML → DOCX', roundtrip[index]],
]));

// The editor HTML as a browser shows it, on the page the DOCX got, in the
// base font the library assumes for text the HTML leaves unstyled.
const editorPage = JSON.parse(execFileSync('php', [join(import.meta.dirname, 'convert.php'), join(examples, 'editor.docx'), 'plain']).toString()).page;
const editorBrowser = await chromium.launch();
const fragment = readFileSync(join(examples, 'editor.html'), 'utf8');
const inBrowser = await rasterize(await print(editorBrowser, `<!DOCTYPE html><html><head><meta charset="utf-8"><base href="${pathToFileURL(examples).href}/"><style>body { font-family: Calibri; font-size: 11pt; }</style></head><body>${fragment}</body></html>`, editorPage, work, 'editor'));
await editorBrowser.close();
save('editor-p1.png', [['editor.html in a browser (Calibri 11pt)', inBrowser[0]], ['editor.docx in Word', (await pdf('editor.pdf'))[0]]]);

/** Pages side by side under their labels, as one PNG in examples/images. */
function save(file, columns) {
    const pages = columns.filter(([, pageImage]) => pageImage);
    const gap = 24;
    const label = 48;
    const width = Math.max(...pages.map(([, p]) => p.width));
    const height = Math.max(...pages.map(([, p]) => p.height));
    const canvas = createCanvas(pages.length * width + (pages.length - 1) * gap, height + label);
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, canvas.width, canvas.height);

    pages.forEach(([title, p], index) => {
        const x = index * (width + gap);
        const data = context.createImageData(p.width, p.height);
        data.data.set(p.data);
        context.putImageData(data, x, label);
        context.strokeStyle = '#bfbfbf';
        context.strokeRect(x + 0.5, label + 0.5, p.width - 1, p.height - 1);
        context.fillStyle = '#404040';
        context.font = '26px sans-serif';
        context.fillText(title, x + 4, 32);
    });

    writeFileSync(join(images, file), canvas.toBuffer('image/png'));
    console.log(`examples/images/${file}`);
}
