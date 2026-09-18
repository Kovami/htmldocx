<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Docx\Reader;

use Dom\XMLDocument;
use Kovami\HtmlDocx\Docx\Namespaces;
use Kovami\HtmlDocx\Exceptions\HtmlDocxException;
use Kovami\HtmlDocx\Package\ZipReader;

/**
 * Open Packaging Conventions view of a DOCX archive: parts, their content
 * types and relationships. Part names are absolute ("/word/document.xml");
 * relationship targets are resolved against their source part, and targets
 * that escape the package are treated as missing.
 */
final class OpcPackage
{
    /** @var array<string, string> lowercase extension => content type */
    private array $defaults = [];

    /** @var array<string, string> lowercase part name => content type */
    private array $overrides = [];

    /** @var array<string, array<string, Relationship>> source part => id => relationship */
    private array $relationships = [];

    public function __construct(private readonly ZipReader $zip)
    {
        if (! $zip->has('[Content_Types].xml')) {
            throw HtmlDocxException::malformedDocx('the package has no [Content_Types].xml');
        }

        $types = Xml::parse($zip->read('[Content_Types].xml'), '[Content_Types].xml')->documentElement;

        foreach (Xml::children($types, 'Default', Namespaces::CONTENT_TYPES) as $default) {
            $this->defaults[strtolower((string) $default->getAttribute('Extension'))] = strtolower((string) $default->getAttribute('ContentType'));
        }

        foreach (Xml::children($types, 'Override', Namespaces::CONTENT_TYPES) as $override) {
            $this->overrides[strtolower((string) $override->getAttribute('PartName'))] = strtolower((string) $override->getAttribute('ContentType'));
        }
    }

    public function has(string $partName): bool
    {
        return $this->zip->has($partName);
    }

    public function read(string $partName): string
    {
        return $this->zip->read($partName);
    }

    public function xml(string $partName): XMLDocument
    {
        return Xml::parse($this->zip->read($partName), $partName);
    }

    public function contentType(string $partName): ?string
    {
        $partName = '/'.ltrim($partName, '/');

        return $this->overrides[strtolower($partName)]
            ?? $this->defaults[strtolower(pathinfo($partName, PATHINFO_EXTENSION))]
            ?? null;
    }

    /** The main document part, located through the package relationships. */
    public function mainDocumentPart(): string
    {
        $main = $this->relationshipByType('/', 'officeDocument');

        if ($main === null || $main->external || ! $this->has($main->target)) {
            throw HtmlDocxException::malformedDocx('the package has no main document part');
        }

        return $main->target;
    }

    public function relationship(string $sourcePart, string $id): ?Relationship
    {
        return $this->relationshipsOf($sourcePart)[$id] ?? null;
    }

    /** The first relationship whose type ends with "/{$typeSuffix}". */
    public function relationshipByType(string $sourcePart, string $typeSuffix): ?Relationship
    {
        foreach ($this->relationshipsOf($sourcePart) as $relationship) {
            if (str_ends_with($relationship->type, '/'.$typeSuffix)) {
                return $relationship;
            }
        }

        return null;
    }

    /**
     * @return array<string, Relationship>
     */
    private function relationshipsOf(string $sourcePart): array
    {
        $sourcePart = '/'.ltrim($sourcePart, '/');

        if (isset($this->relationships[$sourcePart])) {
            return $this->relationships[$sourcePart];
        }

        $directory = $sourcePart === '/' ? '' : dirname($sourcePart);
        $relsPart = $sourcePart === '/' ? '/_rels/.rels' : rtrim($directory, '/').'/_rels/'.basename($sourcePart).'.rels';
        $relationships = [];

        if ($this->has($relsPart)) {
            foreach (Xml::children($this->xml($relsPart)->documentElement, 'Relationship', Namespaces::PACKAGE_RELATIONSHIPS) as $element) {
                $id = (string) $element->getAttribute('Id');
                $target = (string) $element->getAttribute('Target');
                $external = strcasecmp((string) $element->getAttribute('TargetMode'), 'External') === 0;

                if (! $external) {
                    $target = self::resolve($directory, $target);

                    if ($target === null) {
                        continue;
                    }
                }

                $relationships[$id] ??= new Relationship($id, (string) $element->getAttribute('Type'), $target, $external);
            }
        }

        return $this->relationships[$sourcePart] = $relationships;
    }

    /** Resolves a relative part reference; null when it leaves the package. */
    private static function resolve(string $directory, string $target): ?string
    {
        $target = rawurldecode(explode('#', $target, 2)[0]);
        $path = str_starts_with($target, '/') ? $target : rtrim($directory, '/').'/'.$target;
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
