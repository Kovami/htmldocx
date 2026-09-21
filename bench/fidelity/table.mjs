// Writes the README's fidelity tables from the latest bench results:
// report/summary.json (npm run bench, plain HTML), report/editors/summary.json
// (npm run editors, each editor's recommended configuration) and
// report/html/summary.json (npm run html). Both READMEs get the tables
// between their <!-- bench:start --> and <!-- bench:end --> markers; a cell
// with no result yet shows a dash.
//
// Usage: npm run table

import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const here = resolve(import.meta.dirname);
const read = (file) => (existsSync(join(here, 'report', file)) ? JSON.parse(readFileSync(join(here, 'report', file), 'utf8')) : []);
const plain = read('summary.json');
const editors = read('editors/summary.json');
const html = read('html/summary.json');

// Columns: the two profiles the library is tuned for first, then the others.
const PROFILES = [['plain', 'Plain HTML'], ['suneditor', 'SunEditor'], ['ckeditor', 'CKEditor'], ['tinymce', 'TinyMCE'], ['tiptap', 'TipTap']];

const DOCX_ROWS = {
    en: [['formatting-ru', 'Text and character formatting'], ['typography', 'Fonts and typography'], ['lists', 'Lists'], ['tables', 'Tables'],
        ['images', 'Pictures'], ['long', 'A six-page report'], ['notes', 'Footnotes and endnotes'], ['furniture-comments', 'Headers, footers, comments']],
    ru: [['formatting-ru', 'Текст и форматирование знаков'], ['typography', 'Шрифты и типографика'], ['lists', 'Списки'], ['tables', 'Таблицы'],
        ['images', 'Изображения'], ['long', 'Отчёт на шесть страниц'], ['notes', 'Сноски'], ['furniture-comments', 'Колонтитулы, комментарии']],
};
const HTML_ROWS = {
    en: [['text', 'Text and character formatting'], ['lists', 'Lists'], ['tables', 'Tables'], ['images', 'Pictures']],
    ru: [['text', 'Текст и форматирование знаков'], ['lists', 'Списки'], ['tables', 'Таблицы'], ['images', 'Изображения']],
};
const WORDS = {
    en: { document: 'Document', mean: 'Mean', docx: '**DOCX → HTML**: Word prints the document; the library\'s HTML — passed through the editor with the configuration below, and shown with its content stylesheet — is printed by Chromium on the same page, body text only (headers, footers and notes differ by design).', html: '**HTML → DOCX**: the editor\'s own HTML shown in Chromium with its content stylesheet, against Word\'s print of the DOCX the library writes from it.', unit: 'Share of the ink in place within 1.33 pt, higher is better.' },
    ru: { document: 'Документ', mean: 'Среднее', docx: '**DOCX → HTML**: документ печатает Word; HTML библиотеки — пропущенный через редактор с конфигурацией ниже и показанный с его стилями контента — печатает Chromium на той же странице, только основной текст (колонтитулы и сноски расходятся намеренно).', html: '**HTML → DOCX**: собственный HTML редактора в Chromium с его стилями контента против того, как Word печатает DOCX, который из него пишет библиотека.', unit: 'Доля краски на своём месте с точностью 1,33 pt, чем больше, тем лучше.' },
};

function docxScore(profile, name) {
    const entry = profile === 'plain'
        ? plain.find((r) => r.name === name)
        : editors.find((r) => r.editor === `${profile} recommended` && r.name === name);

    return profile === 'plain' ? entry?.bodyInk ?? null : entry?.bodyPixels ?? null;
}

const htmlScore = (profile, name) => html.find((r) => r.name === `${profile}-${name}`)?.pixelSimilarity ?? null;

function table(language, rows, score, heading) {
    const words = WORDS[language];
    const format = (value, bold = false) => (value === null ? '—' : `${bold ? '**' : ''}${(value * 100).toFixed(1).replace('.', language === 'ru' ? ',' : '.')}%${bold ? '**' : ''}`);
    const lines = [
        heading,
        '',
        `| ${words.document} | ${PROFILES.map(([, label]) => label).join(' | ')} |`,
        `| --- | ${PROFILES.map(() => '---:').join(' | ')} |`,
        ...rows.map(([name, label]) => `| ${label} | ${PROFILES.map(([profile]) => format(score(profile, name))).join(' | ')} |`),
    ];
    const means = PROFILES.map(([profile]) => {
        const values = rows.map(([name]) => score(profile, name));

        return values.includes(null) ? null : values.reduce((sum, value) => sum + value, 0) / values.length;
    });
    lines.push(`| **${words.mean}** | ${means.map((value) => format(value, true)).join(' | ')} |`);

    return lines.join('\n');
}

for (const [file, language] of [['README.md', 'en'], ['README.ru.md', 'ru']]) {
    const path = join(here, '..', '..', file);
    const text = readFileSync(path, 'utf8');
    const words = WORDS[language];
    const block = [
        '<!-- bench:start (npm run table in bench/fidelity) -->',
        words.unit,
        '',
        table(language, DOCX_ROWS[language], docxScore, words.docx),
        '',
        table(language, HTML_ROWS[language], htmlScore, words.html),
        '<!-- bench:end -->',
    ].join('\n');
    const updated = text.replace(/<!-- bench:start[\s\S]*?<!-- bench:end -->/, block);

    if (updated === text && !text.includes('<!-- bench:start')) {
        console.warn(`${file}: no <!-- bench:start --> marker, nothing written`);
        continue;
    }

    writeFileSync(path, updated);
    console.log(`${file}: tables written`);
}
