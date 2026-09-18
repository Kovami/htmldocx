<?php

declare(strict_types=1);

use Kovami\HtmlDocx\Config\PageLayout;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Model\Comment;
use Kovami\HtmlDocx\Model\CommentEnd;
use Kovami\HtmlDocx\Model\CommentStart;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\DocumentMetadata;
use Kovami\HtmlDocx\Model\Hyperlink;
use Kovami\HtmlDocx\Model\Inline;
use Kovami\HtmlDocx\Model\Paragraph;
use Kovami\HtmlDocx\Model\ParagraphProperties;
use Kovami\HtmlDocx\Model\RunProperties;
use Kovami\HtmlDocx\Model\TextRun;
use Kovami\HtmlDocx\Tests\Support\Docx;
use Kovami\HtmlDocx\Tests\Support\DocxBuilder;
use Kovami\HtmlDocx\Tests\Support\DocxIntegrity;

/**
 * Paragraph content as text, comment boundaries written `[1` and `1]`, so a
 * test reads where each range begins and ends.
 *
 * @param  list<Inline>  $inlines
 */
function commentedText(array $inlines): string
{
    $text = '';

    foreach ($inlines as $inline) {
        $text .= match (true) {
            $inline instanceof TextRun => $inline->text,
            $inline instanceof CommentStart => "[{$inline->id}",
            $inline instanceof CommentEnd => "{$inline->id}]",
            $inline instanceof Hyperlink => '<'.commentedText($inline->children).'>',
            default => '',
        };
    }

    return $text;
}

/** @return list<string> */
function commentedParagraphs(Document $document): array
{
    return array_map(static fn (Paragraph $paragraph): string => commentedText($paragraph->children), $document->blocks);
}

/** @return list<string> */
function commentTexts(Document $document): array
{
    return array_map(
        static fn (Comment $comment): string => implode("\n", array_map(static fn (Paragraph $p): string => commentedText($p->children), $comment->blocks)),
        $document->comments,
    );
}

/**
 * Two paragraphs: comment 1 runs across both and is resolved, comment 2 sits
 * inside it on one word, comment 3 answers comment 1.
 */
function reviewedDocument(): Document
{
    $run = new RunProperties(fontFamily: 'Calibri', size: 22, color: '000000');
    $properties = static fn (): ParagraphProperties => new ParagraphProperties(spacingBefore: 0, spacingAfter: 0);
    $body = static fn (string $text): array => [new Paragraph($properties(), [new TextRun($text, $run)])];

    return new Document(
        blocks: [
            new Paragraph($properties(), [
                new TextRun('Plain ', $run),
                new CommentStart(1),
                new CommentStart(3),
                new TextRun('first ', $run),
                new CommentStart(2),
                new TextRun('word', $run),
                new CommentEnd(2),
            ]),
            new Paragraph($properties(), [
                new TextRun('second', $run),
                new CommentEnd(1),
                new CommentEnd(3),
                new TextRun(' after', $run),
            ]),
        ],
        defaultRunProperties: $run,
        styles: [],
        lists: [],
        pageLayout: PageLayout::a4Portrait(),
        metadata: new DocumentMetadata(new DateTimeImmutable('2026-01-02T03:04:05Z')),
        comments: [
            new Comment(1, $body('Rephrase this.'), 'Ann Reviewer', 'AR', new DateTimeImmutable('2026-05-06T07:08:00Z'), resolved: true),
            new Comment(2, $body('Typo?'), 'Bob'),
            new Comment(3, $body('Done.'), 'Ann Reviewer', 'AR', parentId: 1, resolved: true),
        ],
    );
}

