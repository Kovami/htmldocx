// CKEditor 5 with the plugins an application that edits Word-like documents
// enables, in their default configuration. Loaded after the UMD build.
window.roundTrip = async (html) => {
    const c = window.CKEDITOR;
    const editor = await c.ClassicEditor.create(document.querySelector('#editor'), {
        licenseKey: 'GPL',
        plugins: [
            c.Essentials, c.Paragraph, c.Heading, c.Bold, c.Italic, c.Underline, c.Strikethrough, c.Subscript,
            c.Superscript, c.Font, c.Highlight, c.Alignment, c.List, c.ListProperties, c.Indent, c.IndentBlock,
            c.Link, c.Table, c.TableProperties, c.TableCellProperties, c.TableColumnResize, c.Image, c.ImageInline,
            c.ImageBlock, c.ImageStyle, c.ImageResize, c.BlockQuote, c.CodeBlock, c.HorizontalLine, c.PageBreak,
        ],
        initialData: html,
    });
    const out = editor.getData();
    await editor.destroy();

    return out;
};
