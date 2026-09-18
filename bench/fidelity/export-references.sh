#!/bin/sh
# Prints every corpus document to reference/<name>.pdf with Microsoft Word
# (macOS only). Run it after adding or changing a document in corpus/.
set -eu
cd "$(dirname "$0")"
# Word is sandboxed: it may only touch files inside its own container.
work="$HOME/Library/Containers/com.microsoft.Word/Data/htmldocx-bench"
mkdir -p "$work"
for docx in corpus/*.docx; do
    name=$(basename "$docx" .docx)
    cp "$docx" "$work/$name.docx"
    osascript <<APPLESCRIPT
with timeout of 180 seconds
    tell application "Microsoft Word"
        open POSIX file "$work/$name.docx"
        set d to active document
        save as d file name "$work/$name.pdf" file format format PDF
        close active document saving no
    end tell
end timeout
APPLESCRIPT
    cp "$work/$name.pdf" "reference/$name.pdf"
    echo "reference/$name.pdf"
done
