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
}
