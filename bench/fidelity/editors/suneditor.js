// SunEditor 3 with all of its plugins. The recommended configuration adds
// what the README suggests: KaTeX for formulas, the tags the library writes,
// and strict mode without its attribute and style filters, so the formatting
// the library spells out on every block survives.
import katex from 'katex';
import suneditor from 'suneditor';
import plugins from 'suneditor/plugins';

window.roundTrip = async (html, recommended) => {
    const editor = suneditor.create(document.querySelector('#editor'), recommended
        ? {
            plugins,
            externalLibs: { katex: { src: katex } },
            elementWhitelist: 'section|colgroup|col',
            attributeWhitelist: { '*': 'style|id|role|start|value|data-[^\\s]+' },
            strictMode: { tagFilter: true, formatFilter: true, classFilter: true, textStyleTagFilter: true, attrFilter: false, styleFilter: false },
        }
        : { plugins });
    editor.$.html.set(html);

    return editor.$.html.get();
};