it('reads comments, their ranges and threads the way Word writes them', function () {
    $document = DocxBuilder::make()
        ->comments(
            '<w:comment w:id="7" w:author="Ann" w:date="2026-05-06T07:08:09Z" w:initials="A">'
            .'<w:p w14:paraId="0A000001"><w:r><w:annotationRef/></w:r><w:r><w:t>Rephrase this.</w:t></w:r></w:p></w:comment>'
            .'<w:comment w:id="9" w:author="Bob"><w:p w14:paraId="0A000002"><w:r><w:t>Agreed.</w:t></w:r></w:p></w:comment>'
            .'<w:comment w:id="12" w:author="Cy"><w:p w14:paraId="0A000003"><w:r><w:t>Here.</w:t></w:r></w:p></w:comment>'
        )
        ->commentsExtended(
            // Word keeps the state of a thread on its first comment.
            '<w15:commentEx w15:paraId="0A000001" w15:done="1"/>'
            .'<w15:commentEx w15:paraId="0A000002" w15:paraIdParent="0A000001" w15:done="0"/>'
        )
        ->body(
            '<w:commentRangeStart w:id="7"/><w:commentRangeStart w:id="9"/>'
            .'<w:p><w:r><w:t>First</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>second</w:t></w:r><w:commentRangeEnd w:id="7"/><w:r><w:commentReference w:id="7"/></w:r>'
            .'<w:commentRangeEnd w:id="9"/><w:r><w:commentReference w:id="9"/></w:r>'
            // A comment on a point: Word writes only the reference.
            .'<w:r><w:t xml:space="preserve"> point</w:t></w:r><w:r><w:commentReference w:id="12"/></w:r></w:p>'
        )
        ->read();

    expect(commentedParagraphs($document))->toBe(['[1[2First', 'second1]2] point[33]'])
        ->and(commentTexts($document))->toBe(['Rephrase this.', 'Agreed.', 'Here.'])
        ->and($document->comments[0]->author)->toBe('Ann')
        ->and($document->comments[0]->initials)->toBe('A')
        ->and($document->comments[0]->date?->format('c'))->toBe('2026-05-06T07:08:09+00:00')
        ->and($document->comments[0]->parentId)->toBeNull()
        ->and($document->comments[0]->resolved)->toBeTrue()
        ->and($document->comments[1]->parentId)->toBe(1)
        ->and($document->comments[1]->resolved)->toBeTrue()
        ->and($document->comments[2]->author)->toBe('Cy');
});

it('anchors a comment whose range lost its end', function () {
    $document = DocxBuilder::make()
        ->comments('<w:comment w:id="1" w:author="A"><w:p><w:r><w:t>Note</w:t></w:r></w:p></w:comment>'
            .'<w:comment w:id="2" w:author="B"><w:p><w:r><w:t>Orphan</w:t></w:r></w:p></w:comment>')
        ->body('<w:p><w:r><w:t>One </w:t></w:r><w:commentRangeStart w:id="1"/><w:r><w:t>two</w:t></w:r></w:p>')
        ->read();

    // Comment 1 closes where it starts; comment 2, anchored nowhere, opens the document.
    expect(commentedParagraphs($document))->toBe(['[22]One [11]two']);
});

it('leaves comments out on request', function () {
    $document = DocxBuilder::make()
        ->comments('<w:comment w:id="1" w:author="A"><w:p><w:r><w:t>Note</w:t></w:r></w:p></w:comment>')
        ->body('<w:p><w:commentRangeStart w:id="1"/><w:r><w:t>text</w:t></w:r><w:commentRangeEnd w:id="1"/></w:p>')
        ->read(testOptions(['includeComments' => false]));

    expect($document->comments)->toBe([])
        ->and(commentedParagraphs($document))->toBe(['text']);
});

it('writes comment ranges, the comments part and the threads', function () {
    $docx = Docx::fromBytes((new HtmlDocx(testOptions()))->writeDocx(reviewedDocument()));

    expect(DocxIntegrity::violations($docx))->toBe([])
        ->and($docx->count('//w:commentRangeStart'))->toBe(3)
        ->and($docx->count('//w:commentRangeEnd'))->toBe(3)
        ->and($docx->count('//w:r/w:commentReference'))->toBe(3)
        ->and($docx->first('//w:comment[@w:id="1"]', null, 'word/comments.xml')?->getAttribute('w:author'))->toBe('Ann Reviewer')
        ->and($docx->first('//w:comment[@w:id="1"]', null, 'word/comments.xml')?->getAttribute('w:date'))->toBe('2026-05-06T07:08:00Z')
        ->and($docx->first('//w:comment[@w:id="1"]//w:annotationRef', null, 'word/comments.xml'))->not->toBeNull()
        ->and($docx->first('//w:comment[@w:id="2"]//w:t', null, 'word/comments.xml')?->textContent)->toBe('Typo?')
        ->and($docx->count('//w15:commentEx', null, 'word/commentsExtended.xml'))->toBe(3)
        ->and($docx->first('//w15:commentEx[w15:paraIdParent or @w15:paraIdParent]', null, 'word/commentsExtended.xml')?->getAttribute('w15:paraIdParent'))
        ->toBe($docx->first('//w:comment[@w:id="1"]/w:p[last()]', null, 'word/comments.xml')?->getAttribute('w14:paraId'))
        ->and($docx->count('//w15:commentEx[@w15:done="1"]', null, 'word/commentsExtended.xml'))->toBe(2);
});

