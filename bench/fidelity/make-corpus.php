<?php

declare(strict_types=1);

/*
 * Writes the synthetic corpus sources: each document is Word's own blank
 * package (source/base.docx — Word 365 defaults, styles and theme) with a
 * body written here. build-corpus.sh then lets Word open and re-save every
 * one, so the corpus is what Word itself writes, not what this file wrote.
 *
 * Usage: php make-corpus.php <output directory>
 */

$out = rtrim($argv[1] ?? '', '/');

if ($out === '' || ! is_dir($out)) {
    fwrite(STDERR, "usage: php make-corpus.php <existing output directory>\n");
    exit(1);
}

$base = __DIR__ . '/source/base.docx';
$styles = styleIds($base);
$style = static fn(string $name): string => $styles[strtolower($name)] ?? throw new RuntimeException("Word's base package has no style {$name}");

$documents = [
    'typography' => typography($style),
    'lists' => lists($style),
    'tables' => tables($style),
    'images' => images($style),
    'long' => long($style),
];

foreach ($documents as $name => $document) {
    build($base, "{$out}/{$name}.docx", $document);
    echo "{$out}/{$name}.docx\n";
}

// ---------------------------------------------------------------------------
// Documents

function typography(Closure $style): array
{
    $body = [
        p('Typography', $style('Title')),
        p('Character formatting', $style('heading 1')),
        pr([
            r('Plain text, '), r('bold', '<w:b/>'), r(', '), r('italic', '<w:i/>'), r(', '),
            r('underlined', '<w:u w:val="single"/>'), r(', '), r('double underline', '<w:u w:val="double"/>'), r(', '),
            r('struck through', '<w:strike/>'), r(', E = mc'), r('2', '<w:vertAlign w:val="superscript"/>'),
            r(', H'), r('2', '<w:vertAlign w:val="subscript"/>'), r('O, '),
            r('red text', '<w:color w:val="C00000"/>'), r(', '), r('highlighted', '<w:highlight w:val="yellow"/>'), r(', '),
            r('shaded', '<w:shd w:val="clear" w:color="auto" w:fill="DEEAF6"/>'), r(', '),
            r('small caps', '<w:smallCaps/>'), r(', '), r('all caps', '<w:caps/>'), r(' and '),
            r('expanded spacing', '<w:spacing w:val="40"/>'), r('.'),
        ]),
        pr([
            r('Sizes: '), r('9 pt ', '<w:sz w:val="18"/>'), r('11 pt ', '<w:sz w:val="22"/>'),
            r('14 pt ', '<w:sz w:val="28"/>'), r('20 pt', '<w:sz w:val="40"/>'), r('.'),
        ]),
        pr([
            r('Fonts: '), r('Times New Roman', font('Times New Roman')), r(', '), r('Arial', font('Arial')), r(', '),
            r('Calibri', font('Calibri')), r(', '), r('Cambria', font('Cambria')), r(', '),
            r('Courier New', font('Courier New')), r(' and '), r('Georgia', font('Georgia')), r('.'),
        ]),
        p('Paragraph formatting', $style('heading 1')),
        p(text(42), null, '<w:jc w:val="left"/>'),
        p(text(30), null, '<w:jc w:val="center"/>'),
        p(text(30), null, '<w:jc w:val="right"/>'),
        p(text(60), null, '<w:jc w:val="both"/>'),
        p('Indentation', $style('heading 2')),
        p('First line indented by 1.25 cm, as Russian documents usually are. ' . text(40), null, '<w:ind w:firstLine="709"/><w:jc w:val="both"/>'),
        p('Hanging indent of 1 cm. ' . text(35), null, '<w:ind w:left="567" w:hanging="567"/>'),
        p('Indented 2 cm from both margins. ' . text(35), null, '<w:ind w:left="1134" w:right="1134"/>'),
        p('Spacing', $style('heading 2')),
        p('Twelve points before, none after. ' . text(20), null, '<w:spacing w:before="240" w:after="0"/>'),
        p('Single line spacing. ' . text(40), null, '<w:spacing w:line="240" w:lineRule="auto"/>'),
        p('One and a half lines. ' . text(40), null, '<w:spacing w:line="360" w:lineRule="auto"/>'),
        p('Double spacing. ' . text(30), null, '<w:spacing w:line="480" w:lineRule="auto"/>'),
        p('Exactly 18 pt. ' . text(35), null, '<w:spacing w:line="360" w:lineRule="exact"/>'),
        p('At least 20 pt. ' . text(35), null, '<w:spacing w:line="400" w:lineRule="atLeast"/>'),
        p('Headings and quotes', $style('heading 1')),
        p('A third-level heading', $style('heading 3')),
        p(text(30)),
        p('A quotation set in Word\'s Quote style. ' . text(20), $style('Quote')),
        pr([r('Text after a manual page break.')], null, '', '<w:r><w:br w:type="page"/></w:r>'),
        p('Second page', $style('heading 1')),
        p(text(80), null, '<w:jc w:val="both"/>'),
        p('Кириллица: съешь же ещё этих мягких французских булок да выпей чаю. ' . text(25)),
    ];

    return ['body' => $body];
}

