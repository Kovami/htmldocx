// SunEditor 2 with all of its plugins except math, which needs KaTeX.
import suneditor from 'suneditor';
import plugins from 'suneditor/src/plugins';

window.roundTrip = async (html) => {
    const { math, ...rest } = plugins;
    const editor = suneditor.create(document.querySelector('#editor'), { plugins: rest });
    editor.setContents(html);
    // No destroy(): it trips over SunEditor's own resize timer, and the page is closed anyway.
    return editor.getContents();
};
