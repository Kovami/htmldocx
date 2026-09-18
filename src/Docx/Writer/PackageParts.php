<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

use DateTimeZone;
use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Model\DocumentMetadata;

/** The small, mostly static parts every package needs. */
final class PackageParts
{
    /** The content type of every XML part this writer produces. */
    private const array PART_TYPES = [
        'word/document.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
        'word/styles.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
        'word/settings.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml',
        'word/numbering.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml',
        'word/footnotes.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.footnotes+xml',
        'word/endnotes.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.endnotes+xml',
        'word/comments.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml',
        'word/commentsExtended.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.commentsExtended+xml',
        'docProps/core.xml' => 'application/vnd.openxmlformats-package.core-properties+xml',
        'docProps/app.xml' => 'application/vnd.openxmlformats-officedocument.extended-properties+xml',
    ];

    /**
     * @param  array<string, string>  $mediaTypes  extension => content type
     * @param  list<string>  $parts  the package parts being written
     */
    public static function contentTypes(array $mediaTypes, array $parts): string
    {
        $xml = new XmlBuilder();
        $xml->open('Types', ['xmlns' => 'http://schemas.openxmlformats.org/package/2006/content-types'])
            ->leaf('Default', ['Extension' => 'rels', 'ContentType' => 'application/vnd.openxmlformats-package.relationships+xml'])
            ->leaf('Default', ['Extension' => 'xml', 'ContentType' => 'application/xml']);

        foreach ($mediaTypes as $extension => $contentType) {
            $xml->leaf('Default', ['Extension' => $extension, 'ContentType' => $contentType]);
        }

        foreach ($parts as $part) {
            $contentType = self::PART_TYPES[$part]
                ?? (preg_match('~^word/(header|footer)\d+\.xml$~', $part, $match) === 1
                    ? "application/vnd.openxmlformats-officedocument.wordprocessingml.{$match[1]}+xml"
                    : null);

            if ($contentType !== null) {
                $xml->leaf('Override', ['PartName' => '/' . $part, 'ContentType' => $contentType]);
            }
        }

        return $xml->close()->toString();
    }

    public static function rootRelationships(): string
    {
        $relationships = new Relationships();
        $relationships->add(Relationships::OFFICE_DOCUMENT, 'word/document.xml');
        $relationships->add(Relationships::CORE_PROPERTIES, 'docProps/core.xml');
        $relationships->add(Relationships::EXTENDED_PROPERTIES, 'docProps/app.xml');

        return $relationships->toXml();
    }

    public static function coreProperties(DocumentMetadata $metadata): string
    {
        $timestamp = $metadata->createdAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        $xml = new XmlBuilder();
        $xml->open('cp:coreProperties', [
            'xmlns:cp' => 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties',
            'xmlns:dc' => 'http://purl.org/dc/elements/1.1/',
            'xmlns:dcterms' => 'http://purl.org/dc/terms/',
            'xmlns:dcmitype' => 'http://purl.org/dc/dcmitype/',
            'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        ]);

        if ($metadata->title !== null) {
            $xml->text('dc:title', $metadata->title);
        }

        if ($metadata->author !== null) {
            $xml->text('dc:creator', $metadata->author)->text('cp:lastModifiedBy', $metadata->author);
        }

        if ($metadata->language !== null) {
            $xml->text('dc:language', $metadata->language);
        }

        return $xml
            ->text('dcterms:created', $timestamp, ['xsi:type' => 'dcterms:W3CDTF'])
            ->text('dcterms:modified', $timestamp, ['xsi:type' => 'dcterms:W3CDTF'])
            ->close()
            ->toString();
    }

    public static function appProperties(): string
    {
        return (new XmlBuilder())
            ->open('Properties', ['xmlns' => 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties'])
            ->text('Application', 'kovami/htmldocx')
            ->close()
            ->toString();
    }

    /**
     * @param  list<string>  $notes  "footnote", "endnote", or both, for the note parts the package has
     * @param  bool  $evenAndOddHeaders  whether even pages have headers and footers of their own
     */
    public static function settings(array $notes = [], bool $evenAndOddHeaders = false): string
    {
        $xml = (new XmlBuilder())
            ->open('w:settings', ['xmlns:w' => Namespaces::W])
            ->leaf('w:zoom', ['w:percent' => 100])
            ->leaf('w:defaultTabStop', ['w:val' => 708]);

        if ($evenAndOddHeaders) {
            $xml->leaf('w:evenAndOddHeaders');
        }

        $xml->leaf('w:characterSpacingControl', ['w:val' => 'doNotCompress']);

        // Word looks the separator notes up by id, so it wants them declared.
        foreach ($notes as $note) {
            $xml->open("w:{$note}Pr");

            foreach ([-1, 0] as $id) {
                $xml->leaf("w:{$note}", ['w:id' => $id]);
            }

            $xml->close();
        }

        return $xml
            ->open('w:compat')
            ->leaf('w:compatSetting', [
                'w:name' => 'compatibilityMode',
                'w:uri' => 'http://schemas.microsoft.com/office/word',
                'w:val' => 15,
            ])
            ->close()
            ->close()
            ->toString();
    }
}
