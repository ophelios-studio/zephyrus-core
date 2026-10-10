<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Immutable value object representing a parsed URI.
 *
 * Built from a full URL string, with typed accessors per component. Malformed input never throws: an unreadable
 * authority is kept as written, and refusing such a request is up to the caller.
 */
final readonly class Uri
{
    /**
     * A C0 control character or DEL, not a general sanitiser.
     *
     * @internal
     */
    public const string CONTROL_CHARACTER_PATTERN = '/[\x00-\x1F\x7F]/';

    /** Anchored, so a "://" inside an origin-form query is not read as a scheme. */
    private const SCHEME_PATTERN = '#^([a-zA-Z][a-zA-Z0-9+.\-]*)://#';

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

        if ($parts === false) {
            $parts = self::decompose($url);
        }

        $this->scheme = strtolower($parts['scheme'] ?? 'http');
        $this->host = strtolower($parts['host'] ?? 'localhost');
        $this->port = isset($parts['port']) ? (int) $parts['port'] : null;
        $this->path = $parts['path'] ?? '/';
        $this->queryString = $parts['query'] ?? '';
        $this->fragment = $parts['fragment'] ?? '';
    }

    /**
     * Splits a URL that parse_url() refused, keeping each part as written.
     *
     * Nothing is invented: an unreadable authority is not replaced by "localhost", so the scheme and host the
     * caller sees are the ones sent. "localhost" is the default only when the URL has no authority.
     *
     * @return array{scheme?: string, host?: string, port?: int, path?: string, query?: string, fragment?: string}
     */
    private static function decompose(string $url): array
    {
        $parts = [];
        $remainder = $url;

        if (preg_match(self::SCHEME_PATTERN, $remainder, $matches) === 1) {
            $parts['scheme'] = $matches[1];
            $remainder = substr($remainder, strlen($matches[0]));

            $authority = self::cutAuthority($remainder);
            $remainder = substr($remainder, strlen($authority));

            // Userinfo is dropped up to the last "@", as parse_url() does.
            $userinfoEnd = strrpos($authority, '@');
            if ($userinfoEnd !== false) {
                $authority = substr($authority, $userinfoEnd + 1);
            }

            $host = $authority;

            // Only a valid port number is split off; anything else stays in the host, so a host
            // allowlist never approves a host that was not sent.
            if (preg_match('#^(\[[^\]]*\]|[^:]*):(\d+)$#D', $authority, $portMatch) === 1
                && self::isPortNumber($portMatch[2])) {
                $host = $portMatch[1];
                $parts['port'] = (int) $portMatch[2];
            }

            if ($host !== '') {
                $parts['host'] = $host;
            }
        }

        $fragmentAt = strpos($remainder, '#');
        if ($fragmentAt !== false) {
            $parts['fragment'] = substr($remainder, $fragmentAt + 1);
            $remainder = substr($remainder, 0, $fragmentAt);
        }

        $queryAt = strpos($remainder, '?');
        if ($queryAt !== false) {
            $parts['query'] = substr($remainder, $queryAt + 1);
            $remainder = substr($remainder, 0, $queryAt);
        }

        if ($remainder !== '') {
            $parts['path'] = $remainder;
        }

        return $parts;
    }

    private static function isPortNumber(string $port): bool
    {
        return preg_match('/^[1-9][0-9]{0,4}$/D', $port) === 1 && (int) $port <= 65535;
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function host(): string
    {
        return $this->host;
    }

    /**
     * The authority as written (userinfo and port included, not lowercased), or the lowercased host when the URL
     * has no scheme. Do not build URLs from it: it keeps the userinfo.
     */
    public function authority(): string
    {
        return self::rawAuthority($this->url) ?? $this->host;
    }

    public function port(): ?int
    {
        return $this->port;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Raw query string, without the leading "?". */
    public function queryString(): string
    {
        return $this->queryString;
    }

    /**
     * Whether the path as written holds a control character or DEL.
     *
     * path() shows such a byte as "_", except when parse_url() refused the URL: the path is then kept as written.
     */
    public function pathHasControlCharacter(): bool
    {
        return preg_match(self::CONTROL_CHARACTER_PATTERN, self::rawPath($this->url)) === 1;
    }

    public function fragment(): string
    {
        return $this->fragment;
    }

    public function isSecure(): bool
    {
        return $this->scheme === 'https';
    }

    /** Scheme and host, plus the port unless it is the scheme's default, e.g. "https://example.com". */
    public function baseUrl(): string
    {
        $base = $this->scheme . '://' . $this->host;

        if ($this->port !== null && !$this->isDefaultPort()) {
            $base .= ':' . $this->port;
        }

        return $base;
    }

    /** The URL as given to the constructor. */
    public function full(): string
    {
        return $this->url;
    }

    public function __toString(): string
    {
        return $this->url;
    }

    /** The text between "scheme://" and the first "/", "?" or "#", or null without a scheme. */
    private static function rawAuthority(string $url): ?string
    {
        if (preg_match(self::SCHEME_PATTERN, $url, $matches) !== 1) {
            return null;
        }

        return self::cutAuthority(substr($url, strlen($matches[0])));
    }

    /** The text from the end of the authority (if any) up to the first "?" or "#". */
    private static function rawPath(string $url): string
    {
        if (preg_match(self::SCHEME_PATTERN, $url, $matches) === 1) {
            $url = substr($url, strlen($matches[0]));
            $url = substr($url, strcspn($url, '/?#'));
        }

        return substr($url, 0, strcspn($url, '?#'));
    }

    /** The authority at the start of the text after "scheme://". */
    private static function cutAuthority(string $afterScheme): string
    {
        return substr($afterScheme, 0, strcspn($afterScheme, '/?#'));
    }

    private function isDefaultPort(): bool
    {
        return ($this->scheme === 'http' && $this->port === 80)
            || ($this->scheme === 'https' && $this->port === 443);
    }
}
