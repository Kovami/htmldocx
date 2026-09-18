<?php

declare(strict_types=1);

use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Model\CellProperties;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\ImageRun;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\Table;
use Kovami\HtmlDocx\Model\TableCell;
use Kovami\HtmlDocx\Tests\Support\DocxBuilder;
use Kovami\HtmlDocx\Tests\Support\TestImage;

/** `<w:tc>` holding one paragraph of text, with optional cell properties. */
function cellXml(string $text, string $properties = ''): string
{
    return '<w:tc><w:tcPr><w:tcW w:w="2500" w:type="dxa"/>'.$properties.'</w:tcPr>'
        .'<w:p><w:r><w:t>'.$text.'</w:t></w:r></w:p></w:tc>';
}

function firstTable(array $blocks): Table
{
    foreach ($blocks as $block) {
        if ($block instanceof Table) {
            return $block;
        }
    }

    throw new RuntimeException('The document has no table.');
}

function cellText(TableCell $cell): string
{
    return implode('', array_map(blockText(...), $cell->blocks));
}

it('reads a table grid, its rows and their cells', function () {
    $document = DocxBuilder::make()->body(
        '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="dxa"/></w:tblPr>'
        .'<w:tblGrid><w:gridCol w:w="2500"/><w:gridCol w:w="2500"/></w:tblGrid>'
        .'<w:tr>'.cellXml('a').cellXml('b').'</w:tr>'
        .'<w:tr>'.cellXml('c').cellXml('d').'</w:tr>'
        .'</w:tbl>'
    )->read();

    $table = firstTable($document->blocks);

    expect($table->gridColumns)->toBe([2500, 2500])
        ->and($table->rows)->toHaveCount(2)
        ->and($table->properties->width)->toBe(5000)
        ->and(array_map(cellText(...), $table->rows[0]->cells))->toBe(['a', 'b'])
        ->and(array_map(cellText(...), $table->rows[1]->cells))->toBe(['c', 'd']);
});

it('applies a table style and its conditional formatting to the first row', function () {
    $document = DocxBuilder::make()
        ->styles(
            '<w:style w:type="table" w:styleId="Grid"><w:name w:val="Table Grid"/>'
            .'<w:tblPr><w:tblBorders><w:insideH w:val="single" w:sz="4" w:color="808080"/></w:tblBorders></w:tblPr>'
            .'<w:tcPr><w:shd w:val="clear" w:fill="FFFFFF"/></w:tcPr>'
            .'<w:tblStylePr w:type="firstRow"><w:rPr><w:b/></w:rPr><w:tcPr><w:shd w:val="clear" w:fill="D9E2F3"/></w:tcPr></w:tblStylePr>'
            .'</w:style>'
        )
        ->body(
            '<w:tbl><w:tblPr><w:tblStyle w:val="Grid"/><w:tblW w:w="5000" w:type="dxa"/>'
            .'<w:tblLook w:firstRow="1" w:lastRow="0" w:firstColumn="0" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr>'
            .'<w:tblGrid><w:gridCol w:w="2500"/><w:gridCol w:w="2500"/></w:tblGrid>'
            .'<w:tr><w:trPr><w:tblHeader/></w:trPr>'.cellXml('head').cellXml('also head').'</w:tr>'
            .'<w:tr>'.cellXml('body').cellXml('more body').'</w:tr>'
            .'</w:tbl>'
        )
        ->read();

    $table = firstTable($document->blocks);
    $run = static function (TableCell $cell) {
        $paragraph = $cell->blocks[0];

        return $paragraph instanceof Paragraph ? firstRun($paragraph) : null;
    };

    expect($table->rows[0]->isHeader)->toBeTrue()
        ->and($run($table->rows[0]->cells[0])?->properties->bold)->toBeTrue()
        ->and($table->rows[0]->cells[0]->properties->shading)->toBe('D9E2F3')
        ->and($run($table->rows[1]->cells[0])?->properties->bold)->toBeFalse()
        ->and($table->rows[1]->cells[0]->properties->shading)->toBe('FFFFFF')
        // Inside borders from the style reach the cells that need them.
        ->and($table->rows[0]->cells[0]->properties->borders->bottom?->color)->toBe('808080');
});

it('reads merged cells', function () {
    $document = DocxBuilder::make()->body(
        '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="dxa"/></w:tblPr>'
        .'<w:tblGrid><w:gridCol w:w="2500"/><w:gridCol w:w="2500"/></w:tblGrid>'
        .'<w:tr>'.cellXml('wide', '<w:gridSpan w:val="2"/>').'</w:tr>'
        .'<w:tr>'.cellXml('tall', '<w:vMerge w:val="restart"/>').cellXml('right top').'</w:tr>'
        .'<w:tr>'.cellXml('', '<w:vMerge/>').cellXml('right bottom').'</w:tr>'
        .'</w:tbl>'
    )->read();

    $table = firstTable($document->blocks);

    expect($table->rows[0]->cells[0]->properties->gridSpan)->toBe(2)
        ->and($table->rows[1]->cells[0]->properties->verticalMerge)->toBe(CellProperties::MERGE_RESTART)
        ->and($table->rows[2]->cells[0]->properties->verticalMerge)->toBe(CellProperties::MERGE_CONTINUE);
});

