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
        $request  = new Request('POST', 'https://example.com/submit', parsedBody: ['x' => '1']);
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
        self::assertStringContainsString('Location', implode("\n", $response->toHeaderLines()));
        self::assertContains('Location: https://example.com/page', $response->toHeaderLines());
    }

    public function testHttpRedirectPreservesPath(): void
    {
        $request  = new Request('GET', 'http://example.com/a/b/c?q=1&r=2');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(308, $response->status);
        self::assertContains('Location: https://example.com/a/b/c?q=1&r=2', $response->toHeaderLines());
    }

    public function testHttpRedirectStripsPort80(): void
    {
        $request  = new Request('GET', 'http://example.com:80/secure-me');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(308, $response->status);
        self::assertContains('Location: https://example.com/secure-me', $response->toHeaderLines());
    }

    public function testHttpRedirectPreservesNonStandardPort(): void
    {
        $request  = new Request('GET', 'http://example.com:8080/path');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(308, $response->status);
        self::assertContains('Location: https://example.com:8080/path', $response->toHeaderLines());
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

    // ── 308 preserves method semantics ────────────────────────────────────────

    public function testRedirectIs308PermanentForMethodPreservation(): void
    {
        $request  = new Request('DELETE', 'http://example.com/resource/1');
        $response = $this->mw->process($request, fn (Request $r): Response => Response::noContent());

        self::assertSame(308, $response->status);
    }
}
