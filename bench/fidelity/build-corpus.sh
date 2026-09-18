#!/bin/sh
# Rebuilds the synthetic corpus: make-corpus.php writes the sources, Word
# opens and re-saves each one (so corpus/ holds what Word itself writes),
# then export-references.sh prints every corpus document to a reference PDF.
# macOS with Microsoft Word only.
set -eu
cd "$(dirname "$0")"
work="$HOME/Library/Containers/com.microsoft.Word/Data/htmldocx-bench/sources"
rm -rf "$work"
mkdir -p "$work"
php make-corpus.php "$work" >/dev/null

for source in "$work"/*.docx; do
    name=$(basename "$source" .docx)
    osascript <<APPLESCRIPT
with timeout of 180 seconds
    tell application "Microsoft Word"
        open POSIX file "$source"
        save as active document file name "$work/$name.saved.docx" file format format document default
        close active document saving no
    end tell
end timeout
APPLESCRIPT
    cp "$work/$name.saved.docx" "corpus/$name.docx"
    echo "corpus/$name.docx"
done

./export-references.sh
