<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\CookieJar;

final class CookieJarTest extends TestCase
{
    #[Test]
    public function getReturnsCookieValue(): void
    {
        $jar = new CookieJar(['session' => 'abc123', 'theme' => 'dark']);

        self::assertSame('abc123', $jar->get('session'));
        self::assertSame('dark', $jar->get('theme'));
    }

    #[Test]
    public function getReturnsDefaultForMissing(): void
    {
        $jar = new CookieJar([]);

        self::assertNull($jar->get('missing'));
        self::assertSame('fallback', $jar->get('missing', 'fallback'));
    }

    #[Test]
    public function hasReturnsTrueForExisting(): void
    {
        $jar = new CookieJar(['token' => 'xyz']);

        self::assertTrue($jar->has('token'));
        self::assertFalse($jar->has('nope'));
    }

    #[Test]
    public function allReturnsAllCookies(): void
    {
        $cookies = ['a' => '1', 'b' => '2'];
        $jar = new CookieJar($cookies);

        self::assertSame($cookies, $jar->all());
    }

    #[Test]
    public function emptyJar(): void
    {
        $jar = new CookieJar();

        self::assertSame([], $jar->all());
        self::assertFalse($jar->has('anything'));
        self::assertNull($jar->get('anything'));
    }
}
