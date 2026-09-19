// CKEditor 5 with the plugins an application that edits Word-like documents
// enables. The recommended configuration adds what the README suggests:
// General HTML Support for the styles and attributes no feature owns, and
// fonts of any family and size. Loaded after the UMD build.
window.roundTrip = async (html, recommended) => {
    const c = window.CKEDITOR;
    const editor = await c.ClassicEditor.create(document.querySelector('#editor'), {
        licenseKey: 'GPL',
        plugins: [
            c.Essentials, c.Paragraph, c.Heading, c.Bold, c.Italic, c.Underline, c.Strikethrough, c.Subscript,
            c.Superscript, c.Font, c.Highlight, c.Alignment, c.List, c.ListProperties, c.Indent, c.IndentBlock,
            c.Link, c.Table, c.TableProperties, c.TableCellProperties, c.TableColumnResize, c.Image, c.ImageInline,
            c.ImageBlock, c.ImageStyle, c.ImageResize, c.BlockQuote, c.CodeBlock, c.HorizontalLine, c.PageBreak,
            ...(recommended ? [c.GeneralHtmlSupport] : []),
        ],
        ...(recommended ? {
            htmlSupport: { allow: [{ name: /.*/, attributes: true, classes: true, styles: true }] },
            fontFamily: { supportAllValues: true },
            fontSize: { options: [9, 10, 11, 12, 'default', 14, 16, 18, 20, 24], supportAllValues: true },
        } : {}),
        initialData: html,
    });
    const out = editor.getData();
    await editor.destroy();

    return out;
};
