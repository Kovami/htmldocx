import { readFileSync } from 'node:fs';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
const name = process.argv[2];
for (const f of [`reference/${name}.pdf`,`report/${name}.html.pdf`]) {
  const pdf = await getDocument({ data: new Uint8Array(readFileSync(f)), verbosity: 0 }).promise;
  const c = await (await pdf.getPage(1)).getTextContent();
  console.log('==', f);
  let last = null;
  for (const it of c.items) if (it.str.trim()) { const y = it.transform[5].toFixed(2); if (y !== last) console.log(it.transform[4].toFixed(2), y, it.transform[0].toFixed(2), JSON.stringify(it.str.slice(0,30))); last = y; }
}
