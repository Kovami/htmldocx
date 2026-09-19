// TinyMCE with the plugins an application that edits Word-like documents
// enables, in their default configuration. Loaded after tinymce.min.js.
window.roundTrip = async (html) => {
    const [editor] = await window.tinymce.init({
        target: document.querySelector('#editor'),
        license_key: 'gpl',
        promotion: false,
        plugins: 'lists advlist table link image code',
    });
    editor.setContent(html);
    const out = editor.getContent();
    editor.remove();

    return out;
};
