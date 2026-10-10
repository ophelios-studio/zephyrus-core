<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use InvalidArgumentException;
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
     * @return iterable<string, array{string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'overflowing ipv4 prefix' => ['10.0.0.0/' . str_repeat('9', 309)];
        yield 'overflowing ipv6 prefix' => ['2001:db8::/' . str_repeat('9', 309)];
        yield 'letter O for zero' => ['10.0.0.0/O8'];
        yield 'alphabetic prefix' => ['10.0.0.0/abc'];
        yield 'nul byte after prefix' => ["10.0.0.0/8\0"];
        yield 'prefix above ipv4 width' => ['10.0.0.0/33'];
        yield 'empty entry' => [''];
        yield 'hostname' => ['example.com'];
        yield 'comma separated pair' => ['10.0.0.1,10.0.0.2'];
    }

    #[DataProvider('invalidEntries')]
    public function testConstructorRefusesEntriesThatAreNotValidRanges(string $entry): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Allowed IP');

        new IpAllowlistGuard(['127.0.0.1', $entry]);
    }

    public function testConstructorMessageNamesTheRefusedEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Allowed IP "10.0.0.0/33":');

        new IpAllowlistGuard(['10.0.0.0/33']);
    }

    public function testConstructorMessageBoundsAHugeRefusedEntry(): void
    {
        $entry = str_repeat('x', 5 * 1024 * 1024);

        try {
            new IpAllowlistGuard([$entry]);
            self::fail('A hostname-sized entry must be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertLessThan(300, strlen($e->getMessage()));
            self::assertStringContainsString('(5242880 bytes)', $e->getMessage());
        }
    }

    public function testConstructorMessageEscapesControlCharactersInTheEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Allowed IP "10.0.0.1\\n10.0.0.2":');

        new IpAllowlistGuard(["10.0.0.1\n10.0.0.2"]);
    }

    public function testConstructorRefusesShortIpv4MappedRangeWithTheIpv4Form(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an IPv4-mapped IPv6 range shorter than /96');

        new IpAllowlistGuard(['::ffff:10.0.0.0/8']);
    }

    public function testEmptyAllowlistAcceptsNoClient(): void
    {
        $guard = new IpAllowlistGuard([]);
        $request = new Request(method: 'GET', uri: '/secure', clientIp: '127.0.0.1');

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
