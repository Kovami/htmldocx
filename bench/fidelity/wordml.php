<?php

declare(strict_types=1);

// Helpers that write WordprocessingML onto Word's own blank package
// (source/base.docx): shared by make-corpus.php and the README showcase.

function p(string $text, ?string $styleId = null, string $pPr = '', string $rPr = ''): string
{
    return pr($text === '' ? [] : [r($text, $rPr)], $styleId, $pPr);
}

/**
 * @param  list<string>  $runs
 */
function pr(array $runs, ?string $styleId = null, string $pPr = '', string $before = ''): string
{
    $properties = ($styleId === null ? '' : "<w:pStyle w:val=\"{$styleId}\"/>") . $pPr;

    return '<w:p>' . ($properties === '' ? '' : "<w:pPr>{$properties}</w:pPr>") . $before . implode('', $runs) . '</w:p>';
}

function r(string $text, string $rPr = ''): string
{
    return '<w:r>' . ($rPr === '' ? '' : "<w:rPr>{$rPr}</w:rPr>")
        . '<w:t xml:space="preserve">' . htmlspecialchars($text, ENT_XML1) . '</w:t></w:r>';
}

function font(string $name): string
{
    return "<w:rFonts w:ascii=\"{$name}\" w:hAnsi=\"{$name}\" w:cs=\"{$name}\"/>";
}

