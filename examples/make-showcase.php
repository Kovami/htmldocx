<?php

declare(strict_types=1);

/*
 * Writes the source of the README showcase: one realistic report that holds
 * everything the library converts (headings, formatting, lists, a merged and
 * shaded table, a picture with a caption, a formula, footnotes, a comment,
 * a header and a footer with page numbers). Like the bench corpus it is a
 * body on Word's own blank package; build-word.sh lets Word re-save and
 * print it, so examples/showcase.docx is what Word itself writes.
 *
 * Usage: php make-showcase.php <output.docx>
 */

require __DIR__ . '/../bench/fidelity/wordml.php';

$target = $argv[1] ?? '';

if ($target === '') {
    fwrite(STDERR, "usage: php make-showcase.php <output.docx>\n");
    exit(1);
}

$base = __DIR__ . '/../bench/fidelity/source/base.docx';
$styles = styleIds($base);
$style = static fn(string $name): string => $styles[strtolower($name)] ?? throw new RuntimeException("Word's base package has no style {$name}");

const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

$item = static fn(array|string $runs, int $num, int $level = 0): string => pr(
    is_string($runs) ? [r($runs)] : $runs,
    $style('List Paragraph'),
    "<w:numPr><w:ilvl w:val=\"{$level}\"/><w:numId w:val=\"{$num}\"/></w:numPr>",
);
$footnote = static fn(int $id): string => "<w:r><w:rPr><w:vertAlign w:val=\"superscript\"/></w:rPr><w:footnoteReference w:id=\"{$id}\"/></w:r>";
$justified = '<w:jc w:val="both"/>';

// Sales by region: a header row shaded in the accent colour, merged cells.
$cell = static function (string $text, int $width, string $tcPr = '', string $pPr = '', string $rPr = ''): string {
    return "<w:tc><w:tcPr><w:tcW w:w=\"{$width}\" w:type=\"dxa\"/>{$tcPr}</w:tcPr>" . p($text, null, $pPr, $rPr) . '</w:tc>';
};
$head = '<w:shd w:val="clear" w:color="auto" w:fill="2F5496"/><w:vAlign w:val="center"/>';
$white = '<w:b/><w:color w:val="FFFFFF"/>';
$band = '<w:shd w:val="clear" w:color="auto" w:fill="D9E2F3"/>';
$right = '<w:jc w:val="right"/>';
$center = '<w:jc w:val="center"/>';
$table = '<w:tbl><w:tblPr><w:tblStyle w:val="TableGridBench"/><w:tblW w:w="0" w:type="auto"/>'
    . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr>'
    . '<w:tblGrid><w:gridCol w:w="2400"/><w:gridCol w:w="1800"/><w:gridCol w:w="1800"/><w:gridCol w:w="3355"/></w:tblGrid>'
    . '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . $cell('Region', 2400, $head . '<w:vMerge w:val="restart"/>', '', $white)
    . $cell('Revenue, k$', 3600, $head . '<w:gridSpan w:val="2"/>', $center, $white)
    . $cell('Comment', 3355, $head . '<w:vMerge w:val="restart"/>', '', $white) . '</w:tr>'
    . '<w:tr><w:trPr><w:tblHeader/></w:trPr>' . $cell('', 2400, $head . '<w:vMerge/>') . $cell('Q1', 1800, $head, $center, $white)
    . $cell('Q2', 1800, $head, $center, $white) . $cell('', 3355, $head . '<w:vMerge/>') . '</w:tr>'
    . '<w:tr>' . $cell('North', 2400) . $cell('1 240', 1800, '', $right) . $cell('1 510', 1800, '', $right) . $cell('New store opened in May.', 3355) . '</w:tr>'
    . '<w:tr>' . $cell('South', 2400, $band) . $cell('980', 1800, $band, $right) . $cell('1 020', 1800, $band, $right) . $cell('Flat, as planned.', 3355, $band) . '</w:tr>'
    . '<w:tr>' . $cell('West', 2400, '<w:vMerge w:val="restart"/><w:vAlign w:val="center"/>') . $cell('760', 1800, '', $right) . $cell('905', 1800, '', $right) . $cell('Online orders doubled.', 3355) . '</w:tr>'
    . '<w:tr>' . $cell('', 2400, $band . '<w:vMerge/>') . $cell('Two stores merged into one', 3600, $band . '<w:gridSpan w:val="2"/>', $center, '<w:i/>') . $cell('', 3355, $band) . '</w:tr>'
    . '<w:tr>' . $cell('Total', 2400, '', '', '<w:b/>') . $cell('2 980', 1800, '', $right, '<w:b/>') . $cell('3 435', 1800, '', $right, '<w:b/>') . $cell('+15.3%', 3355, '', '', '<w:b/>') . '</w:tr>'
    . '</w:tbl>';

