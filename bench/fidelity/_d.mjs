import { readFileSync } from 'node:fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
const lines = async (f) => { const pdf = await getDocument({ data: new Uint8Array(readFileSync(f)), verbosity: 0 }).promise; const out = []; for (let n = 1; n <= pdf.numPages; n++) { const c = await (await pdf.getPage(n)).getTextContent(); for (const it of c.items) if (it.str.trim()) out.push([n, it.transform[5], it.str.trim()]); } return out; };
const id = process.argv[2];
const [ref, ours] = [await lines(`report/html/${id}.html.pdf`), await lines(`report/html/${id}.word.pdf`)];
const used = new Set();
for (const [pg, y, s] of ref) { const i = ours.findIndex(([, , t], k) => !used.has(k) && t.slice(0, 8) === s.slice(0, 8)); if (i < 0) { console.log('  --', s.slice(0, 40)); continue; } used.add(i); const [pg2, y2] = ours[i]; console.log(`p${pg}->p${pg2}`, (y - y2 + (pg2 - pg) * 700).toFixed(1).padStart(8), s.slice(0, 45)); }
