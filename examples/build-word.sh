#!/bin/sh
# Rebuilds every example with Microsoft Word (macOS only): Word re-saves the
# generated showcase (showcase.docx is what Word itself writes) and prints
# it, build.php converts it with the library, and Word prints the DOCX files
# the library wrote, so the results can be seen without opening Word.
# Then `npm run examples` in bench/fidelity renders the README images.
set -eu
cd "$(dirname "$0")"
# Word is sandboxed: it may only touch files inside its own container.
work="$HOME/Library/Containers/com.microsoft.Word/Data/htmldocx-examples"
rm -rf "$work"
mkdir -p "$work"

# word <in.docx> <out> <format>: Word opens in.docx and saves it as out.
word() {
    osascript <<APPLESCRIPT
with timeout of 180 seconds
    tell application "Microsoft Word"
        open POSIX file "$1"
        try
            set show revisions and comments of view of active window to false
        end try
        save as active document file name "$2" file format $3
        close active document saving no
    end tell
end timeout
APPLESCRIPT
}

php make-showcase.php "$work/source.docx" >/dev/null
word "$work/source.docx" "$work/showcase.docx" "format document default"
cp "$work/showcase.docx" showcase.docx
# Word signs the package with the name of whoever runs it; the showcase is
# written by its fictional company instead.
php -r '$z = new ZipArchive(); $z->open($argv[1]); $x = $z->getFromName("docProps/core.xml");
    $x = preg_replace("~<(dc:creator|cp:lastModifiedBy)>[^<]*</~", "<\$1>Harbor &amp; Pine Co. finance team</", $x);
    $z->addFromString("docProps/core.xml", $x); $z->close();' showcase.docx

php build.php

for docx in showcase.docx editor.docx roundtrip.docx; do
    name=$(basename "$docx" .docx)
    cp "$docx" "$work/$name.docx"
    word "$work/$name.docx" "$work/$name.pdf" "format PDF"
    cp "$work/$name.pdf" "$name.pdf"
    echo "examples/$name.pdf"
done
