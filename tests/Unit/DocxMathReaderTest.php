<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Model\Formula;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Tests\Support\DocxBuilder;

/** The LaTeX of the first formula in a document whose body is the given math. */
function latexOf(string $math, bool $display = false): string
{
    $element = $display ? 'm:oMathPara' : 'm:oMath';
    $document = DocxBuilder::make()->body("<w:p><{$element}>{$math}</{$element}></w:p>")->read();
    $paragraph = $document->blocks[0];

    expect($paragraph)->toBeInstanceOf(Paragraph::class);
    $formula = $paragraph->children[0];
    expect($formula)->toBeInstanceOf(Formula::class);

    return $formula->latex;
}

it('reads Office Math as the LaTeX KaTeX renders', function (string $name, string $math, string $latex) {
    expect(latexOf($math))->toBe($latex);
})->with([
    ['fraction',
        '<m:f><m:num><m:r><m:t>a+b</m:t></m:r></m:num><m:den><m:r><m:t>2</m:t></m:r></m:den></m:f>',
        '\frac{a+b}{2}'],
    ['superscript',
        '<m:sSup><m:e><m:r><m:t>x</m:t></m:r></m:e><m:sup><m:r><m:t>2</m:t></m:r></m:sup></m:sSup>',
        'x^{2}'],
    ['subscript and superscript',
        '<m:sSubSup><m:e><m:r><m:t>a</m:t></m:r></m:e><m:sub><m:r><m:t>i</m:t></m:r></m:sub><m:sup><m:r><m:t>n</m:t></m:r></m:sup></m:sSubSup>',
        'a_{i}^{n}'],
    ['n-ary sum with limits',
        '<m:nary><m:naryPr><m:chr m:val="∑"/></m:naryPr><m:sub><m:r><m:t>i=1</m:t></m:r></m:sub><m:sup><m:r><m:t>n</m:t></m:r></m:sup><m:e><m:r><m:t>i</m:t></m:r></m:e></m:nary>',
        '\sum_{i=1}^{n} i'],
    ['square root',
        '<m:rad><m:radPr><m:degHide m:val="1"/></m:radPr><m:deg/><m:e><m:r><m:t>x+1</m:t></m:r></m:e></m:rad>',
        '\sqrt{x+1}'],
    ['nth root',
        '<m:rad><m:deg><m:r><m:t>3</m:t></m:r></m:deg><m:e><m:r><m:t>x</m:t></m:r></m:e></m:rad>',
        '\sqrt[3]{x}'],
    ['delimiters',
        '<m:d><m:dPr><m:begChr m:val="["/><m:endChr m:val="]"/></m:dPr><m:e><m:r><m:t>x</m:t></m:r></m:e></m:d>',
        '\left[ x \right]'],
    ['function',
        '<m:func><m:fName><m:r><m:t>sin</m:t></m:r></m:fName><m:e><m:r><m:t>x</m:t></m:r></m:e></m:func>',
        '\sin x'],
    ['matrix',
        '<m:m><m:mr><m:e><m:r><m:t>1</m:t></m:r></m:e><m:e><m:r><m:t>2</m:t></m:r></m:e></m:mr><m:mr><m:e><m:r><m:t>3</m:t></m:r></m:e><m:e><m:r><m:t>4</m:t></m:r></m:e></m:mr></m:m>',
        '\begin{matrix}1 & 2 \\\\ 3 & 4\end{matrix}'],
    ['accent',
        '<m:acc><m:accPr><m:chr m:val="&#x20D7;"/></m:accPr><m:e><m:r><m:t>v</m:t></m:r></m:e></m:acc>',
        '\vec{v}'],
    ['overline',
        '<m:bar><m:barPr><m:pos m:val="top"/></m:barPr><m:e><m:r><m:t>AB</m:t></m:r></m:e></m:bar>',
        '\overline{AB}'],
    ['equation array',
        '<m:eqArr><m:e><m:r><m:t>x=1</m:t></m:r></m:e><m:e><m:r><m:t>y=2</m:t></m:r></m:e></m:eqArr>',
        '\begin{aligned}x=1 \\\\ y=2\end{aligned}'],
    ['symbols',
        '<m:r><m:t>α≤β</m:t></m:r>',
        '\alpha \le \beta'],
    ['upright text',
        '<m:r><m:rPr><m:nor/></m:rPr><m:t>если x&gt;0</m:t></m:r>',
        '\text{если x>0}'],
]);

it('marks a formula on its own line as displayed', function () {
    $document = DocxBuilder::make()->body(
        '<w:p><m:oMathPara><m:oMath><m:r><m:t>x=1</m:t></m:r></m:oMath></m:oMathPara></w:p>'
        . '<w:p><w:r><w:t xml:space="preserve">inline </w:t></w:r><m:oMath><m:r><m:t>y=2</m:t></m:r></m:oMath></w:p>',
    )->read();

    $formulas = [];

    foreach ($document->blocks as $block) {
        foreach ($block instanceof Paragraph ? $block->children : [] as $inline) {
            if ($inline instanceof Formula) {
                $formulas[] = $inline;
            }
        }
    }

    expect($formulas)->toHaveCount(2)
        ->and($formulas[0]->display)->toBeTrue()
        ->and($formulas[0]->latex)->toBe('x=1')
        ->and($formulas[1]->display)->toBeFalse();
});

it('keeps the formatting of the run a formula sits in', function () {
    $document = DocxBuilder::make()->body(
        '<w:p><m:oMath><m:r><m:rPr><m:sty m:val="p"/></m:rPr><w:rPr><w:sz w:val="32"/><w:color w:val="FF0000"/></w:rPr><m:t>x</m:t></m:r></m:oMath></w:p>',
    )->read();

    $paragraph = $document->blocks[0];
    $formula = $paragraph instanceof Paragraph ? $paragraph->children[0] : null;

    expect($formula)->toBeInstanceOf(Formula::class)
        ->and($formula->properties->fontFamily)->toBe('Times New Roman')
        ->and($formula->properties->size)->toBe(32)
        ->and($formula->properties->color)->toBe('FF0000');
});

it('falls back to the text of math it cannot translate', function () {
    expect(latexOf('<m:borderBox><m:e><m:r><m:t>x</m:t></m:r></m:e></m:borderBox>'))->toBe('\boxed{x}')
        ->and(latexOf('<m:unknownThing><m:r><m:t>fallback</m:t></m:r></m:unknownThing>'))->toBe('fallback');
});
