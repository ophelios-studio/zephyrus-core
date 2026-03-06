<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Security\IpAllowlistGuard;

final class IpAllowlistGuardTest extends TestCase
{
    public function testAuthorizesWhenClientIpAttributeIsAllowed(): void
    {
        $guard = new IpAllowlistGuard(['127.0.0.1']);
        $request = Request::fromArray('GET', '/secure', attributes: ['client_ip' => '127.0.0.1']);

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testAuthorizesFromForwardedHeaderFirstIp(): void
    {
        $guard = new IpAllowlistGuard(['10.0.0.2']);
        $request = Request::fromArray('GET', '/secure', headers: ['X-Forwarded-For' => '10.0.0.2, 10.0.0.3']);

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testRejectsWhenIpIsNotAllowlisted(): void
    {
        $guard = new IpAllowlistGuard(['127.0.0.1']);
        $request = Request::fromArray('GET', '/secure', attributes: ['client_ip' => '8.8.8.8']);

        self::assertFalse($guard->isAuthorized($request));
    }

    public function testAuthorizesWhenIpMatchesIpv4Cidr(): void
    {
        $guard = new IpAllowlistGuard(['10.42.0.0/16']);
        $request = Request::fromArray('GET', '/secure', attributes: ['client_ip' => '10.42.9.12']);

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testAuthorizesWhenIpMatchesIpv6Cidr(): void
    {
        $guard = new IpAllowlistGuard(['2001:db8::/32']);
        $request = Request::fromArray('GET', '/secure', attributes: ['client_ip' => '2001:db8:abcd::42']);

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testRejectsWhenIpDoesNotMatchCidr(): void
    {
        $guard = new IpAllowlistGuard(['10.42.0.0/16']);
        $request = Request::fromArray('GET', '/secure', attributes: ['client_ip' => '10.43.1.2']);

        self::assertFalse($guard->isAuthorized($request));
    }

    public function testRejectsWhenNoIpSignalExists(): void
    {
        $guard = new IpAllowlistGuard(['127.0.0.1']);
        $request = Request::fromArray('GET', '/secure');

        self::assertFalse($guard->isAuthorized($request));
    }
}
