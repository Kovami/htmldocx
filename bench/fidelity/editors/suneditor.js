// SunEditor 2 with all of its plugins. The recommended configuration adds
// what the README suggests: KaTeX for formulas, and whitelists that keep the
// styles, attributes and tags the library writes.
import katex from 'katex';
import suneditor from 'suneditor';
import plugins from 'suneditor/src/plugins';

window.roundTrip = async (html, recommended) => {
    const { math, ...rest } = plugins;
    const editor = suneditor.create(document.querySelector('#editor'), recommended
        ? {
            plugins,
            katex,
            // A plugin only starts when a button uses it.
            buttonList: [['font', 'fontSize', 'formatBlock', 'bold', 'underline', 'italic', 'strike', 'subscript', 'superscript',
                'fontColor', 'hiliteColor', 'align', 'list', 'lineHeight', 'table', 'link', 'image', 'math']],
            attributesWhitelist: { all: 'style|id|role|data-.+' },
            addTagsWhitelist: 'section|colgroup|col',
        }
        : { plugins: rest });
    editor.setContents(html);

    // No destroy(): it trips over SunEditor's own resize timer, and the page is closed anyway.
    return editor.getContents();
};
