<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteMatch;
use Zephyrus\Upload\FileUpload;

final class RequestTest extends TestCase
{
    // -------------------------------------------------------------------------
    // fromArray — existing contract tests
    // -------------------------------------------------------------------------

    public function testFactoryNormalizesMethodAndHeaders(): void
    {
        $request = Request::fromArray(
            method: 'post',
            uri: '/users',
            headers: ['Content-Type' => 'application/json'],
        );

        self::assertSame('POST', $request->method);
        self::assertSame('application/json', $request->headers()->get('content-type'));
    }

    public function testQueryAndInputHelpersReturnDefaultWhenMissing(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/search',
            query: ['q' => 'zephyrus'],
            body: ['name' => 'molt'],
        );

        self::assertSame('zephyrus', $request->query('q'));
        self::assertSame('fallback', $request->query('missing', 'fallback'));

        self::assertSame('molt', $request->body()->get('name'));
        self::assertNull($request->body()->get('missing'));
    }

    public function testPathAndMethodHelpers(): void
    {
        $request = Request::fromArray(
            method: 'post',
            uri: '/users/42?expand=roles',
        );

        self::assertSame('/users/42', $request->uri()->path());
        self::assertTrue($request->isMethod('POST'));
        self::assertFalse($request->isMethod('GET'));
    }

    public function testAttributeHelpersAreImmutable(): void
    {
        $request = Request::fromArray('GET', '/users', attributes: ['tenant' => 'acme']);
        $updated = $request->withAttribute('userId', '42');

        self::assertSame('acme', $request->attribute('tenant'));
        self::assertNull($request->attribute('userId'));

        self::assertSame('acme', $updated->attribute('tenant'));
        self::assertSame('42', $updated->attribute('userId'));
    }

    public function testFromArrayCookiesAreAccessible(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/dashboard',
            cookies: ['session' => 'abc123', 'theme' => 'dark'],
        );

        self::assertSame('abc123', $request->cookies()->get('session'));
        self::assertSame('dark', $request->cookies()->get('theme'));
        self::assertNull($request->cookies()->get('missing'));
        self::assertSame('fallback', $request->cookies()->get('missing', 'fallback'));
    }

    public function testWithAttributesPreservesCookies(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/page',
            cookies: ['token' => 'xyz'],
        );

        $updated = $request->withAttributes(['role' => 'admin']);

        self::assertSame('xyz', $updated->cookies()->get('token'));
        self::assertSame('admin', $updated->attribute('role'));
    }

    public function testWithAttributePreservesCookies(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/page',
            cookies: ['lang' => 'fr'],
        );

        $updated = $request->withAttribute('userId', 7);

        self::assertSame('fr', $updated->cookies()->get('lang'));
        self::assertSame(7, $updated->attribute('userId'));
    }

    public function testBearerTokenExtractsDefaultAuthorizationPrefixCaseInsensitively(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/admin',
            headers: ['Authorization' => 'bearer secret-token'],
        );

        self::assertSame('secret-token', $request->headers()->bearerToken());
    }

    public function testBearerTokenReturnsRawHeaderWhenPrefixDoesNotMatch(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/admin',
            headers: ['Authorization' => 'raw-token'],
        );

        self::assertSame('raw-token', $request->headers()->bearerToken());
    }

    public function testBearerTokenReturnsNullWhenHeaderIsMissingOrEmpty(): void
    {
        $missing = Request::fromArray(method: 'GET', uri: '/admin');
        $empty = Request::fromArray(method: 'GET', uri: '/admin', headers: ['Authorization' => '   ']);

        self::assertNull($missing->headers()->bearerToken());
        self::assertNull($empty->headers()->bearerToken());
    }

    public function testFromArrayProvidesFileUploadHelper(): void
    {
        $file = new FileUpload('me.png', 'image/png', '/tmp/phpA', 123);
        $request = Request::fromArray('POST', '/profile', files: ['avatar' => $file]);

        self::assertSame($file, $request->file('avatar'));
        self::assertNull($request->file('missing'));
    }

    // -------------------------------------------------------------------------
    // isJson + isSecure helpers
    // -------------------------------------------------------------------------

    public function testIsJsonReturnsTrueForJsonContentType(): void
    {
        $request = Request::fromArray(
            method: 'POST',
            uri: '/api/items',
            headers: ['Content-Type' => 'application/json; charset=utf-8'],
        );

        self::assertTrue($request->headers()->isJson());
    }

    public function testIsJsonReturnsFalseForFormContentType(): void
    {
        $request = Request::fromArray(
            method: 'POST',
            uri: '/form',
            headers: ['Content-Type' => 'application/x-www-form-urlencoded'],
        );

        self::assertFalse($request->headers()->isJson());
    }

    public function testIsJsonReturnsTrueForJsonSuffixMediaType(): void
    {
        $request = Request::fromArray(
            method: 'POST',
            uri: '/problem',
            headers: ['Content-Type' => 'application/problem+json'],
        );

        self::assertTrue($request->headers()->isJson());
    }

    public function testIsSecureDetectsHttpsUri(): void
    {
        $secure  = Request::fromArray('GET', 'https://example.com/page');
        $plain   = Request::fromArray('GET', 'http://example.com/page');

        self::assertTrue($secure->uri()->isSecure());
        self::assertFalse($plain->uri()->isSecure());
    }

    public function testFromGlobalsResolvesClientIpFromForwardedHeader(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=203.0.113.10:1234;proto=https;host=app.example.com',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('203.0.113.10', $request->clientIp());
        self::assertSame('203.0.113.10', $request->clientIp);
    }

    public function testFromGlobalsIgnoresForwardedHeaderWithoutTrustedProxy(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '198.51.100.5',
                'HTTP_FORWARDED' => 'for=203.0.113.10:1234;proto=https;host=app.example.com',
            ],
        );

        // Without trusted proxies, forwarded headers are ignored.
        self::assertSame('198.51.100.5', $request->clientIp());
    }

    public function testFromGlobalsClientIpFallsBackToRemoteAddress(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '192.0.2.9',
            ],
        );

        self::assertSame('192.0.2.9', $request->clientIp());
    }

    public function testClientIpFromConstructorProperty(): void
    {
        $request = new Request(
            method: 'GET',
            uri: '/secure',
            clientIp: '2001:db8::1',
        );

        self::assertSame('2001:db8::1', $request->clientIp());
    }

    public function testClientIpFromAttribute(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/secure',
            attributes: ['client_ip' => '10.0.0.2'],
        );

        self::assertSame('10.0.0.2', $request->clientIp());
    }

    public function testClientIpFallsBackToClientIpAttribute(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/secure',
            attributes: ['client_ip' => '8.8.8.8'],
        );

        self::assertSame('8.8.8.8', $request->clientIp());
    }

    // -------------------------------------------------------------------------
    // fromGlobals - client IP behind trusted proxies (right to left walk)
    // -------------------------------------------------------------------------

    public function testFromGlobalsIgnoresForgedLeftmostForwardedForEntry(): void
    {
        // The caller sent "X-Forwarded-For: 192.0.2.66" and the proxy appended
        // the peer it actually saw. The forged leftmost entry must not win.
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'        => 'GET',
                'HTTP_HOST'             => 'example.com',
                'REQUEST_URI'           => '/',
                'REMOTE_ADDR'           => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR'  => '192.0.2.66, 198.51.100.7',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('198.51.100.7', $request->clientIp());
    }

    public function testFromGlobalsWalksPastEveryChainedTrustedProxy(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 203.0.113.20, 10.0.0.3, 10.0.0.2',
            ],
            trustedProxies: ['10.0.0.1', '10.0.0.2', '10.0.0.3'],
        );

        self::assertSame('203.0.113.20', $request->clientIp());
    }

    public function testFromGlobalsConsultsNoHeaderWhenRemoteAddressIsNotTrusted(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '198.51.100.5',
                'HTTP_FORWARDED'       => 'for=203.0.113.10',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 192.0.2.67',
                'HTTP_X_REAL_IP'       => '192.0.2.68',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('198.51.100.5', $request->clientIp());
    }

    public function testFromGlobalsReturnsLeftmostWhenEveryHopIsTrusted(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.9, 10.0.0.2',
            ],
            trustedProxies: ['10.0.0.1', '10.0.0.2', '10.0.0.9'],
        );

        self::assertSame('10.0.0.9', $request->clientIp());
    }

    public function testFromGlobalsWildcardTrustedProxyReturnsLeftmostEntry(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 198.51.100.7',
            ],
            trustedProxies: ['*'],
        );

        self::assertSame('192.0.2.66', $request->clientIp());
    }

    public function testFromGlobalsReturnsRemoteAddressWhenTrustedPeerSendsNoForwardingHeader(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '10.0.0.1',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertNotNull($request->clientIp());
        self::assertSame('10.0.0.1', $request->clientIp());
    }

    public function testFromGlobalsWalksForwardedHeaderFromTheRight(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=192.0.2.66;proto=https, for=198.51.100.7;proto=https',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('198.51.100.7', $request->clientIp());
    }

    public function testFromGlobalsResolvesBracketedIpv6WithPortInForwardedHeader(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=unknown, for="[2001:db8::1]:1234"',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('2001:db8::1', $request->clientIp());
    }

    public function testFromGlobalsForwardedHeaderCarryingOnlyUnknownFallsBackToRemoteAddress(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=unknown',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('10.0.0.1', $request->clientIp());
    }

    public function testFromGlobalsPrefersForwardedHeaderOverForwardedForChain(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_FORWARDED'       => 'for=192.0.2.66, for=198.51.100.7',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.90, 203.0.113.91',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded', 'x-forwarded-for'],
        );

        self::assertSame('198.51.100.7', $request->clientIp());
    }

    public function testFromGlobalsWalksIpv6ForwardedForChain(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '2001:db8:ff::1',
                'HTTP_X_FORWARDED_FOR' => '2001:db8::66, 2001:db8::7',
            ],
            trustedProxies: ['2001:db8:ff::1'],
        );

        self::assertSame('2001:db8::7', $request->clientIp());
    }

    public function testFromGlobalsWalksMixedIpv4AndIpv6Chain(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 2001:db8::7, 10.0.0.2',
            ],
            trustedProxies: ['10.0.0.0/24'],
        );

        self::assertSame('2001:db8::7', $request->clientIp());
    }

    public function testFromGlobalsTrustsIpv4CidrRange(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '172.16.4.9',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 203.0.113.7, 172.16.9.4',
            ],
            trustedProxies: ['172.16.0.0/12'],
        );

        self::assertSame('203.0.113.7', $request->clientIp());
    }

    public function testFromGlobalsTrustsIpv6CidrRange(): void
    {
        // Mirrors the real deployment, whose internal network is fdaa::/8.
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => 'fdaa:0:2::5',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.66, 203.0.113.7, fdaa:0:2::9',
            ],
            trustedProxies: ['fdaa::/8'],
        );

        self::assertSame('203.0.113.7', $request->clientIp());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedCidrPrefixes(): iterable
    {
        yield 'alphabetic prefix' => ['10.0.0.0/abc', '10.0.0.1'];
        yield 'empty prefix' => ['10.0.0.0/', '10.0.0.1'];
        yield 'letter O for zero' => ['10.0.0.0/O8', '10.0.0.1'];
        yield 'trailing letter' => ['10.0.0.0/8x', '10.0.0.1'];
        yield 'space before prefix' => ['10.0.0.0/ 8', '10.0.0.1'];
        yield 'plus sign' => ['10.0.0.0/+8', '10.0.0.1'];
        yield 'exponent notation' => ['10.0.0.0/1e1', '10.0.0.1'];
        yield 'nul byte after prefix' => ["10.0.0.0/8\0", '10.0.0.1'];
        yield 'overflowing ipv4 prefix' => ['10.0.0.0/' . str_repeat('9', 309), '10.0.0.1'];
        yield 'overflowing ipv6 prefix' => ['2001:db8::/' . str_repeat('9', 309), '2001:db8::1'];
        yield 'letter O for zero ipv6' => ['2001:db8::/O8', '2001:db8::1'];
    }

    #[DataProvider('malformedCidrPrefixes')]
    public function testFromGlobalsIgnoresForwardedForWhenCidrPrefixIsMalformed(string $cidr, string $peer): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => $peer,
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
            ],
            trustedProxies: [$cidr],
        );

        self::assertSame($peer, $request->clientIp());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cidrBoundaries(): iterable
    {
        yield 'ipv4 /12 last address inside' => ['172.16.0.0/12', '172.31.255.254', '203.0.113.7'];
        yield 'ipv4 /12 first address outside' => ['172.16.0.0/12', '172.32.0.1', '172.32.0.1'];
        yield 'ipv4 /20 first address inside' => ['10.0.16.0/20', '10.0.16.1', '203.0.113.7'];
        yield 'ipv4 /20 last address inside' => ['10.0.16.0/20', '10.0.31.254', '203.0.113.7'];
        yield 'ipv4 /20 address below range' => ['10.0.16.0/20', '10.0.15.255', '10.0.15.255'];
        yield 'ipv4 /20 first address outside' => ['10.0.16.0/20', '10.0.32.1', '10.0.32.1'];
        yield 'ipv6 /12 last address inside' => ['2000::/12', '200f:ffff::1', '203.0.113.7'];
        yield 'ipv6 /12 first address outside' => ['2000::/12', '2010::1', '2010::1'];
        yield 'ipv6 /20 last address inside' => ['2001::/20', '2001:fff:ffff::1', '203.0.113.7'];
        yield 'ipv6 /20 first address outside' => ['2001::/20', '2001:1000::1', '2001:1000::1'];
    }

    #[DataProvider('cidrBoundaries')]
    public function testFromGlobalsCidrPrefixNotMultipleOfEightKeepsItsBoundary(string $cidr, string $peer, string $expected): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => $peer,
                'HTTP_X_FORWARDED_FOR' => '203.0.113.7',
            ],
            trustedProxies: [$cidr],
        );

        self::assertSame($expected, $request->clientIp());
    }

    public function testFromGlobalsToleratesWhitespaceEmptyEntriesAndTrailingComma(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '  192.0.2.66 , ,  198.51.100.7 ,',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('198.51.100.7', $request->clientIp());
    }

    public function testFromGlobalsUsesSingleValueVendorHeaderOnlyAsLastResort(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'  => 'GET',
                'HTTP_HOST'       => 'example.com',
                'REQUEST_URI'     => '/',
                'REMOTE_ADDR'     => '10.0.0.1',
                'HTTP_X_REAL_IP'  => '203.0.113.44',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['x-real-ip'],
        );

        self::assertSame('203.0.113.44', $request->clientIp());
    }

    public function testFromGlobalsPrefersForwardedForChainOverSingleValueVendorHeader(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 198.51.100.7',
                'HTTP_X_REAL_IP'       => '203.0.113.44',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['x-forwarded-for', 'x-real-ip'],
        );

        self::assertSame('198.51.100.7', $request->clientIp());
    }

    // -------------------------------------------------------------------------
    // fromGlobals - which forwarding headers may be read at all (trustedHeaders)
    // -------------------------------------------------------------------------

    public function testTrustedHeadersDefaultIsTheXForwardedFamilyOnly(): void
    {
        self::assertSame(
            ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port'],
            Request::TRUSTED_HEADERS_DEFAULT,
        );
    }

    public function testFromGlobalsIgnoresForgedForwardedHeaderWhenProxyManagesForwardedForOnly(): void
    {
        // The proxy appends to X-Forwarded-For and never touches Forwarded, so a
        // Forwarded header arriving here was written by the caller.
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_FORWARDED'       => 'for=192.0.2.66',
                'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('198.51.100.7', $request->clientIp());
    }

    public function testFromGlobalsIgnoresForgedForwardedHeaderStandingAlone(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=192.0.2.66',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('10.0.0.1', $request->clientIp());
    }

    public function testFromGlobalsIgnoresForgedRealIpHeaderByDefault(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_X_REAL_IP' => '192.0.2.66',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('10.0.0.1', $request->clientIp());
    }

    public function testFromGlobalsIgnoresForgedCloudflareHeaderByDefault(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'        => 'GET',
                'HTTP_HOST'             => 'example.com',
                'REQUEST_URI'           => '/',
                'REMOTE_ADDR'           => '10.0.0.1',
                'HTTP_CF_CONNECTING_IP' => '192.0.2.66',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('10.0.0.1', $request->clientIp());
    }

    public function testFromGlobalsDoesNotReadAHeaderOutsideTheAllowlistEvenWhenItIsTheOnlyOne(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'        => 'GET',
                'HTTP_HOST'             => 'example.com',
                'REQUEST_URI'           => '/',
                'REMOTE_ADDR'           => '10.0.0.1',
                'HTTP_CF_CONNECTING_IP' => '192.0.2.66',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['x-forwarded-for'],
        );

        self::assertSame('10.0.0.1', $request->clientIp());
    }

    public function testFromGlobalsOptingIntoForwardedRestoresTheRfc7239Walk(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_FORWARDED'       => 'for=192.0.2.66, for=198.51.100.7',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('198.51.100.7', $request->clientIp());
    }

    public function testFromGlobalsDoesNotFallBackToAnUntrustedChainHeader(): void
    {
        // Forwarded is allowlisted but yields no usable hop. X-Forwarded-For is
        // NOT allowlisted, so it must not become the fallback.
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_FORWARDED'       => 'for=unknown',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('10.0.0.1', $request->clientIp());
    }

    public function testFromGlobalsEmptyTrustedHeadersReadsNothingForwarded(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.internal',
                'REQUEST_URI'            => '/',
                'REMOTE_ADDR'            => '10.0.0.1',
                'HTTP_FORWARDED'         => 'for=192.0.2.66;proto=https;host=evil.example.com',
                'HTTP_X_FORWARDED_FOR'   => '192.0.2.67',
                'HTTP_X_FORWARDED_HOST'  => 'evil.example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_REAL_IP'         => '192.0.2.68',
            ],
            trustedProxies: ['*'],
            trustedHeaders: [],
        );

        self::assertSame('10.0.0.1', $request->clientIp());
        self::assertSame('http://app.internal/', $request->uri()->full());
    }

    public function testFromGlobalsNormalizesConfiguredHeaderNameCaseAndWhitespace(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 198.51.100.7',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['  X-Forwarded-For  '],
        );

        self::assertSame('198.51.100.7', $request->clientIp());
    }

    public function testFromGlobalsIgnoresAnUnknownConfiguredHeaderName(): void
    {
        // A name this class cannot read enables nothing, and building a Request
        // never throws over it. SecurityConfig is where a typo is rejected.
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'example.com',
                'REQUEST_URI'          => '/',
                'REMOTE_ADDR'          => '10.0.0.1',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.66, 198.51.100.7',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['x-forwarded-fro'],
        );

        self::assertSame('10.0.0.1', $request->clientIp());
    }

    // -------------------------------------------------------------------------
    // fromGlobals - the same allowlist governs the URI
    // -------------------------------------------------------------------------

    public function testFromGlobalsIgnoresForwardedHostAndProtoOutsideTheAllowlist(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.internal',
                'REQUEST_URI'            => '/reports',
                'REMOTE_ADDR'            => '10.0.0.1',
                'HTTP_X_FORWARDED_HOST'  => 'public.example.com',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            trustedProxies: ['*'],
            trustedHeaders: ['x-forwarded-for'],
        );

        self::assertSame('http://app.internal/reports', $request->uri()->full());
    }

    public function testFromGlobalsIgnoresRfc7239ForwardedForTheUriByDefault(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/api',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=1.2.3.4;proto=https;host=api.example.com',
            ],
            trustedProxies: ['*'],
        );

        self::assertSame('http://app.internal/api', $request->uri()->full());
    }

    public function testFromGlobalsHonoursForwardedPortOnlyWhenAllowlisted(): void
    {
        $server = [
            'REQUEST_METHOD'        => 'GET',
            'HTTP_HOST'             => 'app.internal',
            'REQUEST_URI'           => '/x',
            'REMOTE_ADDR'           => '10.0.0.1',
            'SERVER_PORT'           => '80',
            'HTTP_X_FORWARDED_PORT' => '8443',
        ];

        $allowed = Request::fromGlobals(server: $server, trustedProxies: ['*']);
        self::assertSame('http://app.internal:8443/x', $allowed->uri()->full());

        $denied = Request::fromGlobals(
            server: $server,
            trustedProxies: ['*'],
            trustedHeaders: ['x-forwarded-for'],
        );
        self::assertSame('http://app.internal/x', $denied->uri()->full());
    }

    // -------------------------------------------------------------------------
    // fromGlobals — URI construction
    // -------------------------------------------------------------------------

    #[DataProvider('forwardedPortsThatAreNotPortNumbers')]
    public function testFromGlobalsIgnoresForwardedPortThatIsNotAPortNumber(string $port): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'        => 'GET',
                'HTTP_HOST'             => 'app.internal',
                'REQUEST_URI'           => '/public',
                'REMOTE_ADDR'           => '10.0.0.1',
                'SERVER_PORT'           => '8080',
                'HTTP_X_FORWARDED_PORT' => $port,
            ],
            trustedProxies: ['*'],
        );

        self::assertSame('http://app.internal:8080/public', $request->uri()->full());
        self::assertSame('/public', $request->path());
    }

    public static function forwardedPortsThatAreNotPortNumbers(): iterable
    {
        yield 'path injection' => ['80/admin?'];
        yield 'authority injection' => ['80@evil.com'];
        yield 'dot segments' => ['1/../admin'];
        yield 'above the port range' => ['65536'];
        yield 'signed' => ['+80'];
        yield 'embedded newline' => ["80\n/admin"];
        yield 'zero' => ['0'];
        yield 'leading zeros' => ['00080'];
        yield 'leading zero on a valid port' => ['080'];
    }

    public function testFromGlobalsKeepsServerPortWhenForwardedPortHasLeadingZeros(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'        => 'GET',
                'HTTP_HOST'             => 'app.internal',
                'REQUEST_URI'           => '/public',
                'REMOTE_ADDR'           => '10.0.0.1',
                'SERVER_PORT'           => '8080',
                'HTTP_X_FORWARDED_PORT' => '08080',
            ],
            trustedProxies: ['*'],
        );

        self::assertSame('http://app.internal:8080/public', $request->uri()->full());
    }

    public function testFromGlobalsIgnoresForwardedElementPortThatIsNotAPortNumber(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/public',
                'REMOTE_ADDR'    => '10.0.0.1',
                'SERVER_PORT'    => '80',
                'HTTP_FORWARDED' => 'port="80/evil"',
            ],
            trustedProxies: ['*'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('http://app.internal/public', $request->uri()->full());
        self::assertSame('/public', $request->path());
    }

    public function testFromGlobalsAcceptsForwardedPortAtTheUpperBound(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'        => 'GET',
                'HTTP_HOST'             => 'app.internal',
                'REQUEST_URI'           => '/public',
                'REMOTE_ADDR'           => '10.0.0.1',
                'SERVER_PORT'           => '80',
                'HTTP_X_FORWARDED_PORT' => '65535',
            ],
            trustedProxies: ['*'],
        );

        self::assertSame('http://app.internal:65535/public', $request->uri()->full());
    }

    public function testFromGlobalsRecognisesAbsoluteRequestUriSchemeInAnyCase(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'proxy.internal',
                'REQUEST_URI'    => 'HTTPS://real.example.com/api',
            ],
        );

        self::assertSame('https://real.example.com/api', $request->uri()->full());
        self::assertSame('/api', $request->path());
    }

    #[DataProvider('originFormTargetsWithoutLeadingSlash')]
    public function testFromGlobalsKeepsTheHostWhenRequestTargetLacksLeadingSlash(string $target, string $expected): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => $target,
            ],
        );

        self::assertSame($expected, $request->uri()->full());
        self::assertSame('app.internal', $request->uri()->host());
    }

    public static function originFormTargetsWithoutLeadingSlash(): iterable
    {
        yield 'authority injection' => ['@evil.com/admin', 'http://app.internal/@evil.com/admin'];
        yield 'bare host' => ['evil.com/admin', 'http://app.internal/evil.com/admin'];
        yield 'empty target' => ['', 'http://app.internal/'];
    }

    public function testFromGlobalsBuildsHttpUri(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/hello?foo=bar',
            ],
            get: ['foo' => 'bar'],
        );

        self::assertSame('http://example.com/hello?foo=bar', $request->uri()->full());
        self::assertSame('/hello', $request->uri()->path());
        self::assertSame('bar', $request->query('foo'));
    }

    public function testFromGlobalsBuildsHttpsUriWhenHttpsKeyIsOn(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTPS'          => 'on',
                'HTTP_HOST'      => 'secure.example.com',
                'REQUEST_URI'    => '/dashboard',
            ],
        );

        self::assertSame('https://secure.example.com/dashboard', $request->uri()->full());
        self::assertTrue($request->uri()->isSecure());
    }

    public function testFromGlobalsHonorsForwardedProtoAndHostWhenTrusted(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.internal',
                'REQUEST_URI'            => '/reports',
                'REMOTE_ADDR'            => '10.0.0.1',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST'  => 'public.example.com',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('https://public.example.com/reports', $request->uri()->full());
        self::assertTrue($request->uri()->isSecure());
    }

    public function testFromGlobalsIgnoresForwardedProtoAndHostWhenNotTrusted(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.internal',
                'REQUEST_URI'            => '/reports',
                'REMOTE_ADDR'            => '203.0.113.50',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST'  => 'evil.example.com',
            ],
        );

        // Without trusted proxies, forwarded proto/host are ignored.
        self::assertSame('http://app.internal/reports', $request->uri()->full());
        self::assertFalse($request->uri()->isSecure());
    }

    public function testFromGlobalsForwardedHeaderTakesPriorityWhenTrusted(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.internal',
                'REQUEST_URI'            => '/api',
                'REMOTE_ADDR'            => '10.0.0.1',
                'HTTP_FORWARDED'         => 'for=1.2.3.4;proto=https;host=api.example.com',
                'HTTP_X_FORWARDED_HOST'  => 'ignored.example.com',
                'HTTP_X_FORWARDED_PROTO' => 'http',
            ],
            trustedProxies: ['*'],
            trustedHeaders: ['forwarded', 'x-forwarded-host', 'x-forwarded-proto'],
        );

        self::assertSame('https://api.example.com/api', $request->uri()->full());
    }

    public function testFromGlobalsHttpsKeyOf1AlsoTriggersSecure(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTPS'          => '1',
                'HTTP_HOST'      => 'secure.example.com',
                'REQUEST_URI'    => '/',
            ],
        );

        self::assertTrue($request->uri()->isSecure());
    }

    public function testFromGlobalsHttpsOffKeyIsNotSecure(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTPS'          => 'off',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
            ],
        );

        self::assertFalse($request->uri()->isSecure());
    }

    public function testFromGlobalsFallsBackToServerNameWhenHostMissing(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'SERVER_NAME'    => 'internal.host',
                'REQUEST_URI'    => '/probe',
            ],
        );

        self::assertSame('http://internal.host/probe', $request->uri()->full());
    }

    public function testFromGlobalsDefaultsToLocalhostAndSlashWhenMinimal(): void
    {
        $request = Request::fromGlobals(server: ['REQUEST_METHOD' => 'GET']);

        self::assertSame('http://localhost/', $request->uri()->full());
    }

    public function testFromGlobalsIncludesNonDefaultServerPortInUri(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'SERVER_NAME'    => 'localhost',
                'SERVER_PORT'    => '8080',
                'REQUEST_URI'    => '/health',
            ],
        );

        self::assertSame('http://localhost:8080/health', $request->uri()->full());
    }

    public function testFromGlobalsOmitsDefaultHttpsPortFromUri(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'SERVER_NAME'    => 'secure.example.com',
                'SERVER_PORT'    => '443',
                'HTTPS'          => 'on',
                'REQUEST_URI'    => '/health',
            ],
        );

        self::assertSame('https://secure.example.com/health', $request->uri()->full());
    }

    public function testFromGlobalsDropsServerPortBehindTlsProxyThatForwardsOnlyTheProto(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'       => 'GET',
                'HTTP_HOST'            => 'app.example.test',
                'REQUEST_URI'          => '/x',
                'REMOTE_ADDR'          => '10.0.0.1',
                'SERVER_PORT'          => '80',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('https://app.example.test/x', $request->uri()->full());
    }

    public function testFromGlobalsDropsServerPortWhenCaddyLikeProxyForwardsProtoAndHost(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.internal:8080',
                'REQUEST_URI'            => '/dashboard',
                'REMOTE_ADDR'            => '10.0.0.2',
                'SERVER_PORT'            => '8080',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_HOST'  => 'app.example.test',
            ],
            trustedProxies: ['10.0.0.2'],
        );

        self::assertSame('https://app.example.test/dashboard', $request->uri()->full());
    }

    public function testFromGlobalsDropsServerPortBehindForwardedHeaderWithProtoOnly(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/x',
                'REMOTE_ADDR'    => '10.0.0.1',
                'SERVER_PORT'    => '80',
                'HTTP_FORWARDED' => 'proto=https;host=app.example.test',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('https://app.example.test/x', $request->uri()->full());
    }

    public function testFromGlobalsKeepsForwardedPortWhenProtoComesFromAForwardedHeader(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'         => 'GET',
                'HTTP_HOST'              => 'app.example.test',
                'REQUEST_URI'            => '/x',
                'REMOTE_ADDR'            => '10.0.0.1',
                'SERVER_PORT'            => '80',
                'HTTP_X_FORWARDED_PROTO' => 'https',
                'HTTP_X_FORWARDED_PORT'  => '8443',
            ],
            trustedProxies: ['10.0.0.1'],
        );

        self::assertSame('https://app.example.test:8443/x', $request->uri()->full());
    }

    public function testFromGlobalsReadsAsteriskOptionsTargetAsRootPath(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'OPTIONS',
                'HTTP_HOST'      => 'app.example.test',
                'REQUEST_URI'    => '*',
            ],
        );

        self::assertSame('http://app.example.test/', $request->uri()->full());
        self::assertSame('/', $request->path());
    }

    public function testFromGlobalsReadsHostAndProtoFromTheElementAppendedByTheOutermostTrustedProxy(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/x',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=192.0.2.1;host=evil.example;proto=http, for=203.0.113.9;host=real.example;proto=https',
            ],
            trustedProxies: ['10.0.0.1'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('https://real.example/x', $request->uri()->full());
        self::assertSame('203.0.113.9', $request->clientIp());
    }

    public function testFromGlobalsReadsTheLeftmostForwardedElementWhenEveryHopIsTrusted(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/x',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=10.0.0.2;host=first.example;proto=https, for=10.0.0.1;host=second.example;proto=http',
            ],
            trustedProxies: ['10.0.0.0/24'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('https://first.example/x', $request->uri()->full());
    }

    public function testFromGlobalsDoesNotSplitForwardedElementsOnSeparatorsInsideQuotedStrings(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/x',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=203.0.113.9;host="legit.example.com,for=_x;host=evil.example;proto=https"',
            ],
            trustedProxies: ['10.0.0.0/24'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('http://legit.example.com%2Cfor%3D_x%3Bhost%3Devil.example%3Bproto%3Dhttps/x', $request->uri()->full());
    }

    public function testFromGlobalsIgnoresForwardedCommaInsideQuotedStringWhenResolvingClientIp(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/x',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=203.0.113.9;host="legit.example.com,for=198.51.100.7"',
            ],
            trustedProxies: ['10.0.0.0/24'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('203.0.113.9', $request->clientIp());
    }

    public function testFromGlobalsHonoursBackslashEscapesInsideForwardedQuotedStrings(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/x',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => 'for=203.0.113.9;host="legit.example.com\\",for=10.0.0.9;proto=https"',
            ],
            trustedProxies: ['10.0.0.0/24'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('http://legit.example.com%5C%22%2Cfor%3D10.0.0.9%3Bproto%3Dhttps/x', $request->uri()->full());
    }

    #[DataProvider('forwardedHeadersWithEmptyElements')]
    public function testFromGlobalsSkipsEmptyForwardedElementsWhenWalkingFromTheRight(string $header): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'app.internal',
                'REQUEST_URI'    => '/x',
                'REMOTE_ADDR'    => '10.0.0.1',
                'HTTP_FORWARDED' => $header,
            ],
            trustedProxies: ['10.0.0.0/24'],
            trustedHeaders: ['forwarded'],
        );

        self::assertSame('https://good.example.com/x', $request->uri()->full());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forwardedHeadersWithEmptyElements(): iterable
    {
        yield 'trailing comma' => ['for=203.0.113.9;host=good.example.com;proto=https,'];
        yield 'empty element between hops' => ['for=203.0.113.9;host=good.example.com;proto=https, , for=10.0.0.2'];
        yield 'whitespace only element' => ['for=203.0.113.9;host=good.example.com;proto=https,   , for=10.0.0.2'];
    }

    public function testFromGlobalsDefaultsMethodToGetWhenAbsent(): void
    {
        $request = Request::fromGlobals(server: []);

        self::assertSame('GET', $request->method);
    }

    // -------------------------------------------------------------------------
    // fromGlobals — header extraction
    // -------------------------------------------------------------------------

    public function testFromGlobalsExtractsHttpPrefixedHeaders(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'      => 'GET',
                'HTTP_HOST'           => 'example.com',
                'REQUEST_URI'         => '/',
                'HTTP_ACCEPT'         => 'application/json',
                'HTTP_X_REQUEST_ID'   => 'abc-123',
                'HTTP_AUTHORIZATION'  => 'Bearer token',
            ],
        );

        self::assertSame('application/json', $request->headers()->get('accept'));
        self::assertSame('abc-123', $request->headers()->get('x-request-id'));
        self::assertSame('Bearer token', $request->headers()->get('authorization'));
    }

    public function testFromGlobalsExtractsContentTypeWithoutHttpPrefix(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/upload',
                'CONTENT_TYPE'   => 'multipart/form-data',
                'CONTENT_LENGTH' => '1024',
            ],
        );

        self::assertSame('multipart/form-data', $request->headers()->get('content-type'));
        self::assertSame('1024', $request->headers()->get('content-length'));
    }

    public function testFromGlobalsHeaderLookupIsCaseInsensitive(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'   => 'GET',
                'HTTP_HOST'        => 'example.com',
                'REQUEST_URI'      => '/',
                'HTTP_X_CUSTOM_HDR' => 'value',
            ],
        );

        self::assertSame('value', $request->headers()->get('X-Custom-Hdr'));
        self::assertSame('value', $request->headers()->get('x-custom-hdr'));
    }

    public function testFromGlobalsEmptyContentTypeIsNotExtracted(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/',
                'CONTENT_TYPE'   => '',
            ],
        );

        self::assertNull($request->headers()->get('content-type'));
    }

    // -------------------------------------------------------------------------
    // fromGlobals — body parsing
    // -------------------------------------------------------------------------

    #[DataProvider('unparsableJsonBodies')]
    public function testFromGlobalsFlagsJsonBodyThatIsNotAnObject(string $raw): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: $raw,
        );

        self::assertSame([], $request->body()->all());
        self::assertTrue($request->body()->isMalformed());
    }

    public static function unparsableJsonBodies(): iterable
    {
        yield 'truncated object' => ['{"a":'];
        yield 'json scalar' => ['42'];
        yield 'json null' => ['null'];
        yield 'nul byte' => ["{\"a\":\u{0}}"];
        yield 'nul byte only' => ["\u{0}"];
    }

    #[DataProvider('wellFormedBodies')]
    public function testFromGlobalsDoesNotFlagAbsentOrValidBody(string $contentType, string $raw): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => $contentType,
            ],
            post: ['field' => 'value'],
            rawBody: $raw,
        );

        self::assertFalse($request->body()->isMalformed());
    }

    public function testWithAttributeKeepsMalformedFlag(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"a":',
        );

        self::assertTrue($request->withAttribute('k', 'v')->body()->isMalformed());
    }

    public static function wellFormedBodies(): iterable
    {
        yield 'empty json body' => ['application/json', ''];
        yield 'whitespace-only json body' => ['application/json', " \n\t\r "];
        yield 'valid json object' => ['application/json', '{"a":1}'];
        yield 'valid empty json object' => ['application/json', '{}'];
        yield 'form body' => ['application/x-www-form-urlencoded', 'field=value'];
    }

    public function testFromGlobalsJsonBodyParsedFromRawBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"title":"Widget","price":9.99}',
        );

        self::assertTrue($request->headers()->isJson());
        self::assertSame('Widget', $request->body()->get('title'));
        self::assertSame(9.99, $request->body()->get('price'));
    }

    public function testFromGlobalsJsonBodyWithCharsetParameterParsed(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'PUT',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items/1',
                'CONTENT_TYPE'   => 'application/json; charset=utf-8',
            ],
            rawBody: '{"status":"active"}',
        );

        self::assertSame('active', $request->body()->get('status'));
    }

    public function testFromGlobalsJsonSuffixMediaTypeBodyIsParsed(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/problems',
                'CONTENT_TYPE'   => 'application/problem+json',
            ],
            rawBody: '{"type":"about:blank","title":"Bad Request"}',
        );

        self::assertSame('Bad Request', $request->body()->get('title'));
    }

    public function testFromGlobalsEmptyJsonBodyReturnsEmptyParsedBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '',
        );

        self::assertSame([], $request->body()->all());
    }

    public function testFromGlobalsInvalidJsonBodyReturnsEmptyParsedBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"title":"broken"',
        );

        self::assertSame([], $request->body()->all());
    }

    public function testFromGlobalsFormBodyUsesPostArray(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/login',
                'CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
            ],
            post: ['username' => 'alice', 'password' => 's3cr3t'],
        );

        self::assertSame('alice', $request->body()->get('username'));
        self::assertSame('s3cr3t', $request->body()->get('password'));
    }

    public function testFromGlobalsMultipartFormUsesPostArray(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/upload',
                'CONTENT_TYPE'   => 'multipart/form-data; boundary=----xyz',
            ],
            post: ['field' => 'value'],
        );

        self::assertSame('value', $request->body()->get('field'));
    }

    public function testFromGlobalsGetRequestHasNoParsedBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/search',
            ],
            post: ['should' => 'be ignored'],
        );

        self::assertSame([], $request->body()->all());
    }

    public function testFromGlobalsHeadRequestHasNoParsedBody(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'HEAD',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/ping',
            ],
            post: ['ignored' => 'yes'],
        );

        self::assertSame([], $request->body()->all());
    }

    // -------------------------------------------------------------------------
    // fromGlobals — method override
    // -------------------------------------------------------------------------

    public function testFromGlobalsMethodOverrideViaPostField(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/items/5',
                'CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
            ],
            post: ['_method' => 'DELETE', 'confirm' => '1'],
        );

        self::assertSame('DELETE', $request->method);
        // Override field stays in body (controller may inspect it)
        self::assertSame('DELETE', $request->body()->get('_method'));
    }

    public function testFromGlobalsMethodOverrideViaPutPostField(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/items/5',
                'CONTENT_TYPE'   => 'application/x-www-form-urlencoded',
            ],
            post: ['_method' => 'put', 'name' => 'Widget'],
        );

        self::assertSame('PUT', $request->method);
    }

    public function testFromGlobalsMethodOverrideViaHeader(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'          => 'POST',
                'HTTP_HOST'               => 'api.example.com',
                'REQUEST_URI'             => '/items/3',
                'CONTENT_TYPE'            => 'application/json',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'PATCH',
            ],
            rawBody: '{"status":"closed"}',
        );

        self::assertSame('PATCH', $request->method);
    }

    public function testFromGlobalsHeaderOverrideTakesPriorityOverFieldOverride(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'              => 'POST',
                'HTTP_HOST'                   => 'example.com',
                'REQUEST_URI'                 => '/items/1',
                'CONTENT_TYPE'                => 'application/x-www-form-urlencoded',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
            ],
            post: ['_method' => 'PATCH'],
        );

        // Header wins over body field
        self::assertSame('DELETE', $request->method);
    }

    public function testFromGlobalsIgnoresUnsupportedMethodOverride(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'              => 'POST',
                'HTTP_HOST'                   => 'example.com',
                'REQUEST_URI'                 => '/items/1',
                'CONTENT_TYPE'                => 'application/x-www-form-urlencoded',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'TRACE',
            ],
            post: ['_method' => 'OPTIONS'],
        );

        self::assertSame('POST', $request->method);
    }

    public function testFromGlobalsMethodOverrideIgnoredForNonPostRequests(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD'              => 'GET',
                'HTTP_HOST'                   => 'example.com',
                'REQUEST_URI'                 => '/',
                'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE',
            ],
        );

        self::assertSame('GET', $request->method);
    }

    // -------------------------------------------------------------------------
    // fromGlobals — cookies
    // -------------------------------------------------------------------------

    public function testFromGlobalsCookiesAreAvailable(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/profile',
            ],
            cookie: ['session_id' => 'xyz789', 'pref_lang' => 'en'],
        );

        self::assertSame('xyz789', $request->cookies()->get('session_id'));
        self::assertSame('en', $request->cookies()->get('pref_lang'));
        self::assertNull($request->cookies()->get('nonexistent'));
    }

    public function testFromArrayNormalizesNativeFilesShapeLikeFromGlobals(): void
    {
        $request = Request::fromArray('POST', '/upload', files: [
            'avatar' => [
                'name' => 'me.png',
                'type' => 'image/png',
                'tmp_name' => '/tmp/phpA',
                'error' => UPLOAD_ERR_OK,
                'size' => 123,
            ],
        ]);

        self::assertEquals(new FileUpload('me.png', 'image/png', '/tmp/phpA', 123), $request->file('avatar'));
    }

    public function testConstructorNormalizesNativeFilesShape(): void
    {
        $request = new Request('POST', '/upload', files: [
            'avatar' => [
                'name' => 'me.png',
                'type' => 'image/png',
                'tmp_name' => '/tmp/phpA',
                'error' => UPLOAD_ERR_OK,
                'size' => 123,
            ],
        ]);

        self::assertEquals(new FileUpload('me.png', 'image/png', '/tmp/phpA', 123), $request->file('avatar'));
    }

    public function testFromArrayKeepsFileUploadListAsGiven(): void
    {
        $first = new FileUpload('a.png', 'image/png', '/tmp/phpA', 1);
        $second = new FileUpload('b.png', 'image/png', '/tmp/phpB', 2);
        $request = Request::fromArray('POST', '/upload', files: ['gallery' => [$first, $second]]);

        self::assertSame([$first, $second], $request->filesOf('gallery'));
    }

    public function testFileReturnsFirstUploadFromList(): void
    {
        $first = new FileUpload('a.png', 'image/png', '/tmp/phpA', 1);
        $second = new FileUpload('b.png', 'image/png', '/tmp/phpB', 2);
        $request = Request::fromArray('POST', '/upload', files: ['photos' => [$first, $second]]);

        self::assertSame($first, $request->file('photos'));
    }

    public function testFileReturnsLeadingUploadFromListMixedWithOtherValues(): void
    {
        $upload = new FileUpload('a.png', 'image/png', '/tmp/phpA', 1);
        $request = Request::fromArray('POST', '/upload', files: ['photos' => [$upload, 'junk']]);

        self::assertSame($upload, $request->file('photos'));
    }

    public function testFromGlobalsNormalizesSingleFileUpload(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/upload',
            ],
            files: [
                'avatar' => [
                    'name' => 'my-photo.JPG',
                    'type' => 'image/jpeg',
                    'tmp_name' => '/tmp/php-upload',
                    'error' => UPLOAD_ERR_OK,
                    'size' => 1024,
                ],
            ],
        );

        $file = $request->file('avatar');

        self::assertInstanceOf(FileUpload::class, $file);
        self::assertSame('my-photo.JPG', $file->originalName);
        self::assertSame('jpg', $file->extension());
        self::assertSame('/tmp/php-upload', $file->tmpPath);
    }

    public function testFromGlobalsNormalizesMultiFileUploadArrayShape(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/upload',
            ],
            files: [
                'photos' => [
                    'name' => ['a.jpg', 'b.jpg'],
                    'type' => ['image/jpeg', 'image/jpeg'],
                    'tmp_name' => ['/tmp/a', '/tmp/b'],
                    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                    'size' => [100, 200],
                ],
            ],
        );

        self::assertSame('/tmp/a', $request->file('photos')?->tmpPath);

        $files = $request->filesOf('photos');
        self::assertCount(2, $files);
        self::assertSame('/tmp/a', $files[0]->tmpPath);
        self::assertSame('/tmp/b', $files[1]->tmpPath);
    }

    public function testFromGlobalsNormalizesNestedMultiFileUploadArrayShape(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/upload',
            ],
            files: [
                'attachments' => [
                    'name' => ['contracts' => ['a.pdf', 'b.pdf']],
                    'type' => ['contracts' => ['application/pdf', 'application/pdf']],
                    'tmp_name' => ['contracts' => ['/tmp/c1', '/tmp/c2']],
                    'error' => ['contracts' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK]],
                    'size' => ['contracts' => [10, 20]],
                ],
            ],
        );

        $files = $request->filesOf('attachments');
        self::assertCount(2, $files);
        self::assertSame('/tmp/c1', $files[0]->tmpPath);
        self::assertSame('/tmp/c2', $files[1]->tmpPath);
    }

    #[DataProvider('unreadableFileEntries')]
    public function testFromArrayRefusesUnreadableFileEntryWithoutEchoingIt(mixed $entry): void
    {
        try {
            Request::fromArray('POST', '/upload', files: ['avatar' => $entry]);
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('avatar', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());

            return;
        }

        self::fail('An unreadable file entry must be refused by fromArray().');
    }

    public static function unreadableFileEntries(): iterable
    {
        yield 'scalar value' => ['secret-content'];
        yield 'empty array' => [[]];
        yield 'missing tmp_name' => [['name' => 'secret.png', 'error' => UPLOAD_ERR_OK, 'size' => 1]];
        yield 'missing error' => [['name' => 'secret.png', 'tmp_name' => '/tmp/phpA', 'size' => 1]];
    }

    public function testFromGlobalsSkipsMalformedSingleFileEntry(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/upload',
            ],
            files: [
                'avatar' => [
                    'name' => 'bad.jpg',
                    // tmp_name intentionally missing
                    'error' => UPLOAD_ERR_OK,
                    'size' => 12,
                ],
            ],
        );

        self::assertNull($request->file('avatar'));
        self::assertSame([], $request->filesOf('avatar'));
    }

    public function testFromGlobalsSkipsMalformedEntriesInsideMultiUploadShape(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'example.com',
                'REQUEST_URI' => '/upload',
            ],
            files: [
                'photos' => [
                    'name' => ['a.jpg', 'b.jpg'],
                    'type' => ['image/jpeg', 'image/jpeg'],
                    'tmp_name' => ['/tmp/a'], // second index missing
                    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                    'size' => [100, 200],
                ],
            ],
        );

        $files = $request->filesOf('photos');
        self::assertCount(1, $files);
        self::assertSame('/tmp/a', $files[0]->tmpPath);
    }

    // -------------------------------------------------------------------------
    // fromGlobals — query string
    // -------------------------------------------------------------------------

    public function testFromGlobalsPopulatesQueryFromGetArray(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'example.com',
                'REQUEST_URI'    => '/search?q=zephyrus&page=2',
            ],
            get: ['q' => 'zephyrus', 'page' => '2'],
        );

        self::assertSame('zephyrus', $request->query('q'));
        self::assertSame('2', $request->query('page'));
        self::assertNull($request->query('missing'));
    }

    // -------------------------------------------------------------------------
    // fromGlobals — absolute REQUEST_URI (reverse proxy)
    // -------------------------------------------------------------------------

    #[DataProvider('absoluteRequestTargetsWithMixedCaseScheme')]
    public function testFromGlobalsLowercasesTheSchemeOfAnAbsoluteRequestTarget(string $target, string $expected): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'proxy.internal',
                'REQUEST_URI'    => $target,
            ],
        );

        self::assertSame($expected, $request->uri()->full());
    }

    public static function absoluteRequestTargetsWithMixedCaseScheme(): iterable
    {
        yield 'upper HTTP' => ['HTTP://real.example.com/x', 'http://real.example.com/x'];
        yield 'mixed HTTPS' => ['Https://real.example.com/x?y=1', 'https://real.example.com/x?y=1'];
    }


    public function testFromGlobalsPassesThroughAbsoluteRequestUri(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'GET',
                'HTTP_HOST'      => 'proxy.internal',
                'REQUEST_URI'    => 'https://real.example.com/api/v1/users',
            ],
        );

        self::assertSame('https://real.example.com/api/v1/users', $request->uri()->full());
    }

    // -------------------------------------------------------------------------
    // fromGlobals — delete / patch with JSON body
    // -------------------------------------------------------------------------

    public function testFromGlobalsDeleteWithJsonBodyIsParsed(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'DELETE',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items/bulk',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"ids":[1,2,3]}',
        );

        self::assertSame('DELETE', $request->method);
        self::assertSame([1, 2, 3], $request->body()->get('ids'));
    }

    public function testFromGlobalsPatchWithJsonBodyIsParsed(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_METHOD' => 'PATCH',
                'HTTP_HOST'      => 'api.example.com',
                'REQUEST_URI'    => '/items/7',
                'CONTENT_TYPE'   => 'application/json',
            ],
            rawBody: '{"active":true,"score":4.5}',
        );

        self::assertSame(true, $request->body()->get('active'));
        self::assertSame(4.5, $request->body()->get('score'));
    }

    // -------------------------------------------------------------------------
    // getParameter / getParameters / getHeader convenience methods
    // -------------------------------------------------------------------------

    public function testGetParameterPrefersBodyOverQuery(): void
    {
        $request = Request::fromArray(
            method: 'POST',
            uri: '/update',
            body: ['name' => 'from-body'],
            query: ['name' => 'from-query', 'page' => '2'],
        );

        self::assertSame('from-body', $request->getParameter('name'));
        self::assertSame('2', $request->getParameter('page'));
    }

    public function testGetParameterFallsBackToQueryWhenBodyMissing(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/search',
            query: ['q' => 'zephyrus'],
        );

        self::assertSame('zephyrus', $request->getParameter('q'));
    }

    public function testGetParameterReturnsDefaultWhenNotFound(): void
    {
        $request = Request::fromArray(method: 'GET', uri: '/empty');

        self::assertNull($request->getParameter('missing'));
        self::assertSame('fallback', $request->getParameter('missing', 'fallback'));
    }

    public function testGetParametersMergesQueryAndBody(): void
    {
        $request = Request::fromArray(
            method: 'POST',
            uri: '/submit',
            body: ['name' => 'Alice', 'role' => 'admin'],
            query: ['page' => '1', 'name' => 'from-query'],
        );

        $params = $request->getParameters();

        // Body overwrites query for shared keys (array_merge behavior)
        self::assertSame('Alice', $params['name']);
        self::assertSame('admin', $params['role']);
        self::assertSame('1', $params['page']);
    }

    public function testGetParametersReturnsEmptyWhenNonePresent(): void
    {
        $request = Request::fromArray(method: 'GET', uri: '/empty');

        self::assertSame([], $request->getParameters());
    }

    public function testQueryIsDerivedFromUriWhenOmitted(): void
    {
        $request = new Request('GET', '/p?a=1&b[]=2');

        self::assertSame('1', $request->query('a'));
        self::assertSame(['2'], $request->query('b'));
        self::assertSame(['a' => '1', 'b' => ['2']], $request->query);
    }

    public function testExplicitEmptyQueryOverridesUriQueryString(): void
    {
        $request = new Request('GET', '/p?period=x', query: []);

        self::assertSame([], $request->query);
        self::assertNull($request->query('period'));
    }

    public function testExplicitQueryIsUsedAsIs(): void
    {
        $request = new Request('GET', '/p?period=x', query: ['page' => '2']);

        self::assertSame(['page' => '2'], $request->query);
    }

    public function testGetHeaderReturnsHeaderValue(): void
    {
        $request = Request::fromArray(
            method: 'GET',
            uri: '/api',
            headers: ['X-Request-Id' => 'abc-123', 'Accept' => 'application/json'],
        );

        self::assertSame('abc-123', $request->getHeader('X-Request-Id'));
        self::assertSame('application/json', $request->getHeader('accept'));
    }

    public function testGetHeaderReturnsDefaultWhenMissing(): void
    {
        $request = Request::fromArray(method: 'GET', uri: '/api');

        self::assertNull($request->getHeader('X-Missing'));
        self::assertSame('text/html', $request->getHeader('Accept', 'text/html'));
    }

    public function testFromArrayDerivesTheQueryFromTheUriWhenNoneIsGiven(): void
    {
        $request = Request::fromArray(method: 'GET', uri: '/p?period=x&page=2');

        self::assertSame('x', $request->query('period'));
        self::assertSame(['period' => 'x', 'page' => '2'], $request->query);
    }

    public function testFromArrayKeepsAnExplicitEmptyQuery(): void
    {
        $request = Request::fromArray(method: 'GET', uri: '/p?period=x', query: []);

        self::assertSame([], $request->query);
        self::assertNull($request->query('period'));
    }

    public function testRouteIsNullBeforeRouting(): void
    {
        self::assertNull(Request::fromArray(method: 'GET', uri: '/p')->route());
    }

    public function testWithMatchedRouteRecordsTheRouteAndItsParameters(): void
    {
        $route = new Route('GET', '/users/{id}', 'Handler@show', name: 'users.show');
        $request = Request::fromArray(method: 'GET', uri: '/users/7')
            ->withMatchedRoute(new RouteMatch($route, ['id' => '7']));

        self::assertSame($route, $request->route());
        self::assertSame('7', $request->attribute('id'));
        self::assertSame(['id' => '7'], $request->routeParameters);
    }

    public function testRouteSurvivesEveryWithMethod(): void
    {
        $route = new Route('GET', '/users/{id}', 'Handler@show');
        $request = Request::fromArray(method: 'GET', uri: '/users/7')
            ->withMatchedRoute(new RouteMatch($route, ['id' => '7']));

        self::assertSame($route, $request->withAttribute('k', 'v')->route());
        self::assertSame($route, $request->withAttributes(['k' => 'v'])->route());
        self::assertSame($route, $request->withRouteParameters(['k' => 'v'])->route());
        self::assertSame($route, $request->withAttribute('k', 'v')->withAttributes(['j' => 'w'])->route());
    }
}
