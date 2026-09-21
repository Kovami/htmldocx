<?php

declare(strict_types=1);

/*
 * The body-only variant of a corpus document: no headers or footers, no
 * footnotes or endnotes and no comments. Those differ from Word by design
 * (plain HTML has no page furniture; a browser prints notes at the end, Word
 * at the page foot; SunEditor HTML lists comments after the text, Word's
 * print leaves them out),
 * so the release criterion is judged on the body alone: Word prints this
 * variant to reference/<name>.body.pdf and the bench converts the same file.
 *
 * Usage: php body.php <in.docx> <out.docx>
 * Exits 0 when it wrote a variant, 3 when the document has nothing to strip.
 */

/** Writes the body-only variant of `$in` to `$out`; false when there is nothing to strip. */
function bodyOnly(string $in, string $out): bool
{
    $zip = new ZipArchive();
    $zip->open($in) === true || throw new RuntimeException("cannot open {$in}");
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $strip = $xpath->query('//w:sectPr/w:headerReference | //w:sectPr/w:footerReference | //w:sectPr/w:titlePg | //w:r[w:footnoteReference or w:endnoteReference or w:commentReference] | //w:commentRangeStart | //w:commentRangeEnd');

    if ($strip->length === 0) {
        return false;
    }

    foreach (iterator_to_array($strip) as $node) {
        $node->parentNode->removeChild($node);
    }

    copy($in, $out) || throw new RuntimeException("cannot write {$out}");
    $zip->open($out) === true || throw new RuntimeException("cannot open {$out}");
    $zip->addFromString('word/document.xml', $dom->saveXML());

    if ($zip->locateName('word/comments.xml') !== false) {
        $zip->addFromString('word/comments.xml', '<w:comments xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');
    }

    $zip->close();

    return true;
}

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(bodyOnly($argv[1], $argv[2]) ? 0 : 3);
}