function lists(Closure $style): array
{
    $item = static fn(string $text, int $num, int $level = 0): string => p($text, $style('List Paragraph'), "<w:numPr><w:ilvl w:val=\"{$level}\"/><w:numId w:val=\"{$num}\"/></w:numPr>");

    $body = [
        p('Lists', $style('Title')),
        p('Numbered, three levels', $style('heading 1')),
        $item('First item', 1),
        $item('Second item, long enough to wrap onto a second line so the hanging indent of the text can be seen in the result.', 1),
        $item('Nested a', 1, 1),
        $item('Nested b', 1, 1),
        $item('Deeper i', 1, 2),
        $item('Deeper ii', 1, 2),
        $item('Third item', 1),
        p('Bullets', $style('heading 1')),
        $item('Round bullet', 2),
        $item('Another bullet', 2),
        $item('Hollow bullet one level down', 2, 1),
        $item('Square bullet two levels down', 2, 2),
        $item('Back at the top', 2),
        p('Legal numbering', $style('heading 1')),
        $item('Scope', 3),
        $item('Definitions', 3, 1),
        $item('Terms used in this document', 3, 2),
        $item('Obligations', 3, 1),
        $item('Liability', 3),
        p('A list starting at five', $style('heading 1')),
        $item('Fifth', 4),
        $item('Sixth', 4),
        p('A paragraph between two items of the same list does not restart it:'),
        $item('Seventh', 4),
        p('Нумерованный список по-русски', $style('heading 1')),
        $item('Первый пункт', 5),
        $item('Второй пункт', 5),
        $item('Вложенный пункт', 5, 1),
    ];

    $multilevel = abstractNum(0, [
        ['decimal', '%1.', 720, 360],
        ['lowerLetter', '%2.', 1440, 360],
        ['lowerRoman', '%3.', 2160, 180, 'right'],
    ]);
    $bullets = abstractNum(1, [
        ['bullet', "\u{F0B7}", 720, 360, 'left', 'Symbol'],
        ['bullet', 'o', 1440, 360, 'left', 'Courier New'],
        ['bullet', "\u{F0A7}", 2160, 360, 'left', 'Wingdings'],
    ]);
    $legal = abstractNum(2, [
        ['decimal', '%1.', 360, 360],
        ['decimal', '%1.%2.', 792, 432],
        ['decimal', '%1.%2.%3.', 1224, 504],
    ]);
    $numbering = $multilevel . $bullets . $legal
        . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'
        . '<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>'
        . '<w:num w:numId="3"><w:abstractNumId w:val="2"/></w:num>'
        . '<w:num w:numId="4"><w:abstractNumId w:val="0"/><w:lvlOverride w:ilvl="0"><w:startOverride w:val="5"/></w:lvlOverride></w:num>'
        . '<w:num w:numId="5"><w:abstractNumId w:val="0"/><w:lvlOverride w:ilvl="0"><w:startOverride w:val="1"/></w:lvlOverride></w:num>';

    return ['body' => $body, 'numbering' => $numbering];
}

