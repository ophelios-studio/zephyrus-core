<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\HeaderBag;

final class HeaderBagTest extends TestCase
{
    #[Test]
    public function getIsCaseInsensitive(): void
    {
        $bag = new HeaderBag(['content-type' => 'application/json']);

        self::assertSame('application/json', $bag->get('content-type'));
        self::assertSame('application/json', $bag->get('Content-Type'));
        self::assertSame('application/json', $bag->get('CONTENT-TYPE'));
    }

    #[Test]
    public function getReturnsDefaultForMissingHeader(): void
    {
        $bag = new HeaderBag([]);

        self::assertNull($bag->get('x-missing'));
        self::assertSame('fallback', $bag->get('x-missing', 'fallback'));
    }

    #[Test]
    public function hasIsCaseInsensitive(): void
    {
        $bag = new HeaderBag(['x-custom' => 'value']);

        self::assertTrue($bag->has('x-custom'));
        self::assertTrue($bag->has('X-Custom'));
        self::assertFalse($bag->has('x-other'));
    }

    #[Test]
    public function allReturnsAllHeaders(): void
    {
        $headers = ['content-type' => 'text/html', 'accept' => '*/*'];
        $bag = new HeaderBag($headers);

        self::assertSame($headers, $bag->all());
    }

    #[Test]
    public function bearerTokenExtractsToken(): void
    {
        $bag = new HeaderBag(['authorization' => 'Bearer secret-token']);

        self::assertSame('secret-token', $bag->bearerToken());
    }

    #[Test]
    public function bearerTokenReturnsNullWhenMissing(): void
    {
        $bag = new HeaderBag([]);

        self::assertNull($bag->bearerToken());
    }

    #[Test]
    public function bearerTokenReturnsNullForEmptyHeader(): void
    {
        $bag = new HeaderBag(['authorization' => '']);

        self::assertNull($bag->bearerToken());
    }

    #[Test]
    public function bearerTokenReturnsRawWhenNoBearerPrefix(): void
    {
        $bag = new HeaderBag(['authorization' => 'raw-token']);

        self::assertSame('raw-token', $bag->bearerToken());
    }

    #[Test]
    public function bearerTokenWithCustomHeaderAndPrefix(): void
    {
        $bag = new HeaderBag(['x-token' => 'Token abc123']);

        self::assertSame('abc123', $bag->bearerToken('X-Token', 'Token '));
    }

    #[Test]
    public function bearerTokenReturnsNullForBearerPrefixOnly(): void
    {
        $bag = new HeaderBag(['authorization' => 'Bearer ']);

        self::assertNull($bag->bearerToken());
    }

    #[Test]
    public function bearerTokenWithEmptyPrefix(): void
    {
        $bag = new HeaderBag(['authorization' => 'any-value']);

        self::assertSame('any-value', $bag->bearerToken('Authorization', ''));
    }

    #[Test]
    public function contentTypeReturnsValue(): void
    {
        $bag = new HeaderBag(['content-type' => 'application/json; charset=utf-8']);

        self::assertSame('application/json; charset=utf-8', $bag->contentType());
    }

    #[Test]
    public function contentTypeReturnsNullWhenMissing(): void
    {
        $bag = new HeaderBag([]);

        self::assertNull($bag->contentType());
    }

    #[Test]
    public function isJsonDetectsApplicationJson(): void
    {
        self::assertTrue((new HeaderBag(['content-type' => 'application/json']))->isJson());
        self::assertTrue((new HeaderBag(['content-type' => 'application/json; charset=utf-8']))->isJson());
        self::assertTrue((new HeaderBag(['content-type' => 'Application/JSON']))->isJson());
    }

    #[Test]
    public function isJsonDetectsJsonSuffix(): void
    {
        self::assertTrue((new HeaderBag(['content-type' => 'application/vnd.api+json']))->isJson());
    }

    #[Test]
    public function isJsonReturnsFalseForNonJson(): void
    {
        self::assertFalse((new HeaderBag(['content-type' => 'text/html']))->isJson());
        self::assertFalse((new HeaderBag([]))->isJson());
        self::assertFalse((new HeaderBag(['content-type' => '']))->isJson());
    }
}
