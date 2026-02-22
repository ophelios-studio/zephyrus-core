<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\CsrfTokenManagerInterface;

final class CsrfMiddlewareTest extends TestCase
{
    private const VALID_TOKEN = 'valid-csrf-token-abc123';

    // Minimal in-memory token manager for unit tests.
    private function makeManager(string $token = self::VALID_TOKEN): CsrfTokenManagerInterface
    {
        return new class($token) implements CsrfTokenManagerInterface {
            public function __construct(private readonly string $token) {}

            public function getToken(): string
            {
                return $this->token;
            }

            public function isTokenValid(string $submitted): bool
            {
                return hash_equals($this->token, $submitted);
            }
        };
    }

    private function makeMiddleware(?string $bodyField = null, ?string $headerName = null): CsrfMiddleware
    {
        $args = [$this->makeManager()];

        if ($bodyField !== null) {
            $args[] = $bodyField;
        }

        if ($headerName !== null) {
            $args[] = $headerName;
        }

        return new CsrfMiddleware(...$args);
    }

    // ── safe methods pass through without token ───────────────────────────────

    public function testGetPassesThroughWithoutToken(): void
    {
        $mw       = $this->makeMiddleware();
        $request  = new Request('GET', 'https://example.com/page');
        $inner    = Response::text('ok');
        $response = $mw->process($request, fn (Request $r): Response => $inner);

        self::assertSame(200, $response->status);
    }

    public function testHeadPassesThroughWithoutToken(): void
    {
        $mw       = $this->makeMiddleware();
        $request  = new Request('HEAD', 'https://example.com/page');
        $inner    = Response::noContent();
        $response = $mw->process($request, fn (Request $r): Response => $inner);

        self::assertSame(204, $response->status);
    }

    public function testOptionsPassesThroughWithoutToken(): void
    {
        $mw       = $this->makeMiddleware();
        $request  = new Request('OPTIONS', 'https://example.com/page');
        $inner    = Response::noContent();
        $response = $mw->process($request, fn (Request $r): Response => $inner);

        self::assertSame(204, $response->status);
    }

    public function testTracePassesThroughWithoutToken(): void
    {
        $mw       = $this->makeMiddleware();
        $request  = new Request('TRACE', 'https://example.com/page');
        $inner    = Response::text('trace-ok');
        $response = $mw->process($request, fn (Request $r): Response => $inner);

        self::assertSame(200, $response->status);
    }

    // ── state-changing methods without token return 403 ──────────────────────

    public function testPostWithoutTokenReturnsForbidden(): void
    {
        $mw       = $this->makeMiddleware();
        $request  = new Request('POST', 'https://example.com/submit');
        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
    }

    public function testPutWithoutTokenReturnsForbidden(): void
    {
        $mw       = $this->makeMiddleware();
        $request  = new Request('PUT', 'https://example.com/resource/1');
        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
    }

    public function testPatchWithoutTokenReturnsForbidden(): void
    {
        $mw       = $this->makeMiddleware();
        $request  = new Request('PATCH', 'https://example.com/resource/1');
        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
    }

    public function testDeleteWithoutTokenReturnsForbidden(): void
    {
        $mw       = $this->makeMiddleware();
        $request  = new Request('DELETE', 'https://example.com/resource/1');
        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
    }

    // ── valid token via request body ──────────────────────────────────────────

    public function testPostWithValidBodyTokenPasses(): void
    {
        $mw      = $this->makeMiddleware();
        $request = new Request('POST', 'https://example.com/submit', parsedBody: [
            '_csrf_token' => self::VALID_TOKEN,
            'name'        => 'Alice',
        ]);
        $inner    = Response::text('success');
        $response = $mw->process($request, fn (Request $r): Response => $inner);

        self::assertSame(200, $response->status);
    }

