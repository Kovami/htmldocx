// TipTap with the extensions an application that edits Word-like documents
// enables: tables, text style (font, size, colour, background, line height),
// alignment, images, scripts and highlight. The recommended configuration
// adds what the README suggests: block styles kept, inline images, formulas.
import { Editor, Extension } from '@tiptap/core';
import Highlight from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
import { Mathematics } from '@tiptap/extension-mathematics';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { TableKit } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyleKit } from '@tiptap/extension-text-style';
import StarterKit from '@tiptap/starter-kit';

/** Keeps the inline style of blocks, which TipTap drops unless a node declares it. */
const KeepBlockStyles = Extension.create({
    name: 'keepBlockStyles',
    addGlobalAttributes() {
        return [{
            types: ['paragraph', 'heading', 'bulletList', 'orderedList', 'listItem', 'table', 'tableRow', 'tableCell', 'tableHeader', 'blockquote'],
            attributes: {
                style: {
                    default: null,
                    parseHTML: (element) => element.getAttribute('style'),
                    renderHTML: (attributes) => (attributes.style ? { style: attributes.style } : {}),
                },
            },
        }];
    },
});

window.roundTrip = async (html, recommended) => {
    const editor = new Editor({
        element: document.querySelector('#editor'),
        extensions: [
            StarterKit,
            TableKit,
            TextStyleKit,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Image.configure({ inline: recommended }),
            Subscript,
            Superscript,
            Highlight.configure({ multicolor: true }),
            ...(recommended ? [KeepBlockStyles, Mathematics] : []),
        ],
        content: html,
    });
    const out = editor.getHTML();
    editor.destroy();

    return out;
};
