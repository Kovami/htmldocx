<?php

declare(strict_types=1);

namespace Kovami\HtmlDocx\Image;

use Closure;

/**
 * Resolves `data:` URIs out of the box. Local files are read only below an
 * explicitly configured directory, and http(s) URLs only through a fetcher
 * supplied by the application: a library that fetched arbitrary URLs from
 * user-authored HTML would be an SSRF vector.
 */
final readonly class DefaultImageSourceResolver implements ImageSourceResolver
{
    /**
     * @param  null|Closure(string): ?string  $remoteFetcher
     */
    public function __construct(
        public ?Closure $remoteFetcher = null,
        public ?string $localBaseDirectory = null,
    ) {}

    public function resolve(string $source): ?string
    {
        $source = trim($source);

        return match (true) {
            $source === '' => null,
            str_starts_with(strtolower($source), 'data:') => $this->decodeDataUri($source),
            (bool) preg_match('#^(https?:)?//#i', $source) => $this->fetchRemote($source),
            (bool) preg_match('#^[a-z][a-z0-9+.-]*:#i', $source) => null,
            default => $this->readLocal($source),
        };
    }

    private function decodeDataUri(string $source): ?string
    {
        if (! preg_match('#^data:([^,]*?),(.*)$#is', $source, $m)) {
            return null;
        }

        if (str_ends_with(strtolower($m[1]), ';base64')) {
            $decoded = base64_decode((string) preg_replace('/\s+/', '', $m[2]), true);

            return $decoded === false || $decoded === '' ? null : $decoded;
        }

        $decoded = rawurldecode($m[2]);

        return $decoded === '' ? null : $decoded;
    }

    private function fetchRemote(string $source): ?string
    {
        if ($this->remoteFetcher === null) {
            return null;
        }

        $url = str_starts_with($source, '//') ? 'https:'.$source : $source;
        $bytes = ($this->remoteFetcher)($url);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    private function readLocal(string $source): ?string
    {
        if ($this->localBaseDirectory === null) {
            return null;
        }

        $base = realpath($this->localBaseDirectory);
        $path = rawurldecode((string) preg_replace('/[?#].*$/', '', $source));

        if ($base === false || $path === '') {
            return null;
        }

        $candidate = realpath($base.DIRECTORY_SEPARATOR.ltrim($path, '/\\'));

        if ($candidate === false || ! is_file($candidate) || ! str_starts_with($candidate, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $bytes = file_get_contents($candidate);

        return $bytes === false || $bytes === '' ? null : $bytes;
    }
}