    public function testPutWithValidBodyTokenPasses(): void
    {
        $mw      = $this->makeMiddleware();
        $request = new Request('PUT', 'https://example.com/resource/1', parsedBody: [
            '_csrf_token' => self::VALID_TOKEN,
        ]);
        $response = $mw->process($request, fn (Request $r): Response => Response::text('updated'));

        self::assertSame(200, $response->status);
    }

    // ── valid token via header ────────────────────────────────────────────────

    public function testPostWithValidHeaderTokenPasses(): void
    {
        $mw      = $this->makeMiddleware();
        $request = new Request('POST', 'https://example.com/api/action', headers: [
            'x-csrf-token' => self::VALID_TOKEN,
        ]);
        $response = $mw->process($request, fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testDeleteWithValidHeaderTokenPasses(): void
    {
        $mw      = $this->makeMiddleware();
        $request = new Request('DELETE', 'https://example.com/resource/5', headers: [
            'x-csrf-token' => self::VALID_TOKEN,
        ]);
        $response = $mw->process($request, fn (Request $r): Response => Response::noContent());

        self::assertSame(204, $response->status);
    }

    // ── wrong token returns 403 ───────────────────────────────────────────────

    public function testPostWithWrongBodyTokenReturnsForbidden(): void
    {
        $mw      = $this->makeMiddleware();
        $request = new Request('POST', 'https://example.com/submit', parsedBody: [
            '_csrf_token' => 'bad-token',
        ]);
        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
    }

    public function testPostWithWrongHeaderTokenReturnsForbidden(): void
    {
        $mw      = $this->makeMiddleware();
        $request = new Request('POST', 'https://example.com/api/action', headers: [
            'x-csrf-token' => 'tampered',
        ]);
        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
    }

    // ── body field takes precedence over header ───────────────────────────────

    public function testBodyFieldTakesPrecedenceOverHeader(): void
    {
        $mw      = $this->makeMiddleware();
        $request = new Request(
            'POST',
            'https://example.com/submit',
            parsedBody: ['_csrf_token' => self::VALID_TOKEN],  // body: valid
            headers:    ['x-csrf-token' => 'bad-header'],       // header: bad
        );
        // Body wins → should pass
        $response = $mw->process($request, fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    // ── custom field / header names ───────────────────────────────────────────

    public function testCustomBodyFieldIsRespected(): void
    {
        $mw      = new CsrfMiddleware($this->makeManager(), bodyField: '_token');
        $request = new Request('POST', 'https://example.com/submit', parsedBody: [
            '_token' => self::VALID_TOKEN,
        ]);
        $response = $mw->process($request, fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testCustomHeaderNameIsRespected(): void
    {
        $mw      = new CsrfMiddleware($this->makeManager(), headerName: 'X-XSRF-TOKEN');
        $request = new Request('POST', 'https://example.com/api', headers: [
            'x-xsrf-token' => self::VALID_TOKEN,
        ]);
        $response = $mw->process($request, fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    // ── 403 response is JSON ──────────────────────────────────────────────────

    public function testForbiddenResponseIsJson(): void
    {
        $mw      = $this->makeMiddleware();
        $request = new Request('POST', 'https://example.com/submit');
        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
        self::assertStringContainsString('application/json', $response->headers['Content-Type'] ?? '');
        self::assertStringContainsString('error', $response->body);
    }

    // ── inner handler not called on rejection ─────────────────────────────────

    public function testInnerHandlerNotCalledOnRejection(): void
    {
        $called  = false;
        $mw      = $this->makeMiddleware();
        $request = new Request('POST', 'https://example.com/submit');

        $mw->process($request, function (Request $r) use (&$called): Response {
            $called = true;

            return Response::text('unreachable');
        });

        self::assertFalse($called);
    }

    // ── getToken() accessible from manager ───────────────────────────────────

    public function testManagerExposesToken(): void
    {
        $manager = $this->makeManager('my-test-token');

        self::assertSame('my-test-token', $manager->getToken());
    }
}
