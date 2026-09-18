<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Model\HeaderFooter;
use Kovami\HtmlDocx\Model\Note;
use Kovami\HtmlDocx\Package\ZipWriter;

/** Serializes the document model into an Office Open XML package. */
final class DocxWriter
{
    /**
     * @param  resource  $stream
     */
    public function write(Document $document, mixed $stream): void
    {
        $relationships = new Relationships();
        $relationships->add(Relationships::STYLES, 'styles.xml');
        $relationships->add(Relationships::SETTINGS, 'settings.xml');

        $hasNumbering = $document->lists !== [];

        if ($hasNumbering) {
            $relationships->add(Relationships::NUMBERING, 'numbering.xml');
        }

        $media = new MediaRegistry();
        $parts = [];
        $headersFooters = [];
        $counts = [];

        // Each part below carries its own relationships: what it links to or
        // shows is addressed from that part, not from the document.
        foreach ($document->headersFooters as $headerFooter) {
            $partRelationships = new Relationships();
            $xml = (new DocumentPart($document, $partRelationships, $media))->headerFooterXml($headerFooter);
            $counts[$headerFooter->kind] = ($counts[$headerFooter->kind] ?? 0) + 1;
            $target = $headerFooter->kind . $counts[$headerFooter->kind] . '.xml';

            $headersFooters[] = [
                'kind' => $headerFooter->kind,
                'type' => $headerFooter->type,
                'id' => $relationships->add($headerFooter->kind === HeaderFooter::HEADER ? Relationships::HEADER : Relationships::FOOTER, $target),
            ];

            $parts += self::part($target, $xml, $partRelationships);
        }

        $parts = [
            '_rels/.rels' => PackageParts::rootRelationships(),
            'docProps/core.xml' => PackageParts::coreProperties($document->metadata),
            'docProps/app.xml' => PackageParts::appProperties(),
            'word/document.xml' => (new DocumentPart($document, $relationships, $media, $headersFooters))->toXml(),
            'word/styles.xml' => StylesPart::toXml($document),
            ...$parts,
        ];

        if ($hasNumbering) {
            $parts['word/numbering.xml'] = NumberingPart::toXml($document->lists);
        }

        $notes = [];

        foreach ([Note::FOOTNOTE => Relationships::FOOTNOTES, Note::ENDNOTE => Relationships::ENDNOTES] as $type => $relationshipType) {
            $noteRelationships = new Relationships();
            $xml = (new DocumentPart($document, $noteRelationships, $media))->notesXml($type);

            if ($xml === null) {
                continue;
            }

            $notes[] = $type;
            $target = "{$type}s.xml";
            $relationships->add($relationshipType, $target);
            $parts += self::part($target, $xml, $noteRelationships);
        }

        $extended = DocumentPart::commentsExtendedXml($document);
        $commentRelationships = new Relationships();
        $comments = (new DocumentPart($document, $commentRelationships, $media))->commentsXml($extended !== null);

        if ($comments !== null) {
            $relationships->add(Relationships::COMMENTS, 'comments.xml');
            $parts += self::part('comments.xml', $comments, $commentRelationships);
        }

        if ($comments !== null && $extended !== null) {
            $relationships->add(Relationships::COMMENTS_EXTENDED, 'commentsExtended.xml');
            $parts['word/commentsExtended.xml'] = $extended;
        }

        $evenPages = array_filter($document->headersFooters, static fn(HeaderFooter $headerFooter): bool => $headerFooter->type === HeaderFooter::EVEN);
        $parts['word/settings.xml'] = PackageParts::settings($notes, $evenPages !== []);
        $parts['word/_rels/document.xml.rels'] = $relationships->toXml();
        $parts = ['[Content_Types].xml' => PackageParts::contentTypes($media->contentTypes(), array_keys($parts)), ...$parts];

        $zip = new ZipWriter($stream, $document->metadata->createdAt);

        foreach ($parts as $name => $contents) {
            $zip->addFile($name, $contents);
        }

        foreach ($media->parts() as $name => $bytes) {
            $zip->addFile($name, $bytes, compress: false);
        }

        $zip->finish();
    }

    /**
     * A part under word/, with its relationships when it has any.
     *
     * @return array<string, string>
     */
    private static function part(string $target, string $xml, Relationships $relationships): array
    {
        $parts = ["word/{$target}" => $xml];

        if (! $relationships->isEmpty()) {
            $parts["word/_rels/{$target}.rels"] = $relationships->toXml();
        }

        return $parts;
    }
}