it('marks commented text with spans and lists the comments after the body', function () {
    $html = (new HtmlDocx(testOptions()))->writeHtml(reviewedDocument());

    expect($html)
        ->toContain('<p style="margin-bottom: 0;">Plain <span class="se-comment" data-comment="1 3">first </span><span class="se-comment" data-comment="1 3 2">word</span></p>')
        ->toContain('<p style="margin-bottom: 0;"><span class="se-comment" data-comment="1 3">second</span> after</p>')
        ->toContain('<ol class="se-comments">')
        ->toContain('<li id="comment-1" data-comment="1" data-author="Ann Reviewer" data-initials="AR" data-date="2026-05-06T07:08:00Z" data-resolved="true">')
        ->toContain('<li id="comment-3" data-comment="3" data-author="Ann Reviewer" data-initials="AR" data-parent="1" data-resolved="true">');
});

it('reads its own comment markup back', function () {
    $converter = new HtmlDocx(testOptions());
    $read = $converter->readHtml($converter->writeHtml(reviewedDocument()));

    expect(commentedParagraphs($read))->toBe(['Plain [1[3first [2word2]', 'second1]3] after'])
        ->and(commentTexts($read))->toBe(['Rephrase this.', 'Typo?', 'Done.'])
        ->and($read->comments[0]->date?->format('c'))->toBe('2026-05-06T07:08:00+00:00')
        ->and($read->comments[2]->parentId)->toBe(1)
        ->and($read->comments[2]->resolved)->toBeTrue();
});

it('keeps comments through DOCX to HTML and back', function () {
    $converter = new HtmlDocx(testOptions());
    $html = $converter->docxToHtml($converter->writeDocx(reviewedDocument()));
    $bytes = $converter->htmlToDocx($html);

    expect(DocxIntegrity::violations(Docx::fromBytes($bytes)))->toBe([])
        ->and($converter->docxToHtml($bytes))->toBe($html);
});

it('reads comment markup an editor has reshaped', function () {
    $document = (new HtmlDocx(testOptions()))->readHtml(<<<'HTML'
        <p>Go <a href="https://example.com">to <span class="se-comment" data-comment="1">the</span> site</a> now</p>
        <p>Lost <span class="se-comment" data-comment="9">anchor</span></p>
        <ol class="se-comments">
          <li data-comment="1" data-author="A"><p>On the link</p></li>
          <li data-comment="2"><p>Its text was deleted</p></li>
        </ol>
        HTML);

    // An unknown id marks nothing; a comment whose text is gone opens the document.
    expect(commentedParagraphs($document))->toBe(['[22]Go <to >[1<the>1]< site> now', 'Lost anchor'])
        ->and(commentTexts($document))->toBe(['On the link', 'Its text was deleted'])
        ->and($document->comments[0]->author)->toBe('A')
        ->and($document->comments[1]->author)->toBeNull();
});

it('numbers comments in the order of their anchors, whatever order the part lists them in', function () {
    $document = DocxBuilder::make()
        ->comments('<w:comment w:id="0" w:author="Late"><w:p><w:r><w:t>second</w:t></w:r></w:p></w:comment>'
            .'<w:comment w:id="5" w:author="Early"><w:p><w:r><w:t>first</w:t></w:r></w:p></w:comment>')
        ->body('<w:p><w:commentRangeStart w:id="5"/><w:r><w:t>a</w:t></w:r><w:commentRangeEnd w:id="5"/>'
            .'<w:commentRangeStart w:id="0"/><w:r><w:t>b</w:t></w:r><w:commentRangeEnd w:id="0"/></w:p>')
        ->read();

    expect(commentedParagraphs($document))->toBe(['[1a1][2b2]'])
        ->and(commentTexts($document))->toBe(['first', 'second']);
});

it('keeps a thread in one state and breaks loops of replies', function () {
    $document = (new HtmlDocx(testOptions()))->readHtml(<<<'HTML'
        <p><span class="se-comment" data-comment="1 2 3 4">text</span></p>
        <ol class="se-comments">
          <li data-comment="1" data-resolved="true"><p>Root</p></li>
          <li data-comment="2" data-parent="1"><p>Reply</p></li>
          <li data-comment="3" data-parent="4" data-resolved="true"><p>Loop A</p></li>
          <li data-comment="4" data-parent="3"><p>Loop B</p></li>
        </ol>
        HTML);

    $threads = array_map(static fn (Comment $comment): array => [$comment->parentId, $comment->resolved], $document->comments);

    expect($threads)->toBe([[null, true], [1, true], [null, true], [null, false]]);
});

it('keeps white space collapsing across comment boundaries', function () {
    $document = (new HtmlDocx(testOptions()))->readHtml(
        '<p>a <span class="se-comment" data-comment="1"> b </span> c</p><ol class="se-comments"><li data-comment="1"><p>x</p></li></ol>'
    );

    expect(commentedParagraphs($document))->toBe(['a [1b 1]c']);
});