// g = (R₂ − R₁) / R₁ × 100%
$run = static fn(string $text): string => '<m:r><m:t>' . htmlspecialchars($text, ENT_XML1) . '</m:t></m:r>';
$sub = static fn(string $base, string $index): string => '<m:sSub><m:e>' . $run($base) . '</m:e><m:sub>' . $run($index) . '</m:sub></m:sSub>';
$formula = '<m:oMathPara><m:oMath>' . $run('g=') . '<m:f><m:num>' . $sub('R', '2') . $run('−') . $sub('R', '1') . '</m:num><m:den>' . $sub('R', '1') . '</m:den></m:f>'
    . $run('×100%') . '</m:oMath></m:oMathPara>';

$body = [
    p('Quarterly report', $style('Title')),
    p('Harbor & Pine Co. · Second quarter 2026', $style('Subtitle')),
    p('Summary', $style('heading 1')),
    pr([
        r('Revenue reached '), r('3.4 million dollars', '<w:b/>'), r(', 15% more than in the first quarter and '),
        '<w:commentRangeStart w:id="0"/>', r('ahead of plan'), '<w:commentRangeEnd w:id="0"/>',
        '<w:r><w:commentReference w:id="0"/></w:r>',
        r('. Growth came from the north, where a new store opened, and from online orders in the west'), $footnote(1),
        r('. Costs stayed '), r('within budget', '<w:i/>'), r(' for the third quarter in a row.'),
    ], null, $justified),
    $item('Revenue: 3.4 M$, +15%', 1),
    $item('Operating margin: 18%, +2 points', 1),
    $item('Online share of sales: 21%', 1),
    $item('twice the share a year ago', 1, 1),
    p('Sales by region', $style('heading 1')),
    p('Table 1. Revenue by region, thousand dollars', $style('caption'), '<w:keepNext/>'),
    $table,
    p('Growth', $style('heading 1')),
    pr([r('Quarterly growth is measured as the change of revenue against the previous quarter, where R'), r('1', '<w:vertAlign w:val="subscript"/>'),
        r(' and R'), r('2', '<w:vertAlign w:val="subscript"/>'), r(' are the revenues of the two quarters:')], null, $justified),
    '<w:p>' . $formula . '</w:p>',
    pr([drawing('rIdChart', 1, 320, 147)], null, '<w:jc w:val="center"/><w:keepNext/>'),
    p('Figure 1. Revenue in April, May and June, thousand dollars: last year (light) and this year (dark)', $style('caption'), '<w:jc w:val="center"/>'),
    pr([r('Every month of the quarter beat the same month of last year. June was the strongest month on record'), $footnote(2),
        r('; the summer sale moved part of the July demand into it, so July will look weaker in comparison.')], null, $justified),
    p('Next steps', $style('heading 1')),
    $item('Open the second store in the north', 2),
    $item('Choose the site by the end of July', 2, 1),
    $item('Hire and train the staff in August', 2, 1),
    $item('Move the west region fully online', 2),
    $item([r('Report the third quarter by '), r('15 October', '<w:b/>')], 2),
];

$note = static fn(int $id, string $text): string => "<w:footnote w:id=\"{$id}\"><w:p><w:pPr><w:spacing w:after=\"0\" w:line=\"240\" w:lineRule=\"auto\"/></w:pPr>"
    . '<w:r><w:rPr><w:vertAlign w:val="superscript"/></w:rPr><w:footnoteRef/></w:r>' . r(' ' . $text, '<w:sz w:val="20"/>') . '</w:p></w:footnote>';