it('reads a table nested in a cell', function () {
    $inner = '<w:tbl><w:tblPr><w:tblW w:w="2000" w:type="dxa"/></w:tblPr><w:tblGrid><w:gridCol w:w="2000"/></w:tblGrid>'
        .'<w:tr>'.cellXml('inner').'</w:tr></w:tbl><w:p/>';

    $document = DocxBuilder::make()->body(
        '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="dxa"/></w:tblPr><w:tblGrid><w:gridCol w:w="5000"/></w:tblGrid>'
        .'<w:tr><w:tc><w:tcPr><w:tcW w:w="5000" w:type="dxa"/></w:tcPr>'.$inner.'</w:tc></w:tr></w:tbl>'
    )->read();

    $outer = firstTable($document->blocks);
    $nested = $outer->rows[0]->cells[0]->blocks[0];

    expect($nested)->toBeInstanceOf(Table::class)
        ->and(cellText($nested->rows[0]->cells[0]))->toBe('inner');
});

it('reads widths given as a percentage of the space the table has', function () {
    $document = DocxBuilder::make()->body(
        '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr>'
        .'<w:tblGrid><w:gridCol/><w:gridCol/></w:tblGrid>'
        .'<w:tr>'
        .'<w:tc><w:tcPr><w:tcW w:w="1250" w:type="pct"/></w:tcPr><w:p><w:r><w:t>quarter</w:t></w:r></w:p></w:tc>'
        .'<w:tc><w:tcPr><w:tcW w:w="3750" w:type="pct"/></w:tcPr><w:p><w:r><w:t>rest</w:t></w:r></w:p></w:tc>'
        .'</w:tr></w:tbl>'
    )->read();

    $table = firstTable($document->blocks);
    $content = $document->pageLayout->contentWidthTwips();

    // 5000 fiftieths of a percent is the whole width; the columns split it 1:3.
    expect($table->properties->width)->toBe($content)
        ->and($table->rows[0]->cells[0]->properties->width)->toBe((int) round($content / 4))
        ->and($table->rows[0]->cells[1]->properties->width)->toBe($content - (int) round($content / 4));
});

it('lifts the text of a text box out of the paragraph it hangs on', function () {
    $textBox = '<w:r><mc:AlternateContent><mc:Fallback>'
        .'<w:pict><v:shape style="width:100pt;height:50pt"><v:textbox><w:txbxContent>'
        .'<w:p><w:r><w:t>boxed</w:t></w:r></w:p>'
        .'</w:txbxContent></v:textbox></v:shape></w:pict>'
        .'</mc:Fallback></mc:AlternateContent></w:r>';

    $document = DocxBuilder::make()->body(
        '<w:p><w:r><w:t>before</w:t></w:r>'.$textBox.'</w:p><w:p><w:r><w:t>after</w:t></w:r></w:p>'
    )->read();

    expect(blockTexts($document->blocks))->toBe(['before', 'boxed', 'after']);
});

it('reads an inline picture with its size and description', function () {
    $drawing = '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
        .'<wp:extent cx="952500" cy="476250"/>'
        .'<wp:docPr id="1" name="Picture 1" descr="a chart"/>'
        .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        .'<pic:pic><pic:nvPicPr><pic:cNvPr id="1" name="Picture 1"/><pic:cNvPicPr/></pic:nvPicPr>'
        .'<pic:blipFill><a:blip r:embed="rIdImage"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        .'<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="952500" cy="476250"/></a:xfrm></pic:spPr>'
        .'</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';

    $document = DocxBuilder::make()
        ->part('media/image1.png', TestImage::png(100, 50), 'image/png')
        ->relationship('rIdImage', 'image', 'media/image1.png')
        ->body('<w:p>'.$drawing.'</w:p>')
        ->read();

    $paragraph = $document->blocks[0];
    $image = $paragraph instanceof Paragraph ? $paragraph->children[0] : null;

    expect($image)->toBeInstanceOf(ImageRun::class)
        ->and($image->width)->toBe(952500)
        ->and($image->height)->toBe(476250)
        ->and($image->description)->toBe('a chart')
        ->and($image->image->contentType)->toBe('image/png')
        ->and($image->image->widthPx)->toBe(100);
});

it('leaves out a picture whose part is missing, and says so', function () {
    $warnings = [];
    $bytes = DocxBuilder::make()
        ->relationship('rIdImage', 'image', 'media/gone.png')
        ->body('<w:p><w:r><w:drawing><wp:inline><wp:extent cx="1" cy="1"/><wp:docPr id="1" name="p"/>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic>'
            .'<pic:nvPicPr><pic:cNvPr id="1" name="p"/><pic:cNvPicPr/></pic:nvPicPr>'
            .'<pic:blipFill><a:blip r:embed="rIdImage"/></pic:blipFill><pic:spPr/></pic:pic>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r><w:r><w:t>text</w:t></w:r></w:p>')
        ->toBytes();

    $document = (new HtmlDocx)
        ->withWarningHandler(function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        })
        ->readDocx($bytes);

    expect(blockTexts($document->blocks))->toBe(['text'])
        ->and($warnings)->not->toBeEmpty();
});