function tables(Closure $style): array
{
    $cell = static function (string $text, int $width, string $tcPr = '', string $pPr = '', string $rPr = ''): string {
        return "<w:tc><w:tcPr><w:tcW w:w=\"{$width}\" w:type=\"dxa\"/>{$tcPr}</w:tcPr>"
            . p($text, null, $pPr, $rPr) . '</w:tc>';
    };
    $table = static function (array $widths, array $rows, string $tblPr) use ($style): string {
        $grid = implode('', array_map(static fn(int $w): string => "<w:gridCol w:w=\"{$w}\"/>", $widths));

        return "<w:tbl><w:tblPr>{$tblPr}</w:tblPr><w:tblGrid>{$grid}</w:tblGrid>" . implode('', $rows) . '</w:tbl>';
    };
    $grid = '<w:tblStyle w:val="TableGridBench"/><w:tblW w:w="0" w:type="auto"/><w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/>';

    $simple = $table([3000, 3000, 3355], [
        '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . $cell('Product', 3000, '', '', '<w:b/>') . $cell('Quantity', 3000, '', '', '<w:b/>') . $cell('Price', 3355, '', '', '<w:b/>') . '</w:tr>',
        '<w:tr>' . $cell('Apples', 3000) . $cell('12', 3000, '', '<w:jc w:val="right"/>') . $cell('4.50', 3355, '', '<w:jc w:val="right"/>') . '</w:tr>',
        '<w:tr>' . $cell('Pears', 3000) . $cell('7', 3000, '', '<w:jc w:val="right"/>') . $cell('3.20', 3355, '', '<w:jc w:val="right"/>') . '</w:tr>',
        '<w:tr>' . $cell('Plums, a longer name that wraps inside its cell', 3000) . $cell('30', 3000, '', '<w:jc w:val="right"/>') . $cell('9.99', 3355, '', '<w:jc w:val="right"/>') . '</w:tr>',
    ], $grid);

    $shade = '<w:shd w:val="clear" w:color="auto" w:fill="D9E2F3"/>';
    $merged = $table([2000, 2000, 2000, 3355], [
        '<w:tr>' . $cell('Region', 2000, $shade . '<w:vMerge w:val="restart"/><w:vAlign w:val="center"/>', '', '<w:b/>')
            . $cell('Sales', 4000, $shade . '<w:gridSpan w:val="2"/>', '<w:jc w:val="center"/>', '<w:b/>')
            . $cell('Notes', 3355, $shade . '<w:vMerge w:val="restart"/><w:vAlign w:val="center"/>', '', '<w:b/>') . '</w:tr>',
        '<w:tr>' . $cell('', 2000, $shade . '<w:vMerge/>') . $cell('2025', 2000, $shade, '<w:jc w:val="center"/>', '<w:b/>')
            . $cell('2026', 2000, $shade, '<w:jc w:val="center"/>', '<w:b/>') . $cell('', 3355, $shade . '<w:vMerge/>') . '</w:tr>',
        '<w:tr>' . $cell('North', 2000) . $cell('1 200', 2000, '', '<w:jc w:val="right"/>') . $cell('1 450', 2000, '', '<w:jc w:val="right"/>') . $cell('Growth driven by the new store.', 3355) . '</w:tr>',
        '<w:tr>' . $cell('South', 2000, '<w:vMerge w:val="restart"/>') . $cell('980', 2000, '', '<w:jc w:val="right"/>') . $cell('1 010', 2000, '', '<w:jc w:val="right"/>') . $cell('Flat.', 3355) . '</w:tr>',
        '<w:tr>' . $cell('', 2000, '<w:vMerge/>') . $cell('Merged across the two years', 4000, '<w:gridSpan w:val="2"/>', '<w:jc w:val="center"/>') . $cell('', 3355) . '</w:tr>',
    ], $grid);

    $rule = '<w:bottom w:val="single" w:sz="8" w:space="0" w:color="2F5496"/>';
    $lines = $table([4677, 4678], [
        '<w:tr>' . $cell('Term', 4677, "<w:tcBorders>{$rule}</w:tcBorders>", '', '<w:b/><w:color w:val="2F5496"/>') . $cell('Meaning', 4678, "<w:tcBorders>{$rule}</w:tcBorders>", '', '<w:b/><w:color w:val="2F5496"/>') . '</w:tr>',
        '<w:tr>' . $cell('Fidelity', 4677) . $cell('How closely the HTML looks like the document in Word.', 4678) . '</w:tr>',
        '<w:tr>' . $cell('Profile', 4677) . $cell('The flavour of HTML a particular editor expects.', 4678) . '</w:tr>',
    ], '<w:tblW w:w="9355" w:type="dxa"/><w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/><w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders><w:tblLook w:val="0000"/>');

    $body = [
        p('Tables', $style('Title')),
        p('A simple grid', $style('heading 1')),
        $simple,
        p(''),
        p('Merged and shaded cells', $style('heading 1')),
        $merged,
        p(''),
        p('Rules instead of a grid', $style('heading 1')),
        $lines,
        p('Text after the last table. ' . text(25)),
    ];

    return ['body' => $body, 'styles' => tableGridStyle()];
}

