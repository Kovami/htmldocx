<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Tests\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * Read-only view of a generated .docx, opened with ext-zip and ext-dom —
 * independent of the package's own writer code.
 */
final class Docx
{
    public const string W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @var array<string, DOMXPath> */
    private array $xpaths = [];

    /**
     * @param  array<string, string>  $parts  in archive order
     */
    private function __construct(
        public readonly array $parts,
        public readonly string $bytes,
    ) {}

    public static function fromBytes(string $bytes): self
    {
        $path = tempnam(sys_get_temp_dir(), 'kovami_docx_');
        file_put_contents($path, $bytes);

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
                throw new RuntimeException('Not a valid ZIP archive.');
            }

            $parts = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $parts[$name] = (string) $zip->getFromIndex($i);
            }

            $zip->close();
        } finally {
            unlink($path);
        }

        return new self($parts, $bytes);
    }

    public function has(string $part): bool
    {
        return isset($this->parts[$part]);
    }

    public function xpath(string $part = 'word/document.xml'): DOMXPath
    {
        if (! isset($this->xpaths[$part])) {
            $document = new DOMDocument;

            if (! isset($this->parts[$part]) || ! @$document->loadXML($this->parts[$part])) {
                throw new RuntimeException("Part {$part} is missing or not well-formed.");
            }

            $xpath = new DOMXPath($document);

            foreach ([
                'w' => self::W,
                'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
                'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
                'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
                'm' => 'http://schemas.openxmlformats.org/officeDocument/2006/math',
                'rel' => 'http://schemas.openxmlformats.org/package/2006/relationships',
                'ct' => 'http://schemas.openxmlformats.org/package/2006/content-types',
                'cp' => 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties',
                'dc' => 'http://purl.org/dc/elements/1.1/',
                'dcterms' => 'http://purl.org/dc/terms/',
                'w14' => 'http://schemas.microsoft.com/office/word/2010/wordml',
                'w15' => 'http://schemas.microsoft.com/office/word/2012/wordml',
            ] as $prefix => $uri) {
                $xpath->registerNamespace($prefix, $uri);
            }

            $this->xpaths[$part] = $xpath;
        }

        return $this->xpaths[$part];
    }

    /**
     * @return list<DOMElement>
     */
    public function query(string $expression, ?DOMNode $context = null, string $part = 'word/document.xml'): array
    {
        $result = [];

        foreach ($this->xpath($part)->query($expression, $context) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $result[] = $node;
            }
        }

        return $result;
    }

    public function first(string $expression, ?DOMNode $context = null, string $part = 'word/document.xml'): ?DOMElement
    {
        return $this->query($expression, $context, $part)[0] ?? null;
    }

    public function count(string $expression, ?DOMNode $context = null, string $part = 'word/document.xml'): int
    {
        return count($this->query($expression, $context, $part));
    }

    /** Reads a `w:`-namespaced attribute, e.g. attr($el, 'val'). */
    public static function attr(?DOMElement $element, string $name): ?string
    {
        return $element !== null && $element->hasAttributeNS(self::W, $name) ? $element->getAttributeNS(self::W, $name) : null;
    }

    /** `w:val` of the first element matching the expression. */
    public function val(string $expression, ?DOMNode $context = null, string $part = 'word/document.xml'): ?string
    {
        return self::attr($this->first($expression, $context, $part), 'val');
    }

    /** Visible text of a node: w:t text, w:tab as "\t", w:br as "\n". */
    public function text(DOMNode $node): string
    {
        $text = '';

        foreach ($this->query('.//w:t | .//w:tab | .//w:br', $node) as $element) {
            $text .= match ($element->localName) {
                't' => $element->textContent,
                'tab' => "\t",
                default => "\n",
            };
        }

        return $text;
    }

    /**
     * @return list<DOMElement> top-level body paragraphs
     */
    public function paragraphs(): array
    {
        return $this->query('/w:document/w:body/w:p');
    }

    /**
     * @return list<string> text of each top-level body paragraph that has any
     */
    public function paragraphTexts(): array
    {
        return array_values(array_filter(array_map($this->text(...), $this->paragraphs()), static fn (string $t): bool => $t !== ''));
    }

    /** The innermost paragraph (at any depth) whose text contains $needle. */
    public function paragraph(string $needle): DOMElement
    {
        foreach (array_reverse($this->query('//w:p')) as $paragraph) {
            if (str_contains($this->text($paragraph), $needle)) {
                return $paragraph;
            }
        }

        throw new RuntimeException("No paragraph contains \"{$needle}\".");
    }

    /** The run whose own text contains $needle. */
    public function run(string $needle): DOMElement
    {
        foreach ($this->query('//w:r[w:t]') as $run) {
            if (str_contains($this->text($run), $needle)) {
                return $run;
            }
        }

        throw new RuntimeException("No run contains \"{$needle}\".");
    }
}
