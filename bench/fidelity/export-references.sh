#!/bin/sh
# Prints every corpus document to reference/<name>.pdf with Microsoft Word
# (macOS only), and its body-only variant (body.php) to
# reference/<name>.body.pdf when it has headers, footers or notes to strip.
# Run it after adding or changing a document in corpus/.
set -eu
cd "$(dirname "$0")"
# Word is sandboxed: it may only touch files inside its own container.
work="$HOME/Library/Containers/com.microsoft.Word/Data/htmldocx-bench"
mkdir -p "$work"

# print <name>: $work/<name>.docx -> reference/<name>.pdf
print() {
    osascript <<APPLESCRIPT
with timeout of 180 seconds
    tell application "Microsoft Word"
        open POSIX file "$work/$1.docx"
        set d to active document
        -- Print the document itself, without the margin that shows comments.
        try
            set show revisions and comments of view of active window to false
        end try
        save as d file name "$work/$1.pdf" file format format PDF
        close active document saving no
    end tell
end timeout
APPLESCRIPT
    cp "$work/$1.pdf" "reference/$1.pdf"
    echo "reference/$1.pdf"
}

for docx in corpus/*.docx; do
    name=$(basename "$docx" .docx)
    cp "$docx" "$work/$name.docx"
    print "$name"
    rm -f "reference/$name.body.pdf"
    if php body.php "$docx" "$work/$name.body.docx"; then
        print "$name.body"
    fi
done
