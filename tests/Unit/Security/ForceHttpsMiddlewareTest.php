<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\ForceHttpsMiddleware;

final class ForceHttpsMiddlewareTest extends TestCase
{
    private ForceHttpsMiddleware $mw;

    protected function setUp(): void
    {
        $this->mw = new ForceHttpsMiddleware();
    }

    // ── already-secure requests pass through ──────────────────────────────────

    public function testSecureRequestPassesThrough(): void
    {
        $request  = new Request('GET', 'https://example.com/page');
        $inner    = Response::text('hello');
        $response = $this->mw->process($request, fn (Request $r): Response => $inner);

        self::assertSame(200, $response->status);
        self::assertSame('hello', $response->body);
    }

    public function testSecurePostRequestPassesThrough(): void
    {
        $request  = new Request('POST', 'https://example.com/submit', body: ['x' => '1']);
        $inner    = Response::json(['ok' => true]);
        $response = $this->mw->process($request, fn (Request $r): Response => $inner);

        self::assertSame(200, $response->status);
    }

    // ── plain-HTTP requests are redirected ────────────────────────────────────

    public function testHttpRedirectsToHttps(): void
    {
        $request  = new Request('GET', 'http://example.com/page');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(308, $response->status);
        self::assertStringContainsString('location', implode("\n", $response->toHeaderLines()));
        self::assertContains('location: https://example.com/page', $response->toHeaderLines());
    }

    public function testHttpRedirectPreservesPath(): void
    {
        $request  = new Request('GET', 'http://example.com/a/b/c?q=1&r=2');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(308, $response->status);
        self::assertContains('location: https://example.com/a/b/c?q=1&r=2', $response->toHeaderLines());
    }

    public function testHttpRedirectStripsPort80(): void
    {
        $request  = new Request('GET', 'http://example.com:80/secure-me');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(308, $response->status);
        self::assertContains('location: https://example.com/secure-me', $response->toHeaderLines());
    }

    public function testHttpRedirectStripsPort80WithoutPath(): void
    {
        $request  = new Request('GET', 'http://example.com:80');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(308, $response->status);
        self::assertContains('location: https://example.com', $response->toHeaderLines());
    }

    public function testHttpRedirectPreservesNonStandardPort(): void
    {
        $request  = new Request('GET', 'http://example.com:8080/path');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(308, $response->status);
        self::assertContains('location: https://example.com:8080/path', $response->toHeaderLines());
    }

    // ── inner handler not called on redirect ──────────────────────────────────

    public function testInnerHandlerNotCalledOnRedirect(): void
    {
        $called  = false;
        $request = new Request('POST', 'http://example.com/form');

        $this->mw->process($request, function (Request $r) use (&$called): Response {
            $called = true;

            return Response::text('should not reach');
        });

        self::assertFalse($called);
    }

    // ── a malformed authority must not produce a self-redirect ────────────────

    /**
     * THE REDIRECT LOOP THIS PINS. On a URL parse_url() refuses,
     * Uri::__construct used to collapse the whole thing to "http://localhost/",
     * so uri()->isSecure() answered false on a request that arrived over TLS.
     * This middleware then took the redirect branch and called buildHttpsUrl()
     * with the ORIGINAL string, which already starts with "https://" and is
     * therefore returned unchanged: a 308 to the exact URL just requested.
     *
     * A malformed Host header is not a one-off, it repeats on the next request,
     * so the browser followed that 308 back into the same 308 until it gave up.
     *
     * Fixed at the root: Uri no longer invents "http", isSecure() is true, and
     * the request passes straight through to the handler.
     */
    public function testAMalformedAuthorityOnAnHttpsRequestPassesThroughInsteadOfLooping(): void
    {
        $request  = new Request('GET', 'https://example.com:port/secure');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('handled'));

        self::assertSame(200, $response->status);
        self::assertSame('handled', $response->body);
        self::assertNotContains('location: https://example.com:port/secure', $response->toHeaderLines());
    }

    // ── 308 preserves method semantics ────────────────────────────────────────

    public function testRedirectIs308PermanentForMethodPreservation(): void
    {
        $request  = new Request('DELETE', 'http://example.com/resource/1');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::noContent());

        self::assertSame(308, $response->status);
    }
}