$separator = static fn(int $id, string $type, string $mark): string => "<w:footnote w:type=\"{$type}\" w:id=\"{$id}\"><w:p><w:pPr><w:spacing w:after=\"0\" w:line=\"240\" w:lineRule=\"auto\"/></w:pPr><w:r><w:{$mark}/></w:r></w:p></w:footnote>";
$small = '<w:color w:val="7F7F7F"/><w:sz w:val="18"/>';

build($base, $target, [
    'body' => $body,
    'styles' => tableGridStyle(),
    'numbering' => abstractNum(0, [
        ['bullet', "\u{F0B7}", 720, 360, 'left', 'Symbol'],
        ['bullet', 'o', 1440, 360, 'left', 'Courier New'],
    ]) . abstractNum(1, [
        ['decimal', '%1.', 720, 360],
        ['lowerLetter', '%2.', 1440, 360],
    ]) . '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>',
    'media' => ['chart.png' => $chart = chart()],
    'relationships' => ['rIdChart' => 'media/chart.png'],
    'parts' => [
        'rIdFootnotes' => ['footnotes.xml', 'footnotes', '<w:footnotes ' . W . '>' . $separator(-1, 'separator', 'separator')
            . $separator(0, 'continuationSeparator', 'continuationSeparator')
            . $note(1, 'Online orders are counted in the region of the warehouse that shipped them.')
            . $note(2, 'Since the company started monthly reporting in 2019.') . '</w:footnotes>'],
        'rIdComments' => ['comments.xml', 'comments', '<w:comments ' . W . '><w:comment w:id="0" w:author="Reviewer" w:date="2026-07-02T09:00:00Z" w:initials="R">'
            . '<w:p><w:r><w:annotationRef/></w:r>' . r('Check against the final ledger before publishing.') . '</w:p></w:comment></w:comments>'],
        'rIdHeader' => ['header1.xml', 'header', '<w:hdr ' . W . '>' . p('Harbor & Pine Co. · Quarterly report Q2 2026', null, '<w:jc w:val="right"/>', $small) . '</w:hdr>'],
        'rIdFooter' => ['footer1.xml', 'footer', '<w:ftr ' . W . '><w:p><w:pPr><w:jc w:val="center"/></w:pPr>' . r('Page ', $small)
            . "<w:fldSimple w:instr=\" PAGE \\* MERGEFORMAT \">" . r('1', $small) . '</w:fldSimple>' . r(' of ', $small)
            . "<w:fldSimple w:instr=\" NUMPAGES \\* MERGEFORMAT \">" . r('2', $small) . '</w:fldSimple></w:p></w:ftr>'],
    ],
    'section' => '<w:headerReference w:type="default" r:id="rIdHeader"/><w:footerReference w:type="default" r:id="rIdFooter"/>',
]);

// The same picture for the editor HTML example.
file_put_contents(__DIR__ . '/chart.png', $chart);
echo "{$target}\n";

/** A bar chart of the quarter's revenue by month, drawn for the showcase. */
function chart(): string
{
    [$width, $height] = [960, 440];
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $grid = imagecolorallocate($image, 217, 217, 217);
    $last = imagecolorallocate($image, 180, 199, 231);
    $this_ = imagecolorallocate($image, 47, 84, 150);
    imagefilledrectangle($image, 0, 0, $width, $height, $white);

    for ($y = 40; $y <= 400; $y += 60) {
        imageline($image, 40, $y, $width - 40, $y, $grid);
    }

    // Last year and this year for April, May and June, in thousand dollars.
    $months = [[920, 1060], [980, 1150], [1010, 1225]];

    foreach ($months as $index => [$before, $now]) {
        $x = 120 + $index * 280;

        foreach ([[$before, $last], [$now, $this_]] as $bar => [$value, $colour]) {
            $top = 400 - (int) round($value / 1300 * 360);
            imagefilledrectangle($image, $x + $bar * 90, $top, $x + $bar * 90 + 80, 400, $colour);
        }
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}
