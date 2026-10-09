<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAuthorizesFromClientIpProperty(): void
    {
        $guard = new IpAllowlistGuard(['10.0.0.2']);
        $request = new Request(method: 'GET', uri: '/secure', clientIp: '10.0.0.2');

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

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function entriesThatMustNotAuthorize(): iterable
    {
        yield 'overflowing ipv4 prefix' => ['10.0.0.0/' . str_repeat('9', 309), '203.0.113.9'];
        yield 'overflowing ipv6 prefix' => ['2001:db8::/' . str_repeat('9', 309), '2001:db8::1'];
        yield 'letter O for zero' => ['10.0.0.0/O8', '203.0.113.9'];
        yield 'alphabetic prefix' => ['10.0.0.0/abc', '10.0.0.1'];
        yield 'nul byte after prefix' => ["10.0.0.0/8\0", '10.0.0.1'];
        yield 'prefix above ipv4 width' => ['10.0.0.0/33', '10.0.0.1'];
        yield 'ipv6 range with ipv4 client' => ['2001:db8::/32', '10.0.0.1'];
        yield 'empty entry' => ['', '10.0.0.1'];
    }

    #[DataProvider('entriesThatMustNotAuthorize')]
    public function testRejectsEntriesThatAreNotValidRanges(string $entry, string $ip): void
    {
        $guard = new IpAllowlistGuard([$entry]);
        $request = new Request(method: 'GET', uri: '/secure', clientIp: $ip);

        self::assertFalse($guard->isAuthorized($request));
    }

    public function testAuthorizesIpv6ExactEntryRegardlessOfSpelling(): void
    {
        $guard = new IpAllowlistGuard(['2001:DB8::1']);
        $request = new Request(method: 'GET', uri: '/secure', clientIp: '2001:db8::1');

        self::assertTrue($guard->isAuthorized($request));
    }

    public function testRejectsWhenNoIpSignalExists(): void
    {
        $guard = new IpAllowlistGuard(['127.0.0.1']);
        $request = Request::fromArray('GET', '/secure');

        self::assertFalse($guard->isAuthorized($request));
    }
}