function images(Closure $style): array
{
    $media = [
        'image1.png' => png(480, 240, [0x2F, 0x54, 0x96], 'wide'),
        'image2.png' => png(240, 240, [0xC0, 0x50, 0x4D], 'square'),
        'image3.png' => png(32, 32, [0x70, 0xAD, 0x47], 'icon'),
    ];

    $body = [
        p('Images', $style('Title')),
        p('A picture on its own line, centred', $style('heading 1')),
        pr([drawing('rIdImg1', 1, 480, 240)], null, '<w:jc w:val="center"/>'),
        p('Figure 1. A wide picture, centred, with a caption below.', $style('caption'), '<w:jc w:val="center"/>'),
        p('A picture inside a line of text', $style('heading 1')),
        pr([r('The small square '), drawing('rIdImg3', 2, 32, 32), r(' sits inside the line, and the text around it keeps flowing. ' . text(25))]),
        p('A picture aligned left', $style('heading 1')),
        pr([drawing('rIdImg2', 3, 240, 240)]),
        p(text(40)),
    ];

    return [
        'body' => $body,
        'media' => $media,
        'relationships' => [
            'rIdImg1' => 'media/image1.png',
            'rIdImg2' => 'media/image2.png',
            'rIdImg3' => 'media/image3.png',
        ],
    ];
}

function long(Closure $style): array
{
    $body = [p('A longer document', $style('Title'))];

    for ($chapter = 1; $chapter <= 6; $chapter++) {
        $body[] = p("Chapter {$chapter}", $style('heading 1'));

        for ($section = 1; $section <= 2; $section++) {
            $body[] = p("Section {$chapter}.{$section}", $style('heading 2'));

            for ($paragraph = 0; $paragraph < 3; $paragraph++) {
                $body[] = p(text(55 + ($chapter * 7 + $section * 3 + $paragraph * 5) % 40, $chapter * 100 + $section * 10 + $paragraph), null, '<w:ind w:firstLine="709"/><w:jc w:val="both"/>');
            }
        }
    }

    return ['body' => $body];
}

// ---------------------------------------------------------------------------
// WordprocessingML helpers

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
 * @param  array{body: list<string>, numbering?: string, styles?: string, media?: array<string, string>, relationships?: array<string, string>}  $document
 */
function build(string $base, string $target, array $document): void
{
    copy($base, $target);
    $zip = new ZipArchive();
    $zip->open($target);

    $main = (string) $zip->getFromName('word/document.xml');
    $sectPr = preg_match('~<w:sectPr\b.*</w:sectPr>~s', $main, $m) === 1 ? $m[0] : '';
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
