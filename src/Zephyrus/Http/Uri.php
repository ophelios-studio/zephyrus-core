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
     * Split a URL that parse_url() refused, WITHOUT inventing anything.
     *
     * ## What went wrong before
     *
     * parse_url() returns false, not a partial result, for an authority it
     * cannot read. The constructor read every component off that false with a
     * `??`, so ONE unreadable character replaced the whole URL at once:
     * "https://app.example.com:evil/dashboard" became scheme "http", host
     * "localhost", path "/", no query, no fragment. Silently, with nothing
     * logged and nothing thrown.
     *
     * That single fallback is the root cause of two separate findings.
     * SecureHeadersMiddleware dropped HSTS from a response that arrived over
     * TLS, because the collapsed URI said "http". ForceHttpsMiddleware saw
     * isSecure() === false and redirected to HTTPS a request that was ALREADY
     * HTTPS, and since buildHttpsUrl() returns a non-"http://" string
     * unchanged, the 308 pointed at the URL just requested. A malformed Host
     * repeats on the next request, so that is a redirect loop.
     *
     * The trigger is cheap: a Host header of "app.example.com:evil" defeats
     * parse_url() outright, and Request::fromGlobals composes the URL as
     * scheme . "://" . host . target. Apache answers 400 to the direct form,
     * but a trusted X-Forwarded-Host or a laxer front server reaches it.
     *
     * ## Preserve, do not throw
     *
     * Throwing here was considered and rejected. Uri is constructed from inside
     * Request::fromGlobals(), at the very top of the lifecycle, BEFORE the
     * kernel's error handling exists; this codebase already documents that as a
     * poor place to introduce a throw (see Request::collapseLeadingSlashes()).
     * Throwing would also turn a hostile probe into an uncatchable fatal, which
     * hands an attacker a denial of service in exchange for closing a
     * disclosure that is not one.
     *
     * Preserving is strictly better, because the honest value is the one every
     * downstream check needs: isSecure() answers correctly, so HSTS survives
     * and ForceHttps stops looping, and AllowedHostsMiddleware finally judges
     * the host that was really sent instead of a "localhost" nobody sent. The
     * decision to REFUSE the request then sits in a middleware, inside the
     * kernel, where a 400 can be returned and logged.
     *
     * ## The line this draws
     *
     * An unreadable ":port" suffix stays part of the host. Trimming it would
     * report "app.example.com", a host that was never sent, which is the same
     * class of invention as "localhost" and would quietly hand a host-allowlist
     * a value it can approve.
     *
     * "localhost" is still the default when the URL genuinely carries NO
     * authority ("/just-a-path", "http://"). What was wrong was DISCARDING an
     * authority that was reported, not defaulting one that was never there.
     *
     * @return array{scheme?: string, host?: string, port?: int, path?: string, query?: string, fragment?: string}
     */
    private static function decompose(string $url): array
    {
        $parts = [];
        $remainder = $url;

        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*)://#', $remainder, $matches) === 1) {
            $parts['scheme'] = $matches[1];
            $remainder = substr($remainder, strlen($matches[0]));

            $authority = substr($remainder, 0, strcspn($remainder, '/?#'));
            $remainder = substr($remainder, strlen($authority));

            // Userinfo is a credential, never an address, and parse_url() drops
            // it too. The LAST "@" wins, because a userinfo may contain one.
            $userinfoEnd = strrpos($authority, '@');
            if ($userinfoEnd !== false) {
                $authority = substr($authority, $userinfoEnd + 1);
            }

            if ($authority !== '') {
                $parts['host'] = $authority;
            }

            // Only a port that IS a port becomes one. Anything else stays
            // visible in the host rather than being silently discarded.
            if (preg_match('#^(\[[^\]]*\]|[^:]*):(\d+)$#', $authority, $portMatch) === 1) {
                $parts['host'] = $portMatch[1];
                $parts['port'] = (int) $portMatch[2];
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