function drawing(string $relationshipId, int $id, int $widthPx, int $heightPx): string
{
    $cx = $widthPx * 9525;
    $cy = $heightPx * 9525;

    return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
        . "<wp:extent cx=\"{$cx}\" cy=\"{$cy}\"/><wp:docPr id=\"{$id}\" name=\"Picture {$id}\"/>"
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . "<pic:nvPicPr><pic:cNvPr id=\"{$id}\" name=\"Picture {$id}\"/><pic:cNvPicPr/></pic:nvPicPr>"
        . "<pic:blipFill><a:blip r:embed=\"{$relationshipId}\"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>"
        . "<pic:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"{$cx}\" cy=\"{$cy}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></pic:spPr>"
        . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

/**
 * @param  list<array{0: string, 1: string, 2: int, 3: int, 4?: string, 5?: string}>  $levels  format, text, left, hanging, justification, font
 */
function abstractNum(int $id, array $levels): string
{
    $xml = "<w:abstractNum w:abstractNumId=\"{$id}\"><w:multiLevelType w:val=\"hybridMultilevel\"/>";

    foreach ($levels as $level => $definition) {
        [$format, $text, $left, $hanging] = $definition;
        $jc = $definition[4] ?? 'left';
        $font = isset($definition[5]) ? '<w:rPr>' . font($definition[5]) . '</w:rPr>' : '';

        $xml .= "<w:lvl w:ilvl=\"{$level}\"><w:start w:val=\"1\"/><w:numFmt w:val=\"{$format}\"/>"
            . '<w:lvlText w:val="' . htmlspecialchars($text, ENT_XML1) . "\"/><w:lvlJc w:val=\"{$jc}\"/>"
            . "<w:pPr><w:ind w:left=\"{$left}\" w:hanging=\"{$hanging}\"/></w:pPr>{$font}</w:lvl>";
    }

    return $xml . '</w:abstractNum>';
}

/** Word's own definition of the "Table Grid" table style. */
function tableGridStyle(): string
{
    $edge = static fn(string $side): string => "<w:{$side} w:val=\"single\" w:sz=\"4\" w:space=\"0\" w:color=\"auto\"/>";

    return '<w:style w:type="table" w:styleId="TableGridBench"><w:name w:val="Table Grid"/><w:basedOn w:val="a1"/><w:uiPriority w:val="39"/>'
        . '<w:pPr><w:spacing w:after="0" w:line="240" w:lineRule="auto"/></w:pPr>'
        . '<w:tblPr><w:tblBorders>' . implode('', array_map($edge, ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'])) . '</w:tblBorders></w:tblPr></w:style>';
}

/** Deterministic filler text of about $words words. */
function text(int $words, int $seed = 0): string
{
    static $vocabulary = ['the', 'report', 'shows', 'how', 'each', 'region', 'grew', 'during', 'year', 'while', 'costs', 'stayed', 'close', 'to', 'plan',
        'and', 'several', 'teams', 'moved', 'their', 'work', 'into', 'new', 'offices', 'which', 'took', 'longer', 'than', 'expected', 'but', 'paid', 'off',
        'customers', 'noticed', 'faster', 'answers', 'fewer', 'errors', 'better', 'documents', 'in', 'every', 'quarter', 'of', 'a', 'measured', 'way'];

    $out = [];
    $state = $seed * 7919 + $words;

    for ($i = 0; $i < $words; $i++) {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;
        $out[] = $vocabulary[$state % count($vocabulary)];
    }

    $sentence = ucfirst(implode(' ', $out));

    return (string) preg_replace_callback('/(\S+ \S+ \S+ \S+ \S+ \S+ \S+ \S+ \S+ \S+ \S+)( )(\S)/', static fn(array $m): string => $m[1] . '. ' . strtoupper($m[3]), $sentence) . '.';
}

function png(int $width, int $height, array $rgb, string $label): string
{
    $image = imagecreatetruecolor($width, $height);
    $background = imagecolorallocate($image, ...$rgb);
    $light = imagecolorallocate($image, 255, 255, 255);
    imagefilledrectangle($image, 0, 0, $width, $height, $background);

    for ($x = 0; $x < $width; $x += max(8, intdiv($width, 12))) {
        imageline($image, $x, 0, $width - 1, $height - 1 - intdiv($x * $height, max(1, $width)), $light);
    }

    imagestring($image, 5, 6, 6, $label, $light);
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// Package assembly

/** @return array<string, string> lower-case style name => styleId */
function styleIds(string $base): array
{
    $zip = new ZipArchive();
    $zip->open($base);
    $xml = (string) $zip->getFromName('word/styles.xml');
    $zip->close();

    preg_match_all('/w:styleId="([^"]+)"[^>]*>\s*<w:name w:val="([^"]+)"/', $xml, $matches, PREG_SET_ORDER);
    $ids = [];

    foreach ($matches as [, $id, $name]) {
        $ids[strtolower($name)] = $id;
    }

    return $ids;
}

/**
 * `parts` adds package parts by relationship id: [file name in word/, relationship
 * type (footnotes, endnotes, comments, header, footer), XML]; `section` goes first
 * in the body's section properties (header and footer references).
 *
 * @param  array{body: list<string>, numbering?: string, styles?: string, media?: array<string, string>, relationships?: array<string, string>, parts?: array<string, array{0: string, 1: string, 2: string}>, section?: string}  $document
 */
function build(string $base, string $target, array $document): void
{
    copy($base, $target);
    $zip = new ZipArchive();
    $zip->open($target);

    $main = (string) $zip->getFromName('word/document.xml');
    $sectPr = preg_match('~<w:sectPr\b.*</w:sectPr>~s', $main, $m) === 1 ? $m[0] : '';
    $sectPr = (string) preg_replace('~^<w:sectPr\b[^>]*>~', '$0' . str_replace('$', '\\$', $document['section'] ?? ''), $sectPr);
    $main = (string) preg_replace('~<w:body>.*</w:body>~s', '<w:body>' . implode('', $document['body']) . $sectPr . '</w:body>', $main);

    if (! str_contains($main, 'xmlns:wp=')) {
        $main = str_replace('<w:document ', '<w:document xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" ', $main);
    }

    $zip->addFromString('word/document.xml', $main);

    $relationships = (string) $zip->getFromName('word/_rels/document.xml.rels');
    $types = (string) $zip->getFromName('[Content_Types].xml');

    if (isset($document['styles'])) {
        $styles = (string) $zip->getFromName('word/styles.xml');
        $zip->addFromString('word/styles.xml', str_replace('</w:styles>', $document['styles'] . '</w:styles>', $styles));
    }

    if (isset($document['numbering'])) {
        $zip->addFromString('word/numbering.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $document['numbering'] . '</w:numbering>');
        $relationships = str_replace('</Relationships>', '<Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/></Relationships>', $relationships);
        $types = str_replace('</Types>', '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/></Types>', $types);
    }

    foreach ($document['parts'] ?? [] as $id => [$name, $type, $xml]) {
        $zip->addFromString("word/{$name}", '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $xml);
        $relationships = str_replace('</Relationships>', "<Relationship Id=\"{$id}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/{$type}\" Target=\"{$name}\"/></Relationships>", $relationships);
        $types = str_replace('</Types>', "<Override PartName=\"/word/{$name}\" ContentType=\"application/vnd.openxmlformats-officedocument.wordprocessingml.{$type}+xml\"/></Types>", $types);
    }

    foreach ($document['media'] ?? [] as $name => $bytes) {
        $zip->addFromString("word/media/{$name}", $bytes);
    }

    foreach ($document['relationships'] ?? [] as $id => $path) {
        $relationships = str_replace('</Relationships>', "<Relationship Id=\"{$id}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/image\" Target=\"{$path}\"/></Relationships>", $relationships);
    }

    if (($document['media'] ?? []) !== [] && ! str_contains($types, 'Extension="png"')) {
        $types = str_replace('<Default Extension="xml"', '<Default Extension="png" ContentType="image/png"/><Default Extension="xml"', $types);
    }

    $zip->addFromString('word/_rels/document.xml.rels', $relationships);
    $zip->addFromString('[Content_Types].xml', $types);
    $zip->close();
}
