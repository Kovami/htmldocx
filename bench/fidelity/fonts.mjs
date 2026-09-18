// Word ships its fonts (Aptos, Calibri, Cambria, …) inside the application,
// where a browser does not look. To compare layout rather than font
// substitution, the bench hands the same files to Chromium as @font-face
// rules. Nothing here is copied into the repository.

import { existsSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import * as fontkit from 'fontkit';

const directories = [
    process.env.WORD_FONTS,
    '/Applications/Microsoft Word.app/Contents/Resources/DFonts',
].filter(Boolean);

export function fontFaces() {
    const directory = directories.find((path) => existsSync(path));

    if (!directory) {
        return '';
    }

    const rules = [];

    for (const file of readdirSync(directory)) {
        if (!/\.(ttf|otf)$/i.test(file)) {
            continue;
        }

        try {
            const font = fontkit.openSync(join(directory, file));
            const family = font.familyName;
            const sub = (font.subfamilyName || '').toLowerCase();
            const weight = font['OS/2']?.usWeightClass ?? (sub.includes('bold') ? 700 : 400);
            const style = sub.includes('italic') || sub.includes('oblique') ? 'italic' : 'normal';

            rules.push(`@font-face { font-family: "${family}"; src: url("${pathToFileURL(join(directory, file)).href}"); font-weight: ${weight}; font-style: ${style}; }`);
        } catch {
            // Not every file in the folder is a font fontkit can read; skip it.
        }
    }

    return rules.join('\n');
}
