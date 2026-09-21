// Where each line of text landed in two prints of the same document: the
// first PDF is the reference, the second the result. Lines are matched by
// their first characters (list markers ignored); dy > 0 means the result
// sits lower. A diagnostic for the ink scores run.mjs and html.mjs report.
//
// Usage: npm run lines -- report/html/plain-text.html.pdf report/html/plain-text.word.pdf

import { readFileSync } from 'node:fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';

async function lines(file) {
    const pdf = await getDocument({ data: new Uint8Array(readFileSync(file)), verbosity: 0 }).promise;
    const out = [];

    for (let number = 1; number <= pdf.numPages; number++) {
        const page = await pdf.getPage(number);
        const height = page.getViewport({ scale: 1 }).height;

        for (const item of (await page.getTextContent()).items) {
            if (!item.str.trim()) {
                continue;
            }

            const y = height - item.transform[5];
            const last = out.at(-1);

            // Runs within 2 pt of each other are one line (sub- and superscripts included).
            if (last && last.page === number && Math.abs(last.y - y) < 2) {
                last.text += ' ' + item.str;
            } else {
                out.push({ page: number, y, x: item.transform[4], text: item.str });
            }
        }
    }

    return out;
}

const [reference, result] = await Promise.all(process.argv.slice(2, 4).map(lines));
const key = (text) => text.replace(/^[•o§▪\-–]\s*/, '').replace(/\s+/g, '').slice(0, 12);
let from = 0;

for (const line of reference) {
    if (line.text.replace(/\s/g, '').length < 4) {
        continue;
    }

    const index = result.findIndex((other, i) => i >= from && key(other.text) === key(line.text));

    if (index < 0) {
        console.log(`${' '.repeat(33)}(unmatched) ${line.text.slice(0, 50)}`);
        continue;
    }

    const other = result[index];
    from = index + 1;
    const dy = other.page === line.page ? (other.y - line.y).toFixed(2) : `p${other.page}`;
    console.log(`${line.page}:${line.y.toFixed(2).padStart(7)}  ${other.page}:${other.y.toFixed(2).padStart(7)}  dy ${dy.padStart(6)}  dx ${(other.x - line.x).toFixed(1).padStart(5)}  ${line.text.slice(0, 50)}`);
}
