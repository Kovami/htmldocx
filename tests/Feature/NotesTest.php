<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\DocumentMetadata;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\Note;
use Kovami\HtmlDocx\Model\NoteReference;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\TextRun;
use Kovami\HtmlDocx\Tests\Support\Docx;
use Kovami\HtmlDocx\Tests\Support\DocxIntegrity;

/** A document whose text carries one footnote and one endnote. */
function annotatedDocument(): Document
{
    $run = new RunProperties(fontFamily: 'Calibri', size: 22, color: '000000');
    $mark = new RunProperties(fontFamily: 'Calibri', size: 22, color: '000000', verticalAlign: 'superscript');

    $note = static fn (string $type, string $text): Note => new Note($type, 1, [
        new Paragraph(new ParagraphProperties(spacingBefore: 0, spacingAfter: 0), [new TextRun($text, $run)]),
    ]);

    return new Document(
        blocks: [new Paragraph(new ParagraphProperties, [
            new TextRun('Claim', $run),
            new NoteReference(Note::FOOTNOTE, 1, $mark),
            new TextRun(' and another', $run),
            new NoteReference(Note::ENDNOTE, 1, $mark),
        ])],
        defaultRunProperties: $run,
        styles: [],
        lists: [],
        pageLayout: PageLayout::a4Portrait(),
        metadata: new DocumentMetadata(new DateTimeImmutable('2026-01-02T03:04:05Z')),
        notes: [$note(Note::FOOTNOTE, 'The footnote body.'), $note(Note::ENDNOTE, 'The endnote body.')],
    );
}

it('writes the note parts a document with notes needs', function () {
    $bytes = (new HtmlDocx(testOptions()))->writeDocx(annotatedDocument());
    $docx = Docx::fromBytes($bytes);

    expect(DocxIntegrity::violations($docx))->toBe([])
        ->and($docx->has('word/footnotes.xml'))->toBeTrue()
        ->and($docx->has('word/endnotes.xml'))->toBeTrue()
        ->and($docx->count('//w:footnoteReference'))->toBe(1)
        ->and($docx->count('//w:endnoteReference'))->toBe(1)
        // The separators Word draws, plus the note itself.
        ->and($docx->count('//w:footnote', null, 'word/footnotes.xml'))->toBe(3)
        ->and($docx->first('//w:footnote[@w:id="1"]//w:t', null, 'word/footnotes.xml')?->textContent)->toBe('The footnote body.')
        ->and($docx->first('//w:footnote[@w:id="1"]//w:footnoteRef', null, 'word/footnotes.xml'))->not->toBeNull()
        ->and($docx->first('//w:endnote[@w:id="1"]//w:t', null, 'word/endnotes.xml')?->textContent)->toBe('The endnote body.');
});

it('reads its own notes back and links them from the HTML', function () {
    $converter = new HtmlDocx(testOptions());
    $read = $converter->readDocx($converter->writeDocx(annotatedDocument()));

    expect($read->notes)->toHaveCount(2)
        ->and($read->notes[0]->type)->toBe(Note::FOOTNOTE)
        ->and($read->notes[1]->type)->toBe(Note::ENDNOTE);

    $html = $converter->writeHtml($read);

    expect($html)
        ->toContain('<sup><a href="#footnote-1" id="footnote-ref-1">1</a></sup>')
        ->toContain('<sup><a href="#endnote-1" id="endnote-ref-1">i</a></sup>')
        ->toContain('<li id="footnote-1">')
        ->toContain('The footnote body.')
        ->toContain('<a href="#footnote-ref-1">')
        ->toContain('<li id="endnote-1">');
});

it('keeps notes through a second package', function () {
    $converter = new HtmlDocx(testOptions());
    $once = $converter->readDocx($converter->writeDocx(annotatedDocument()));
    $twice = $converter->readDocx($converter->writeDocx($once));

    expect($converter->writeHtml($twice))->toBe($converter->writeHtml($once));
});

/**
 * The plain text of a block sequence, runs and hyperlinks alike.
 *
 * @param  list<Paragraph>  $blocks
 */
function noteText(array $blocks): string
{
    $text = '';

    foreach ($blocks as $block) {
        foreach ($block->children as $inline) {
            $text .= match (true) {
                $inline instanceof TextRun => $inline->text,
                $inline instanceof Hyperlink => implode('', array_map(static fn ($run) => $run->text ?? '', $inline->children)),
                default => '',
            };
        }
    }

    return $text;
}

it('reads its own notes section back as notes', function () {
    $converter = new HtmlDocx(testOptions());
    $read = $converter->readHtml($converter->writeHtml(annotatedDocument()));

    expect($read->notes)->toHaveCount(2)
        ->and($read->notes[0]->type)->toBe(Note::FOOTNOTE)
        ->and($read->notes[0]->number)->toBe(1)
        ->and($read->notes[1]->type)->toBe(Note::ENDNOTE)
        ->and(noteText($read->notes[0]->blocks))->toBe('The footnote body.')
        ->and(noteText($read->notes[1]->blocks))->toBe('The endnote body.');

    $marks = array_values(array_filter($read->blocks[0]->children, static fn ($inline) => $inline instanceof NoteReference));

    expect($marks)->toHaveCount(2)
        ->and($marks[0]->type)->toBe(Note::FOOTNOTE)
        ->and($marks[0]->number)->toBe(1)
        ->and($marks[0]->properties->verticalAlign)->toBe('superscript')
        ->and($marks[1]->type)->toBe(Note::ENDNOTE)
        ->and(noteText($read->blocks))->toBe('Claim and another');
});

it('keeps notes as notes through DOCX to HTML and back', function () {
    $converter = new HtmlDocx(testOptions());
    $html = $converter->docxToHtml($converter->writeDocx(annotatedDocument()));
    $bytes = $converter->htmlToDocx($html);
    $docx = Docx::fromBytes($bytes);

    expect(DocxIntegrity::violations($docx))->toBe([])
        ->and($docx->count('//w:footnoteReference'))->toBe(1)
        ->and($docx->count('//w:endnoteReference'))->toBe(1)
        ->and($docx->first('//w:footnote[@w:id="1"]//w:t', null, 'word/footnotes.xml')?->textContent)->toBe('The footnote body.')
        ->and($docx->first('//w:endnote[@w:id="1"]//w:t', null, 'word/endnotes.xml')?->textContent)->toBe('The endnote body.')
        ->and($converter->docxToHtml($bytes))->toBe($html);
});

it('numbers note bodies by their list value and drops what frames them', function () {
    $document = (new HtmlDocx(testOptions()))->readHtml(<<<'HTML'
        <p>Text<sup><a href="#fn-4" id="ref-4">4</a></sup></p>
        <hr>
        <ol class="se-footnotes"><li id="fn-4" value="4"><p>Fourth.<a href="#ref-4"> ↩</a></p></li></ol>
        HTML);

    expect($document->notes)->toHaveCount(1)
        ->and($document->notes[0]->number)->toBe(4)
        ->and(noteText($document->notes[0]->blocks))->toBe('Fourth.')
        ->and($document->blocks)->toHaveCount(1)
        ->and(noteText($document->blocks))->toBe('Text');
});
