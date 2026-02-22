<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

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

        self::assertContains('X-Frame-Options: SAMEORIGIN', $headers);
    }

    public function testDefaultsEmitXContentTypeOptions(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains('X-Content-Type-Options: nosniff', $response->toHeaderLines());
    }

    public function testDefaultsEmitReferrerPolicy(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains('Referrer-Policy: strict-origin-when-cross-origin', $response->toHeaderLines());
    }

    public function testDefaultsEmitXssProtection(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains('X-XSS-Protection: 0', $response->toHeaderLines());
    }

    public function testDefaultsDoNotEmitCsp(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('Content-Security-Policy', $headerString);
    }

    public function testDefaultsDoNotEmitPermissionsPolicy(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('Permissions-Policy', $headerString);
    }

    // ── HSTS only on HTTPS ────────────────────────────────────────────────────

    public function testHstsNotEmittedOnHttp(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 31_536_000]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest(secure: false));
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('Strict-Transport-Security', $headerString);
    }

    public function testHstsEmittedOnHttps(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 31_536_000]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest(secure: true));

        self::assertContains('Strict-Transport-Security: max-age=31536000', $response->toHeaderLines());
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
            'Strict-Transport-Security: max-age=31536000; includeSubDomains',
            $response->toHeaderLines(),
        );
    }

    public function testHstsDisabledWhenMaxAgeIsZeroOnHttps(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 0]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest(secure: true));
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('Strict-Transport-Security', $headerString);
    }

    // ── optional headers emitted when configured ──────────────────────────────

    public function testCspEmittedWhenConfigured(): void
    {
        $config = SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"]);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains("Content-Security-Policy: default-src 'self'", $response->toHeaderLines());
    }

    public function testPermissionsPolicyEmittedWhenConfigured(): void
    {
        $config = SecureHeadersConfig::fromArray(['permissionsPolicy' => 'camera=(), microphone=()']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());

        self::assertContains('Permissions-Policy: camera=(), microphone=()', $response->toHeaderLines());
    }

    // ── disabling individual headers ─────────────────────────────────────────

    public function testXFrameOptionsSkippedWhenEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray(['xFrameOptions' => '']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('X-Frame-Options', $headerString);
    }

    public function testXContentTypeOptionsSkippedWhenEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray(['xContentTypeOptions' => '']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('X-Content-Type-Options', $headerString);
    }

    public function testReferrerPolicySkippedWhenEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray(['referrerPolicy' => '']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('Referrer-Policy', $headerString);
    }

    public function testXssProtectionSkippedWhenEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray(['xssProtection' => '']);
        $mw = new SecureHeadersMiddleware($config);
        $response = $this->process($mw, $this->makeRequest());
        $headerString = implode("\n", $response->toHeaderLines());

        self::assertStringNotContainsString('X-XSS-Protection', $headerString);
    }

    // ── response is immutable — original not mutated ──────────────────────────

    public function testOriginalResponseIsNotMutated(): void
    {
        $mw = new SecureHeadersMiddleware(SecureHeadersConfig::defaults());
        $inner = Response::text('ok');

        $mw->process($this->makeRequest(), fn (Request $r): Response => $inner);

        // The captured inner response should still have no security headers.
        $headerString = implode("\n", $inner->toHeaderLines());

        self::assertStringNotContainsString('X-Frame-Options', $headerString);
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

        self::assertContains('X-Frame-Options: DENY', $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers);
        self::assertContains('Referrer-Policy: no-referrer', $headers);
        self::assertContains('X-XSS-Protection: 0', $headers);
        self::assertContains("Content-Security-Policy: default-src 'none'", $headers);
        self::assertContains('Permissions-Policy: camera=()', $headers);
        self::assertContains('Strict-Transport-Security: max-age=86400; includeSubDomains', $headers);
    }
}
