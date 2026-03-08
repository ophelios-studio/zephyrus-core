<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Security\HeaderTokenGuard;

final class HeaderTokenGuardTest extends TestCase
{
    public function testAuthorizesWithBearerToken(): void
    {
        $guard = new HeaderTokenGuard('secret-token');
        $request = Request::fromArray('GET', '/admin', headers: ['Authorization' => 'Bearer secret-token']);

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testRejectsWhenBearerTokenDoesNotMatch(): void
    {
        $guard = new HeaderTokenGuard('secret-token');
        $request = Request::fromArray('GET', '/admin', headers: ['Authorization' => 'Bearer wrong']);

        self::assertFalse($guard->isAuthorized($request));
    }

    public function testAuthorizesWithLowercaseBearerScheme(): void
    {
        $guard = new HeaderTokenGuard('secret-token');
        $request = Request::fromArray('GET', '/admin', headers: ['Authorization' => 'bearer secret-token']);

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testRejectsWhenHeaderMissing(): void
    {
        $guard = new HeaderTokenGuard('secret-token');
        $request = Request::fromArray('GET', '/admin');

        self::assertFalse($guard->isAuthorized($request));
    }

    public function testSupportsCustomHeaderWithoutBearerPrefix(): void
    {
        $guard = new HeaderTokenGuard('abc123', headerName: 'X-Api-Key', bearerPrefix: '');
        $request = Request::fromArray('GET', '/admin', headers: ['X-Api-Key' => 'abc123']);

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testAcceptsRawTokenEvenWhenBearerPrefixConfigured(): void
    {
        $guard = new HeaderTokenGuard('abc123');
        $request = Request::fromArray('GET', '/admin', headers: ['Authorization' => 'abc123']);

        self::assertTrue($guard->isAuthorized($request));
    }
}
