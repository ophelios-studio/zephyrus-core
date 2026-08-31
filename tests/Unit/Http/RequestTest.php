<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
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
}