it('reads a complex hyperlink field', function () {
    $document = DocxBuilder::make()->body(
        '<w:p>'
        .'<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
        .'<w:r><w:instrText xml:space="preserve"> HYPERLINK "https://example.com/deep" \\l "anchor" </w:instrText></w:r>'
        .'<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
        .'<w:r><w:t>linked</w:t></w:r>'
        .'<w:r><w:fldChar w:fldCharType="end"/></w:r>'
        .'</w:p>'
    )->read();

    $paragraph = $document->blocks[0];
    $link = $paragraph instanceof Paragraph ? $paragraph->children[0] : null;

    expect($link)->toBeInstanceOf(Hyperlink::class)
        ->and($link->url)->toBe('https://example.com/deep#anchor')
        ->and(blockTexts($document->blocks))->toBe(['linked']);
});

it('reads the page layout of the final section', function () {
    $document = DocxBuilder::make()->body('<w:p><w:r><w:t>x</w:t></w:r></w:p>')->read();

    expect($document->pageLayout->widthTwips)->toBe(11906)
        ->and($document->pageLayout->heightTwips)->toBe(16838)
        ->and($document->pageLayout->marginLeftTwips)->toBe(1701)
        ->and($document->pageLayout->contentWidthTwips())->toBe(11906 - 1701 - 850);
});

it('bands the rows and marks the first column the way the table style asks', function () {
    $document = DocxBuilder::make()
        ->styles(
            '<w:style w:type="table" w:styleId="Banded"><w:name w:val="Banded"/>'
            .'<w:tblPr><w:tblStyleRowBandSize w:val="1"/></w:tblPr>'
            .'<w:tblStylePr w:type="firstRow"><w:tcPr><w:shd w:val="clear" w:fill="222222"/></w:tcPr></w:tblStylePr>'
            .'<w:tblStylePr w:type="band1Horz"><w:tcPr><w:shd w:val="clear" w:fill="EEEEEE"/></w:tcPr></w:tblStylePr>'
            .'<w:tblStylePr w:type="firstCol"><w:rPr><w:b/></w:rPr></w:tblStylePr>'
            .'</w:style>'
        )
        ->body(
            '<w:tbl><w:tblPr><w:tblStyle w:val="Banded"/><w:tblW w:w="5000" w:type="dxa"/>'
            .'<w:tblLook w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/></w:tblPr>'
            .'<w:tblGrid><w:gridCol w:w="2500"/><w:gridCol w:w="2500"/></w:tblGrid>'
            .'<w:tr>'.cellXml('head a').cellXml('head b').'</w:tr>'
            .'<w:tr>'.cellXml('band one').cellXml('plain one').'</w:tr>'
            .'<w:tr>'.cellXml('band two').cellXml('plain two').'</w:tr>'
            .'</w:tbl>'
        )
        ->read();

    $table = firstTable($document->blocks);
    $bold = static function (TableCell $cell): ?bool {
        $paragraph = $cell->blocks[0];

        return $paragraph instanceof Paragraph ? firstRun($paragraph)?->properties->bold : null;
    };

    expect($table->rows[0]->cells[0]->properties->shading)->toBe('222222')
        // The first body row is band one, the next falls outside the banding.
        ->and($table->rows[1]->cells[0]->properties->shading)->toBe('EEEEEE')
        ->and($table->rows[2]->cells[0]->properties->shading)->toBeNull()
        ->and($bold($table->rows[1]->cells[0]))->toBeTrue()
        ->and($bold($table->rows[1]->cells[1]))->toBeFalse();
});

it('applies a row\'s exceptions to the table properties', function () {
    $document = DocxBuilder::make()->body(
        '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="dxa"/>'
        .'<w:tblBorders><w:top w:val="single" w:sz="4" w:color="111111"/><w:bottom w:val="single" w:sz="4" w:color="111111"/></w:tblBorders>'
        .'</w:tblPr>'
        .'<w:tblGrid><w:gridCol w:w="5000"/></w:tblGrid>'
        .'<w:tr>'.cellXml('ordinary').'</w:tr>'
        .'<w:tr><w:tblPrEx><w:tblBorders><w:top w:val="single" w:sz="24" w:color="FF0000"/><w:bottom w:val="single" w:sz="24" w:color="FF0000"/></w:tblBorders></w:tblPrEx>'
        .cellXml('exceptional').'</w:tr>'
        .'</w:tbl>'
    )->read();

    $table = firstTable($document->blocks);

    expect($table->rows[0]->cells[0]->properties->borders->top?->color)->toBe('111111')
        ->and($table->rows[1]->cells[0]->properties->borders->bottom?->color)->toBe('FF0000')
        ->and($table->rows[1]->cells[0]->properties->borders->bottom?->size)->toBe(24);
});
