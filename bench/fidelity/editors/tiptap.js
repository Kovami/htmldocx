// TipTap with the extensions an application that edits Word-like documents
// enables: tables, text style (font, size, colour, background, line height),
// alignment, images, scripts and highlight.
import { Editor } from '@tiptap/core';
import Highlight from '@tiptap/extension-highlight';
import Image from '@tiptap/extension-image';
import Subscript from '@tiptap/extension-subscript';
import Superscript from '@tiptap/extension-superscript';
import { TableKit } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyleKit } from '@tiptap/extension-text-style';
import StarterKit from '@tiptap/starter-kit';

window.roundTrip = async (html) => {
    const editor = new Editor({
        element: document.querySelector('#editor'),
        extensions: [
            StarterKit,
            TableKit,
            TextStyleKit,
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Image,
            Subscript,
            Superscript,
            Highlight.configure({ multicolor: true }),
        ],
        content: html,
    });
    const out = editor.getHTML();
    editor.destroy();

    return out;
};
