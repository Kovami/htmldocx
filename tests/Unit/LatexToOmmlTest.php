<?php

declare(strict_types=1);

use Dom\XMLDocument;
use Kovami\HtmlDocx\Docx\Reader\OmmlToLatex;
use Kovami\HtmlDocx\Docx\Writer\LatexToOmml;
use Kovami\HtmlDocx\Docx\Writer\XmlBuilder;
use Kovami\HtmlDocx\Model\RunProperties;

/** The Office Math one formula becomes, as an `m:oMath` element. */
function omml(string $latex): string
{
    $xml = new XmlBuilder;
    $xml->open('m:oMath', [
        'xmlns:m' => 'http://schemas.openxmlformats.org/officeDocument/2006/math',
        'xmlns:w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
    ]);
    LatexToOmml::write($xml, $latex, new RunProperties);

    return $xml->close()->toString();
}

/** The LaTeX that comes back out of the Office Math a formula was written as. */
function latexRoundTrip(string $latex): string
{
    $document = XMLDocument::createFromString(omml($latex), LIBXML_NOERROR);

    expect($document->documentElement)->not->toBeNull();

    return OmmlToLatex::convert($document->documentElement);
}

it('writes formulas as Office Math that reads back as the same LaTeX', function (string $latex) {
    expect(latexRoundTrip($latex))->toBe($latex);
})->with([
    'a+b=c',
    'x^{2}',
    'a_{i}^{n}',
    'e^{-x^{2}}',
    '\frac{a+b}{2}',
    '\frac{\sqrt{x}}{2}',
    '\sqrt{x+1}',
    '\sqrt[3]{x}',
    '\sum_{i=1}^{n} i',
    '\int_{0}^{1} x',
    '\left[ x \right]',
    '\left( a+b \right)',
    '\sin x',
    '\vec{v}',
    '\overline{AB}',
    '\underline{x}',
    '\boxed{x}',
    '\alpha \le \beta',
    '\text{если x>0}',
    '\mathbb{R}',
    '\begin{matrix}1 & 2 \\\\ 3 & 4\end{matrix}',
    '\begin{aligned}x=1 \\\\ y=2\end{aligned}',
]);

it('writes the Office Math elements Word expects', function () {
    expect(omml('\frac{1}{2}'))->toContain('<m:f><m:num>')->toContain('<m:den>')
        ->and(omml('x^{2}'))->toContain('<m:sSup>')->toContain('<m:sup>')
        ->and(omml('\sqrt[3]{x}'))->toContain('<m:rad>')->toContain('<m:deg>')
        ->and(omml('\sum_{i}^{n} i'))->toContain('<m:nary>')->toContain('m:val="∑"')
        ->and(omml('\left( x \right)'))->toContain('<m:d>')->toContain('m:val="("')
        ->and(omml('\sin x'))->toContain('<m:func>')->toContain('<m:fName>')
        ->and(omml('\vec{v}'))->toContain('<m:acc>')
        ->and(omml('\text{plain}'))->toContain('<m:nor/>')
        ->and(omml('\begin{matrix}1\end{matrix}'))->toContain('<m:m><m:mr>');
});

it('wraps a matrix environment in the delimiters its name asks for', function () {
    expect(latexRoundTrip('\begin{pmatrix}a \\\\ b\end{pmatrix}'))->toBe('\left( \begin{matrix}a \\\\ b\end{matrix} \right)')
        ->and(latexRoundTrip('\begin{bmatrix}a\end{bmatrix}'))->toBe('\left[ \begin{matrix}a\end{matrix} \right]');
});

it('keeps neighbouring characters in one run', function () {
    expect(substr_count(omml('a+b=c'), '<m:r>'))->toBe(1);
});

it('shows a command it does not know as its name', function () {
    expect(latexRoundTrip('\unknowncommand'))->toBe('unknowncommand');
});

it('carries the formula\'s own formatting into every run', function () {
    $xml = new XmlBuilder;
    $xml->open('m:oMath', [
        'xmlns:m' => 'http://schemas.openxmlformats.org/officeDocument/2006/math',
        'xmlns:w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
    ]);
    LatexToOmml::write($xml, 'x+1', new RunProperties(size: 32, color: 'FF0000'));

    expect($xml->close()->toString())->toContain('<w:sz w:val="32"/>')->toContain('<w:color w:val="FF0000"/>');
});
