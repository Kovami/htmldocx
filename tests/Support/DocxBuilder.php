<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Tests\Support;

use DateTimeImmutable;
use Kovami\HtmlDocx\HtmlDocx;
use Kovami\HtmlDocx\Model\Document;
use Kovami\HtmlDocx\Options;
use Kovami\HtmlDocx\Package\ZipWriter;

/**
 * Builds DOCX packages from XML fragments, so reader tests can state exactly
 * the WordprocessingML they are about — including markup our own writer never
 * produces (table styles, theme references, multi-level numbering, notes).
 */
final class DocxBuilder
{
    private const string OFFICE_DOCUMENT = 'application/vnd.openxmlformats-officedocument.wordprocessingml';

    /** @var array<string, string> part name inside the package => contents */
    private array $parts = [];

    /** @var array<string, string> part name => content type */
    private array $contentTypes = ['/word/document.xml' => self::OFFICE_DOCUMENT.'.document.main+xml'];

    /** @var list<string> */
    private array $relationships = [];

    private string $body = '';

    private string $section = '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="850" w:bottom="1134" w:left="1701"/></w:sectPr>';

    public static function make(): self
    {
        return new self;
    }

    /** The inner XML of `w:body`; the section properties are added for you. */
    public function body(string $xml): self
    {
        $this->body = $xml;

        return $this;
    }

    /** The inner XML of `w:styles`. */
    public function styles(string $xml): self
    {
        return $this->related('styles.xml', 'styles', self::OFFICE_DOCUMENT.'.styles+xml', self::root('w:styles', $xml));
    }

    /** The inner XML of `w:numbering`. */
    public function numbering(string $xml): self
    {
        return $this->related('numbering.xml', 'numbering', self::OFFICE_DOCUMENT.'.numbering+xml', self::root('w:numbering', $xml));
    }

    /** The inner XML of `a:theme`. */
    public function theme(string $xml): self
    {
        $theme = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Test">'.$xml.'</a:theme>';

        return $this->related('theme/theme1.xml', 'theme', 'application/vnd.openxmlformats-officedocument.theme+xml', $theme);
    }

    /**
     * The notes of one kind, as the inner XML of `w:footnotes` or `w:endnotes`.
     *
     * @param  string  $type  "footnote" or "endnote"
     */
    public function notes(string $type, string $xml): self
    {
        return $this->related("{$type}s.xml", "{$type}s", self::OFFICE_DOCUMENT.".{$type}s+xml", self::root("w:{$type}s", $xml));
    }

    /** The inner XML of the body's final `w:sectPr`. */
    public function section(string $xml): self
    {
        $this->section = '<w:sectPr>'.$xml.'</w:sectPr>';

        return $this;
    }

    /** The inner XML of `w:settings`. */
    public function settings(string $xml): self
    {
        return $this->related('settings.xml', 'settings', self::OFFICE_DOCUMENT.'.settings+xml', self::root('w:settings', $xml));
    }

    /**
     * A header or footer part, as the inner XML of `w:hdr` or `w:ftr`, related
     * to the document under $id; a section refers to it by that id.
     *
     * @param  string  $kind  "header" or "footer"
     */
    public function headerFooter(string $kind, string $id, string $target, string $xml): self
    {
        $this->parts["word/{$target}"] = self::root($kind === 'header' ? 'w:hdr' : 'w:ftr', $xml);
        $this->contentTypes["/word/{$target}"] = self::OFFICE_DOCUMENT.".{$kind}+xml";

        return $this->relationship($id, $kind, $target);
    }

    /** The inner XML of `w:comments`. */
    public function comments(string $xml): self
    {
        return $this->related('comments.xml', 'comments', self::OFFICE_DOCUMENT.'.comments+xml', self::root('w:comments', $xml));
    }

    /** The inner XML of Word 2013's `w15:commentsEx`: threads and the done state. */
    public function commentsExtended(string $xml): self
    {
        $this->parts['word/commentsExtended.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w15:commentsEx xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml">'.$xml.'</w15:commentsEx>';
        $this->contentTypes['/word/commentsExtended.xml'] = self::OFFICE_DOCUMENT.'.commentsExtended+xml';
        $this->relationships[] = '<Relationship Id="rIdCommentsEx" Type="http://schemas.microsoft.com/office/2011/relationships/commentsExtended" Target="commentsExtended.xml"/>';

        return $this;
    }

    /** A relationship of the document part, e.g. a hyperlink or an image. */
    public function relationship(string $id, string $type, string $target, bool $external = false): self
    {
        $this->relationships[] = '<Relationship Id="'.$id.'"'
            .' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/'.$type.'"'
            .' Target="'.$target.'"'.($external ? ' TargetMode="External"' : '').'/>';

        return $this;
    }

    /** Any other part, named relative to `word/`, e.g. `media/image1.png`. */
    public function part(string $name, string $contents, ?string $contentType = null): self
    {
        $this->parts["word/{$name}"] = $contents;

        if ($contentType !== null) {
            $this->contentTypes["/word/{$name}"] = $contentType;
        }

        return $this;
    }

    public function toBytes(): string
    {
        return self::zip([
            '[Content_Types].xml' => $this->contentTypesXml(),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',
            'word/document.xml' => self::root('w:document', '<w:body>'.$this->body.$this->section.'</w:body>'),
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .implode('', $this->relationships).'</Relationships>',
            ...$this->parts,
        ]);
    }

    /** Reads the package back through the reader under test. */
    public function read(?Options $options = null): Document
    {
        return (new HtmlDocx($options ?? new Options))->readDocx($this->toBytes());
    }

    private function related(string $target, string $type, string $contentType, string $contents): self
    {
        $this->parts["word/{$target}"] = $contents;
        $this->contentTypes["/word/{$target}"] = $contentType;

        return $this->relationship('rIdPart'.count($this->relationships), $type, $target);
    }

    private function contentTypesXml(): string
    {
        $overrides = '';

        foreach ($this->contentTypes as $part => $type) {
            $overrides .= '<Override PartName="'.$part.'" ContentType="'.$type.'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Default Extension="png" ContentType="image/png"/>'
            .$overrides
            .'</Types>';
    }

    /** Every namespace a fragment might use, declared once on the root. */
    private static function root(string $element, string $xml): string
    {
        $namespaces = [
            'w' => 'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
            'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
            'm' => 'http://schemas.openxmlformats.org/officeDocument/2006/math',
            'mc' => 'http://schemas.openxmlformats.org/markup-compatibility/2006',
            'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
            'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
            'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
            'v' => 'urn:schemas-microsoft-com:vml',
            'w10' => 'urn:schemas-microsoft-com:office:word',
            'wps' => 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape',
            'w14' => 'http://schemas.microsoft.com/office/word/2010/wordml',
        ];

        $declarations = '';

        foreach ($namespaces as $prefix => $uri) {
            $declarations .= " xmlns:{$prefix}=\"{$uri}\"";
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            ."<{$element}{$declarations}>{$xml}</{$element}>";
    }

    /**
     * @param  array<string, string>  $files  name => contents
     */
    private static function zip(array $files): string
    {
        $stream = fopen('php://memory', 'w+b');
        $zip = new ZipWriter($stream, new DateTimeImmutable('2026-01-02T03:04:05Z'));

        foreach ($files as $name => $contents) {
            $zip->addFile($name, $contents);
        }

        $zip->finish();
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
