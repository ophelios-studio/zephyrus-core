<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Uri;

final class UriTest extends TestCase
{
    #[Test]
    public function parsesFullUrl(): void
    {
        $uri = new Uri('https://example.com:8443/users?page=2&sort=name#top');

        self::assertSame('https', $uri->scheme());
        self::assertSame('example.com', $uri->host());
        self::assertSame(8443, $uri->port());
        self::assertSame('/users', $uri->path());
        self::assertSame('page=2&sort=name', $uri->queryString());
        self::assertSame('top', $uri->fragment());
        self::assertTrue($uri->isSecure());
    }

    #[Test]
    public function parsesSimpleHttpUrl(): void
    {
        $uri = new Uri('http://localhost/test');

        self::assertSame('http', $uri->scheme());
        self::assertSame('localhost', $uri->host());
        self::assertNull($uri->port());
        self::assertSame('/test', $uri->path());
        self::assertSame('', $uri->queryString());
        self::assertSame('', $uri->fragment());
        self::assertFalse($uri->isSecure());
    }

    #[Test]
    public function defaultsForMinimalUrl(): void
    {
        $uri = new Uri('/just-a-path');

        self::assertSame('http', $uri->scheme());
        self::assertSame('localhost', $uri->host());
        self::assertNull($uri->port());
        self::assertSame('/just-a-path', $uri->path());
    }

    #[Test]
    public function baseUrlExcludesDefaultPorts(): void
    {
        $http80 = new Uri('http://example.com:80/test');
        self::assertSame('http://example.com', $http80->baseUrl());

        $https443 = new Uri('https://example.com:443/test');
        self::assertSame('https://example.com', $https443->baseUrl());
    }

    #[Test]
    public function baseUrlIncludesNonDefaultPort(): void
    {
        $uri = new Uri('http://example.com:3000/test');
        self::assertSame('http://example.com:3000', $uri->baseUrl());

        $uri2 = new Uri('https://example.com:8443/test');
        self::assertSame('https://example.com:8443', $uri2->baseUrl());
    }

    #[Test]
    public function baseUrlWithNoPort(): void
    {
        $uri = new Uri('https://example.com/test');
        self::assertSame('https://example.com', $uri->baseUrl());
    }

    #[Test]
    public function fullReturnsOriginalUrl(): void
    {
        $url = 'https://example.com:8443/users?page=2#top';
        $uri = new Uri($url);

        self::assertSame($url, $uri->full());
        self::assertSame($url, (string) $uri);
    }

    #[Test]
    public function normalizesSchemeAndHostToLowercase(): void
    {
        $uri = new Uri('HTTPS://EXAMPLE.COM/Test');

        self::assertSame('https', $uri->scheme());
        self::assertSame('example.com', $uri->host());
        self::assertSame('/Test', $uri->path()); // path is case-sensitive
    }

    #[Test]
    public function handlesRootPath(): void
    {
        $uri = new Uri('http://example.com');

        self::assertSame('/', $uri->path());
    }

    #[Test]
    public function handlesQueryStringOnlyUrl(): void
    {
        $uri = new Uri('http://example.com/?foo=bar');

        self::assertSame('/', $uri->path());
        self::assertSame('foo=bar', $uri->queryString());
    }

    #[Test]
    public function handlesUrlWithUsernamePassword(): void
    {
        // parse_url handles user:pass, but Uri doesn't expose them (intentional)
        $uri = new Uri('http://user:pass@example.com/path');

        self::assertSame('example.com', $uri->host());
        self::assertSame('/path', $uri->path());
    }

    // ── a URL parse_url() refuses outright ───────────────────────────────────

