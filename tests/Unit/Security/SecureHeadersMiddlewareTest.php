<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;

final class SecureHeadersMiddlewareTest extends TestCase
{
    // Helper — build a minimal HTTP request.
    private function makeRequest(bool $secure = false): Request
    {
        $uri = $secure ? 'https://example.com/test' : 'http://example.com/test';

        return new Request('GET', $uri);
    }

    // Helper — run the middleware against a plain text 200 OK response.
    private function process(SecureHeadersMiddleware $mw, Request $request): Response
    {
        $inner = Response::text('ok');

        return $mw->process($request, fn (Request $r): Response => $inner);
    }

    // ── default header emission ───────────────────────────────────────────────

    public function testDefaultsEmitXFrameOptions(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());
        $headers = $response->toHeaderLines();

        self::assertContains('x-frame-options: SAMEORIGIN', $headers);
    }

    public function testDefaultsEmitXContentTypeOptions(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains('x-content-type-options: nosniff', $response->toHeaderLines());
    }

    public function testDefaultsEmitReferrerPolicy(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains('referrer-policy: strict-origin-when-cross-origin', $response->toHeaderLines());
    }

    public function testDefaultsEmitXssProtection(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains('x-xss-protection: 0', $response->toHeaderLines());
    }

    public function testDefaultsDoNotEmitCsp(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('content-security-policy', $headerString);
    }

    public function testDefaultsDoNotEmitPermissionsPolicy(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('permissions-policy', $headerString);
    }

    // ── HSTS only on HTTPS ────────────────────────────────────────────────────

    public function testHstsNotEmittedOnHttp(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 31_536_000]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest(secure: false));
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('strict-transport-security', $headerString);
    }

    public function testHstsEmittedOnHttps(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 31_536_000]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest(secure: true));

        self::assertContains('strict-transport-security: max-age=31536000', $response->toHeaderLines());
    }

    public function testHstsWithIncludeSubdomainsOnHttps(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'hstsMaxAge'            => 31_536_000,
            'hstsIncludeSubdomains' => true,
        ]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest(secure: true));

        self::assertContains(
            'strict-transport-security: max-age=31536000; includeSubDomains',
            $response->toHeaderLines(),
        );
    }

    /**
     * THE TRAP THIS PINS. Uri::__construct fell back to "http" and "localhost"
     * when parse_url() failed, silently, so a malformed authority collapsed the
     * whole URI and uri()->isSecure() answered false on a request the SAPI had
     * reported as HTTPS. HSTS was then dropped with no error anywhere.
     *
     * "https://example.com:port/secure" is a URL parse_url() rejects outright,
     * and Request::fromGlobals builds exactly that shape from an attacker-chosen
     * Host header on an HTTPS connection.
     *
     * ## WHAT CHANGED, AND WHY THESE TWO ASSERTIONS ARE INVERTED
     *
     * The two lines below used to read:
     *
     *     self::assertFalse($request->uri()->isSecure());
     *     self::assertSame('localhost', $request->uri()->host());
     *
     * and were commented "the collapse itself, asserted rather than assumed".
     * They pinned the DEFECT as a precondition of the workaround, so they had to
     * flip the moment the defect was fixed at its root in Uri::__construct,
     * which no longer invents an authority it failed to parse. The headline
     * guarantee of this test, that HSTS survives a malformed authority, is
     * unchanged and still asserted below; what changed is that it is now true
     * for the right reason. The assertions were strengthened, not relaxed: the
     * scheme and the host are now the ones actually reported.
     */
    public function testHstsIsStillEmittedWhenAMalformedAuthorityDefeatsUriParsing(): void
    {
        $request = new Request('GET', 'https://example.com:port/secure');
        // The URI no longer collapses: the scheme and host survive the parse
        // failure, so isSecure() answers correctly on its own.
        self::assertTrue($request->uri()->isSecure());
        self::assertSame('example.com:port', $request->uri()->host());

        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 31_536_000]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $request);

        self::assertContains('strict-transport-security: max-age=31536000', $response->toHeaderLines());
    }

    public function testHstsIsNotEmittedWhenAMalformedAuthorityAppearsOnAnHttpUrl(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 31_536_000]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, new Request('GET', 'http://example.com:port/plain'));
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('strict-transport-security', $headerString);
    }

    public function testHstsDisabledWhenMaxAgeIsZeroOnHttps(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 0]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest(secure: true));
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('strict-transport-security', $headerString);
    }

    // ── optional headers emitted when configured ──────────────────────────────

    public function testCspEmittedWhenConfigured(): void
    {
        $config = SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains("content-security-policy: default-src 'self'", $response->toHeaderLines());
    }

    public function testPermissionsPolicyEmittedWhenConfigured(): void
    {
        $config = SecureHeadersConfig::fromArray(['permissionsPolicy' => 'camera=(), microphone=()']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains('permissions-policy: camera=(), microphone=()', $response->toHeaderLines());
    }

    // ── disabling individual headers ─────────────────────────────────────────

    public function testXFrameOptionsSkippedWhenEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray(['xFrameOptions' => '']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('x-frame-options', $headerString);
    }

    public function testXContentTypeOptionsSkippedWhenEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray(['xContentTypeOptions' => '']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('x-content-type-options', $headerString);
    }

    public function testReferrerPolicySkippedWhenEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray(['referrerPolicy' => '']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('referrer-policy', $headerString);
    }

    public function testXssProtectionSkippedWhenEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray(['xssProtection' => '']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('x-xss-protection', $headerString);
    }

    // ── response is immutable — original not mutated ──────────────────────────

    public function testOriginalResponseIsNotMutated(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $inner = Response::text('ok');

        $mw->process($this->makeRequest(), fn (Request $r): Response => $inner);

        // The captured inner response should still have no security headers.
        $headerString = implode("\n", $inner->toHeaderLines());

        self::assertStringNotContainsString('x-frame-options', $headerString);
    }

    // ── full headers snapshot ────────────────────────────────────────────────

    public function testAllHeadersTogetherOnHttps(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'xFrameOptions'         => 'DENY',
            'referrerPolicy'        => 'no-referrer',
            'csp'                   => "default-src 'none'",
            'permissionsPolicy'     => 'camera=()',
            'hstsMaxAge'            => 86_400,
            'hstsIncludeSubdomains' => true,
        ]);

        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest(secure: true));
        $headers = $response->toHeaderLines();

        self::assertContains('x-frame-options: DENY', $headers);
        self::assertContains('x-content-type-options: nosniff', $headers);
        self::assertContains('referrer-policy: no-referrer', $headers);
        self::assertContains('x-xss-protection: 0', $headers);
        self::assertContains("content-security-policy: default-src 'none'", $headers);
        self::assertContains('permissions-policy: camera=()', $headers);
        self::assertContains('strict-transport-security: max-age=86400; includeSubDomains', $headers);
    }

    // ── a header the route already set is kept ───────────────────────────────

    /**
     * A route that set its own non-empty value for a configured name keeps it.
     * The route's value differs from the configured one, so an overwrite cannot
     * pass as a coincidence.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function routeOwnedHeaders(): iterable
    {
        yield 'X-Frame-Options' => ['X-Frame-Options', 'DENY'];
        yield 'X-Content-Type-Options' => ['X-Content-Type-Options', 'none'];
        yield 'Referrer-Policy' => ['Referrer-Policy', 'no-referrer'];
        yield 'X-XSS-Protection' => ['X-XSS-Protection', '1; mode=block'];
        yield 'Content-Security-Policy' => ['Content-Security-Policy', "default-src 'none'"];
        yield 'Permissions-Policy' => ['Permissions-Policy', 'camera=()'];
        yield 'Strict-Transport-Security' => ['Strict-Transport-Security', 'max-age=0'];
    }

    #[DataProvider('routeOwnedHeaders')]
    public function testRouteOwnedHeaderIsKeptWhenTheConfigurationAlsoSetsIt(string $name, string $routeValue): void
    {
        $config = SecureHeadersConfig::fromArray([
            'csp'               => "default-src 'self'",
            'permissionsPolicy' => 'geolocation=()',
            'hstsMaxAge'        => 31_536_000,
        ]);
        $mw = new SecureHeadersMiddleware($config);
        $inner = Response::text('ok')->withHeader($name, $routeValue);

        $response = $mw->process($this->makeRequest(secure: true), fn (Request $r): Response => $inner);

        self::assertSame($routeValue, $response->headers[strtolower($name)]);
    }

    public function testConfiguredValueIsStillAppliedWhenTheRouteSetsNoSecurityHeader(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'referrerPolicy'    => 'same-origin',
            'csp'               => "default-src 'self'",
            'permissionsPolicy' => 'geolocation=()',
            'hstsMaxAge'        => 31_536_000,
        ]);
        $mw = new SecureHeadersMiddleware($config);

        $response = $mw->process($this->makeRequest(secure: true), fn (Request $r): Response => Response::text('ok'));

        self::assertSame('same-origin', $response->headers['referrer-policy']);
        self::assertSame("default-src 'self'", $response->headers['content-security-policy']);
        self::assertSame('geolocation=()', $response->headers['permissions-policy']);
        self::assertSame('max-age=31536000', $response->headers['strict-transport-security']);
    }

    /** @return iterable<string, array{string}> */
    public static function blankRouteValues(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => [' '];
        yield 'tab and newline' => ["\t\n"];
    }

    #[DataProvider('blankRouteValues')]
    public function testBlankRouteValueGetsTheConfiguredDefault(string $blank): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $inner = Response::text('ok')->withHeader('X-Frame-Options', $blank);

        $response = $mw->process($this->makeRequest(), fn (Request $r): Response => $inner);

        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
    }

    public function testRouteHeaderIsKeptWhenOtherConfiguredHeadersAreStillApplied(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $inner = Response::text('ok')->withHeader('Referrer-Policy', 'no-referrer');

        $response = $mw->process($this->makeRequest(), fn (Request $r): Response => $inner);

        self::assertSame('no-referrer', $response->headers['referrer-policy']);
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
        self::assertSame('nosniff', $response->headers['x-content-type-options']);
    }
}
