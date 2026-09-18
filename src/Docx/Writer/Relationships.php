<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Writer;

final class Relationships
{
    public const string OFFICE_DOCUMENT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';

    public const string CORE_PROPERTIES = 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties';

    public const string EXTENDED_PROPERTIES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties';

    public const string STYLES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';

    public const string SETTINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings';

    public const string NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';

    public const string FOOTNOTES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes';

    public const string ENDNOTES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/endnotes';

    public const string HEADER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/header';

    public const string FOOTER = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer';

    public const string COMMENTS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/comments';

    public const string COMMENTS_EXTENDED = 'http://schemas.microsoft.com/office/2011/relationships/commentsExtended';

    public const string IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';

    public const string HYPERLINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';

    /** @var list<array{id: string, type: string, target: string, external: bool}> */
    private array $relationships = [];

    public function add(string $type, string $target, bool $external = false): string
    {
        foreach ($this->relationships as $relationship) {
            if ($relationship['type'] === $type && $relationship['target'] === $target && $relationship['external'] === $external) {
                return $relationship['id'];
            }
        }

        $id = 'rId'.(count($this->relationships) + 1);
        $this->relationships[] = ['id' => $id, 'type' => $type, 'target' => $target, 'external' => $external];

        return $id;
    }

    public function isEmpty(): bool
    {
        return $this->relationships === [];
    }

    public function toXml(): string
    {
        $xml = new XmlBuilder;
        $xml->open('Relationships', ['xmlns' => 'http://schemas.openxmlformats.org/package/2006/relationships']);

        foreach ($this->relationships as $relationship) {
            $xml->leaf('Relationship', [
                'Id' => $relationship['id'],
                'Type' => $relationship['type'],
                'Target' => $relationship['target'],
                'TargetMode' => $relationship['external'] ? 'External' : null,
            ]);
        }

        return $xml->close()->toString();
    }
}