    /**
     * THE TRAP. parse_url() returns false for an authority it cannot read, and
     * the constructor then fell through to "http" and "localhost" for EVERY
     * component at once: scheme, host, port, path, query and fragment were all
     * replaced by defaults, silently.
     *
     * A Host header of "app.example.com:evil" is enough to trigger it, and
     * Request::fromGlobals composes exactly that shape (scheme . "://" . host .
     * target). It is the root cause of two separate findings: HSTS was dropped
     * from a genuinely HTTPS response because the collapsed URI said "http",
     * and ForceHttpsMiddleware redirected an already-HTTPS request to HTTPS,
     * which with a persistent Host is a redirect loop.
     *
     * The constructor now refuses to INVENT an authority. It preserves what it
     * was actually given.
     */
    #[Test]
    public function malformedAuthorityKeepsTheSchemeThatWasActuallyReported(): void
    {
        $uri = new Uri('https://app.example.com:evil/dashboard');

        self::assertSame('https', $uri->scheme());
        self::assertTrue($uri->isSecure());
    }

    #[Test]
    public function malformedAuthorityKeepsTheHostAsSentInsteadOfInventingLocalhost(): void
    {
        $uri = new Uri('https://app.example.com:evil/dashboard');

        self::assertSame('app.example.com:evil', $uri->host());
    }

    /**
     * An unreadable ":port" suffix stays visible as part of the host rather than
     * being trimmed off. Trimming it would report a host that was never sent,
     * which is the same class of invention as "localhost".
     */
    #[Test]
    public function malformedAuthorityLeavesThePortNullRatherThanGuessingOne(): void
    {
        self::assertNull((new Uri('https://app.example.com:evil/dashboard'))->port());
    }

    #[Test]
    public function malformedAuthorityKeepsThePathQueryAndFragment(): void
    {
        $uri = new Uri('https://app.example.com:evil/dashboard?tab=2&sort=name#totals');

        self::assertSame('/dashboard', $uri->path());
        self::assertSame('tab=2&sort=name', $uri->queryString());
        self::assertSame('totals', $uri->fragment());
    }

    #[Test]
    public function malformedAuthorityStillDropsUserinfoBecauseItIsACredential(): void
    {
        $uri = new Uri('https://user:pass@app.example.com:evil/path');

        self::assertSame('app.example.com:evil', $uri->host());
        self::assertSame('/path', $uri->path());
    }

    #[Test]
    public function malformedAuthorityIsStillLowercasedLikeAReadableOne(): void
    {
        self::assertSame('app.example.com:evil', (new Uri('HTTPS://APP.EXAMPLE.COM:EVIL/x'))->host());
        self::assertSame('https', (new Uri('HTTPS://APP.EXAMPLE.COM:EVIL/x'))->scheme());
    }

    #[Test]
    public function malformedAuthorityKeepsTheOriginalStringForFull(): void
    {
        $url = 'https://app.example.com:evil/dashboard';

        self::assertSame($url, (new Uri($url))->full());
    }

    /**
     * baseUrl() reflects the malformed host instead of a clean invented one. The
     * value is unusable to an attacker (a browser rejects a non-numeric port),
     * and it is honest, which is what a security check downstream needs in order
     * to make the right call.
     */
    #[Test]
    public function malformedAuthorityProducesAnHonestBaseUrl(): void
    {
        self::assertSame(
            'https://app.example.com:evil',
            (new Uri('https://app.example.com:evil/dashboard'))->baseUrl(),
        );
    }

    /**
     * The boundary of the ruling. "localhost" is still the default when the URL
     * genuinely carries NO authority, which is the ordinary origin-form case and
     * the shape Request::fromArray() builds all over this suite. What was wrong
     * was discarding an authority that WAS reported, not defaulting one that was
     * never there.
     */
    #[Test]
    public function anAbsentAuthorityStillDefaultsRatherThanBeingLeftEmpty(): void
    {
        self::assertSame('localhost', (new Uri('/just-a-path'))->host());
        self::assertSame('localhost', (new Uri('http://'))->host());
        self::assertSame('http', (new Uri('http://'))->scheme());
    }
}
