// What the editor benches share: putting each editor on a page, and showing
// an editor's HTML the way its docs say to show saved content.

import { writeFileSync, mkdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';
import { build } from 'esbuild';

const here = resolve(import.meta.dirname);

/**
 * How each editor is put on a page (scripts in order, stylesheets, the
 * element it takes over) and how its HTML is shown outside it: the content
 * stylesheet and the class its docs put around saved content.
 */
export const EDITORS = {
    ckeditor: {
        scripts: ['node_modules/ckeditor5/dist/browser/ckeditor5.umd.js', 'editors/ckeditor.js'],
        styles: ['node_modules/ckeditor5/dist/browser/ckeditor5.css'],
        element: 'div',
        content: { css: 'node_modules/ckeditor5/dist/browser/ckeditor5-content.css', className: 'ck-content' },
    },
    tinymce: {
        scripts: ['node_modules/tinymce/tinymce.min.js', 'editors/tinymce.js'],
        styles: [],
        element: 'textarea',
        content: { css: 'node_modules/tinymce/skins/content/default/content.css', className: 'mce-content-body' },
    },
    tiptap: { bundle: 'editors/tiptap.js', styles: [], element: 'div' },
    suneditor: {
        bundle: 'editors/suneditor.js',
        styles: ['node_modules/suneditor/dist/suneditor.min.css'],
        element: 'textarea',
        content: { css: 'node_modules/suneditor/src/assets/suneditor-contents.css', className: 'sun-editor-editable' },
    },
};

/** A page that loads the editor, plus the feature counter the bench runs in the browser. */
export async function harnessFor(editor, report) {
    const { scripts = [], styles, bundle, element } = EDITORS[editor];
    const files = [...scripts.map((script) => join(here, script))];

    if (bundle) {
        const outfile = join(report, 'build', `${editor}.js`);
        await build({ entryPoints: [join(here, bundle)], bundle: true, format: 'iife', outfile, logLevel: 'error' });
        files.push(outfile);
    }

    mkdirSync(report, { recursive: true });
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

/** The editor's HTML as a page shows it: the library's head, the editor's content stylesheet and class. */
export function shown(editor, html, output) {
    const { content } = EDITORS[editor];
    // The container's padding frames the editor, not the document: the page margins do that.
    const link = content
        ? `<link rel="stylesheet" href="${pathToFileURL(join(here, content.css)).href}">\n<style>body.${content.className} { padding: 0; margin: 0; }</style>\n`
        : '';
    const open = content ? `<body class="${content.className}">` : '<body>';

    return html
        .replace('</head>', `${link}</head>`)
        .replace(/<body[^>]*>[\s\S]*<\/body>/, () => `${open}\n${output}\n</body>`);
}

export function body(html) {
    const match = html.match(/<body[^>]*>([\s\S]*)<\/body>/);

    return (match ? match[1] : html).trim();
}

