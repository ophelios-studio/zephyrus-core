<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Immutable value object representing a parsed URI.
 *
 * Constructed once from a full URL string and provides typed accessors for
 * each component (scheme, host, port, path, query string, fragment).
 */
final readonly class Uri
{
    private string $scheme;
    private string $host;
    private ?int $port;
    private string $path;
    private string $queryString;
    private string $fragment;

    /**
     * @param string $url Full URL string (e.g. "https://example.com:8443/users?page=2#top").
     */
    public function __construct(private string $url)
    {
        $parts = parse_url($url);

        $this->scheme = strtolower($parts['scheme'] ?? 'http');
        $this->host = strtolower($parts['host'] ?? 'localhost');
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path = $parts['path'] ?? '/';
        $this->queryString = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): ?int
    {
        return $this->port;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Raw query string without the leading "?".
     *
     * Example: "page=2&sort=name"
     */
    public function queryString(): string
    {
        return $this->queryString;
    }

    public function fragment(): string
    {
        return $this->fragment;
    }

    public function isSecure(): bool
    {
        return $this->scheme === 'https';
    }

    /**
     * Scheme + host + optional non-default port.
     *
     * Example: "https://example.com" or "http://localhost:8080"
     */
    public function baseUrl(): string
    {
        $base = $this->scheme . '://' . $this->host;

        if ($this->port !== null && !$this->isDefaultPort()) {
            $base .= ':' . $this->port;
        }

        return $base;
    }

    /**
     * Full original URL string as provided at construction time.
     */
    public function full(): string
    {
        return $this->url;
    }

    public function __toString(): string
    {
        return $this->url;
    }

    private function isDefaultPort(): bool
    {
        return ($this->scheme === 'http' && $this->port === 80)
            || ($this->scheme === 'https' && $this->port === 443);
    }
}
