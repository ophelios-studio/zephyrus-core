<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\AllowedHostsMiddleware;

final class AllowedHostsMiddlewareTest extends TestCase
{
    /** An exact name, a wildcard and an IPv6 literal, so every entry form is exercised. */
    private const ALLOWLIST = ['example.com', '*.example.com', '2001:db8::1'];

    public function testEmptyAllowlistPassesThrough(): void
    {
        $mw = new AllowedHostsMiddleware([]);
        $request = new Request('GET', 'https://example.com/ping');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testExactHostMatchPassesIncludingPortInHeader(): void
    {
        $mw = new AllowedHostsMiddleware(['example.com']);
        $request = new Request('GET', 'https://example.com/ping', headers: [
            'host' => 'example.com:443',
        ]);

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testHostCanBeResolvedFromUriWhenHostHeaderMissing(): void
    {
        $mw = new AllowedHostsMiddleware(['api.example.com']);
        $request = new Request('GET', 'https://api.example.com/v1');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testHostCanBeResolvedFromIpv6UriWhenHostHeaderMissing(): void
    {
        $mw = new AllowedHostsMiddleware(['::1']);
        $request = new Request('GET', 'http://[::1]/v1');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testIpv6UriWithPortIsNormalizedAndAllowed(): void
    {
        $mw = new AllowedHostsMiddleware(['2001:db8::1']);
        $request = new Request('GET', 'https://[2001:db8::1]:443/secure');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    /**
     * The middleware judges the URI, not the raw Host header: a trusted
     * X-Forwarded-Host decides the URI, and every consumer of the request
     * (links, redirects, cookie domains, baseUrl()) reads the URI too. An allowed
     * Host header over a URI pointing elsewhere must therefore be refused.
     */
    public function testAnAllowedHostHeaderCannotAdmitARequestWhoseUriPointsElsewhere(): void
    {
        $called = false;
        $mw = new AllowedHostsMiddleware(['app.agreely.ca']);
        $request = new Request('GET', 'https://evil.attacker.test/path', headers: [
            'host' => 'app.agreely.ca',
        ]);

        $response = $mw->process($request, static function (Request $r) use (&$called): Response {
            $called = true;

            return Response::text('unreachable');
        });

        self::assertFalse($called);
        self::assertSame(400, $response->status);
        self::assertSame('evil.attacker.test', $request->uri()->host());
    }

    /**
     * The mirror image: the URI names an allowed host, so the request is served
     * whatever the raw Host header says. A trusted proxy that rewrites Host to an
     * internal name is a common topology, and refusing on disagreement would
     * break it.
     */
    public function testAnAllowedUriIsServedEvenWhenTheRawHostHeaderDisagrees(): void
    {
        $mw = new AllowedHostsMiddleware(['app.agreely.ca']);
        $request = new Request('GET', 'https://app.agreely.ca/dashboard', headers: [
            'host' => 'internal-backend.flycast',
        ]);

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testRejectsUnknownHostAndSkipsInnerHandler(): void
    {
        $called = false;
        $mw = new AllowedHostsMiddleware(['example.com']);
        $request = new Request('GET', 'https://evil.example.net/path', headers: [
            'host' => 'evil.example.net',
        ]);

        $response = $mw->process($request, static function (Request $r) use (&$called): Response {
            $called = true;

            return Response::text('unreachable');
        });

        self::assertFalse($called);
        self::assertSame(400, $response->status);
        self::assertStringContainsString('Invalid Host header.', $response->body);
    }

    public function testWildcardAllowsSubdomainsButNotApexDomain(): void
    {
        $mw = new AllowedHostsMiddleware(['*.example.com']);

        $subdomainRequest = new Request('GET', 'https://api.example.com/items');
        $subdomainResponse = $mw->process($subdomainRequest, static fn (Request $r): Response => Response::text('ok'));
        self::assertSame(200, $subdomainResponse->status);

        $apexRequest = new Request('GET', 'https://example.com/items');
        $apexResponse = $mw->process($apexRequest, static fn (Request $r): Response => Response::text('nope'));
        self::assertSame(400, $apexResponse->status);
    }

    public function testHostNormalizationHandlesCaseAndTrailingDot(): void
    {
        $mw = new AllowedHostsMiddleware(['Example.COM']);
        $request = new Request('GET', 'https://example.com/home', headers: [
            'host' => 'EXAMPLE.com.',
        ]);

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    /**
     * Raw Host values with the expected verdict. Each one is judged by allows()
     * directly, and by process() on a request whose URL carries that value, so
     * the two must agree on every row.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function rawHostCases(): iterable
    {
        yield 'exact name' => ['example.com', true];
        yield 'upper case' => ['EXAMPLE.com', true];
        yield 'trailing dot' => ['example.com.', true];
        yield 'port 443' => ['example.com:443', true];
        yield 'port 8443' => ['example.com:8443', true];
        yield 'port 80' => ['example.com:80', true];
        yield 'trailing dot before a port' => ['example.com.:443', true];
        yield 'empty port' => ['example.com:', false];
        yield 'port above five digits' => ['example.com:123456', false];
        yield 'signed port' => ['example.com:+80', false];
        yield 'padded with CRLF' => ["  example.com\r\n", false];
        yield 'CRLF after the name' => ["example.com\r\n", false];
        yield 'tab before the name' => ["\texample.com", false];
        yield 'bracketed name' => ['[example.com]', false];
        yield 'bracketed name with a path' => ['[evil.com/.example.com]', false];
        yield 'backslash at the end' => ['example.com\\', false];
        yield 'space before the port' => ['example.com: 80', false];
        yield 'hex port' => ['example.com:0x50', false];
        yield 'negative port' => ['example.com:-1', false];
        yield 'non-numeric port' => ['example.com:evil', false];
        yield 'two colons without brackets' => ['example.com:80:80', false];
        yield 'leading space' => [' example.com', false];
        yield 'trailing space' => ['example.com ', false];
        yield 'other name' => ['evil.com', false];
        yield 'suffix look-alike' => ['example.com.evil.com', false];
        yield 'wildcard subdomain' => ['api.example.com', true];
        yield 'wildcard subdomain with port' => ['api.example.com:8443', true];
        yield 'wildcard subdomain with non-numeric port' => ['api.example.com:evil', false];
        yield 'nested wildcard subdomain' => ['a.b.example.com', true];
        yield 'underscore in a wildcard label, as a Docker service name' => ['my_app.example.com', true];
        yield 'underscore in an unrelated name' => ['my_app.evil.com', false];
        yield 'underscore only as a label' => ['_.example.com', true];
        yield 'internationalised label as punycode' => ['xn--bcher-kva.example.com', true];
        yield 'internationalised label as unicode' => ['café.example.com', false];
        yield 'slash then name' => ['evil.com/.example.com', false];
        yield 'hash then name' => ['evil.com#.example.com', false];
        yield 'question mark then name' => ['evil.com?.example.com', false];
        yield 'slash then path, allowed name' => ['example.com/x', true];
        yield 'question mark then query, allowed name' => ['example.com?x', true];
        yield 'hash then fragment, allowed name' => ['example.com#x', true];
        yield 'port then path, allowed name' => ['example.com:443/x', true];
        yield 'wildcard name then path' => ['api.example.com/x', true];
        yield 'slash then userinfo after the host' => ['example.com/@evil.com', true];
        yield 'other name then path' => ['evil.com/x', false];
        yield 'userinfo before an allowed name' => ['evil.com@example.com/x', false];
        yield 'backslash then name' => ['evil.com\\.example.com', false];
        yield 'tab then name' => ["evil.com\t.example.com", false];
        yield 'NUL then name' => ["evil.com\0.example.com", false];
        yield 'space then name' => ['evil.com .example.com', false];
        yield 'userinfo' => ['evil.com@example.com', false];
        yield 'label starting with a hyphen' => ['-a.example.com', false];
        yield 'label ending with a hyphen' => ['a-.example.com', false];
        yield 'empty label' => ['a..example.com', false];
        yield 'label of 64 characters' => [str_repeat('a', 64) . '.example.com', false];
        yield 'name over 253 characters' => [str_repeat('a', 63) . '.' . str_repeat('b', 63) . '.'
            . str_repeat('c', 63) . '.' . str_repeat('d', 63) . '.example.com', false];
        yield 'wildcard character as a host' => ['*.example.com', false];
        yield 'bracketed IPv6 literal' => ['[2001:db8::1]', true];
        yield 'bracketed IPv6 literal with port' => ['[2001:db8::1]:443', true];
        yield 'bracketed IPv6 literal with non-numeric port' => ['[2001:db8::1]:evil', false];
        yield 'bracketed IPv6 literal with trailing text' => ['[2001:db8::1]x', false];
        yield 'bracketed IPv6 literal without closing bracket' => ['[2001:db8::1', false];
        yield 'unbracketed IPv6 literal' => ['2001:db8::1', false];
        yield 'unbracketed loopback' => ['::1', false];
        yield 'bracketed IPv4 literal' => ['[192.0.2.1]', false];
        yield 'IPv4 literal not on the list' => ['192.0.2.1', false];
    }

    #[DataProvider('rawHostCases')]
    public function testAllowsDecidesEachRawHost(string $host, bool $expected): void
    {
        $mw = new AllowedHostsMiddleware(self::ALLOWLIST);

        self::assertSame($expected, $mw->allows($host));
    }

    #[DataProvider('rawHostCases')]
    public function testProcessDecidesEachRawHostTheSameWayAsAllows(string $host, bool $expected): void
    {
        $mw = new AllowedHostsMiddleware(self::ALLOWLIST);
        $request = new Request('GET', 'https://' . $host . '/ping');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame($expected, $response->status === 200);
        self::assertSame($mw->allows($host), $response->status === 200);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEntries(): iterable
    {
        yield 'empty' => [''];
        yield 'bare star' => ['*'];
        yield 'bare wildcard' => ['*.'];
        yield 'path' => ['evil.com/x'];
        yield 'wildcard with a path' => ['*.evil.com/x'];
        yield 'wildcard with a query' => ['*.example.com?x'];
        yield 'unclosed IPv6 literal' => ['[::1'];
        yield 'leading space' => [' example.com'];
        yield 'non-numeric port' => ['example.com:evil'];
        yield 'wildcard over an IPv4 literal' => ['*.203.0.113.7'];
    }

    #[DataProvider('invalidEntries')]
    public function testAllowlistEntryThatIsNotAHostNameIsRefusedAtConstruction(string $entry): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AllowedHostsMiddleware([$entry]);
    }

    public function testConstructorRefusalCarriesTheReasonFromTheSharedPredicate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('use an empty list to allow every host');

        new AllowedHostsMiddleware(['*']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function entriesWithADedicatedReason(): iterable
    {
        yield 'comma-separated list' => ['a.example.com,b.example.com', 'use list items, not a comma inside one item'];
        yield 'leading space' => [' example.com', 'remove the spaces'];
        yield 'trailing space' => ['example.com ', 'remove the spaces'];
        yield 'host with port' => ['example.com:8080', 'ports are not matched: list "example.com" only'];
        yield 'wildcard with port' => ['*.example.com:443', 'ports are not matched: list "*.example.com" only'];
        yield 'bracketed IPv6 with port' => ['[2001:db8::1]:8080', 'ports are not matched: list "[2001:db8::1]" only'];
    }

    #[DataProvider('entriesWithADedicatedReason')]
    public function testInvalidEntryReasonNamesTheFixForCommaPaddingAndPorts(string $entry, string $reason): void
    {
        self::assertStringContainsString($reason, (string) AllowedHostsMiddleware::invalidEntryReason($entry));
    }

    #[DataProvider('entriesWithADedicatedReason')]
    public function testConstructorRefusesEntriesWithADedicatedReason(string $entry, string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);

        new AllowedHostsMiddleware([$entry]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function portEntriesWithAnInvalidHost(): iterable
    {
        yield 'empty host' => [':8080'];
        yield 'host with a trailing space' => ['example.com :80'];
        yield 'host with an inner space' => ['a b:80'];
        yield 'empty bracketed host' => ['[]:80'];
    }

    #[DataProvider('portEntriesWithAnInvalidHost')]
    public function testPortEntryWithAnInvalidHostDoesNotNameThatHostInItsReason(string $entry): void
    {
        self::assertSame(
            'must be a host name, an IP literal, or a wildcard over a host name such as *.example.com',
            AllowedHostsMiddleware::invalidEntryReason($entry),
        );
    }

    public function testBareIpv6LiteralIsNotMistakenForAHostWithPort(): void
    {
        self::assertNull(AllowedHostsMiddleware::invalidEntryReason('2001:db8::1'));
        self::assertNull(AllowedHostsMiddleware::invalidEntryReason('[2001:db8::1]'));
    }

    public function testAllowsRefusesAnEmptyHostWhileTheAllowlistIsSet(): void
    {
        $mw = new AllowedHostsMiddleware(['app.example.ca']);

        self::assertFalse($mw->allows(''));
    }

    public function testAllowsAcceptsAnyHostWhileTheAllowlistIsEmpty(): void
    {
        $mw = new AllowedHostsMiddleware([]);

        self::assertTrue($mw->allows('anything.example.test'));
    }

    /**
     * An origin-form target carries no host of its own. A URL inside its query
     * must not be read as the request's authority.
     *
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function originFormCases(): iterable
    {
        yield 'URL in the query, named host not allowed' => ['/r?to=https://app.example.com/x', ['app.example.com'], false];
        yield 'URL in the query names an allowed host' => ['/r?to=https://evil.example/x', ['evil.example'], false];
        yield 'bare origin-form target, host is localhost' => ['/r', ['app.example.com'], false];
        yield 'origin-form target, localhost allowed' => ['/r?to=https://app.example.com/x', ['localhost'], true];
    }

    /** @param list<string> $entries */
    #[DataProvider('originFormCases')]
    public function testOriginFormTargetIsJudgedByItsHostNotByAUrlInItsQuery(string $target, array $entries, bool $expected): void
    {
        $mw = new AllowedHostsMiddleware($entries);
        $request = new Request('GET', $target);

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('ok'));

        self::assertSame($expected, $response->status === 200);
        self::assertSame($mw->allows($request->uri()->host()), $response->status === 200);
    }

    public function testHostWithNonNumericPortIsRejectedByProcess(): void
    {
        $mw = new AllowedHostsMiddleware(['app.example.ca']);
        $request = new Request('GET', 'https://app.example.ca:evil/ping');

        $response = $mw->process($request, static fn (Request $r): Response => Response::text('reached'));

        self::assertSame(400, $response->status);
        self::assertStringNotContainsString('reached', $response->body);
    }
}
