<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\CsrfConfig;
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

    private function makeMiddleware(?CsrfConfig $config = null): CsrfMiddleware
    {
        return new CsrfMiddleware($this->makeManager(), $config ?? CsrfConfig::defaults());
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

    // ── custom field / header names via CsrfConfig ───────────────────────────

    public function testCustomBodyFieldIsRespected(): void
    {
        $config  = new CsrfConfig(bodyField: '_token');
        $mw      = new CsrfMiddleware($this->makeManager(), $config);
        $request = new Request('POST', 'https://example.com/submit', parsedBody: [
            '_token' => self::VALID_TOKEN,
        ]);
        $response = $mw->process($request, fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testCustomHeaderNameIsRespected(): void
    {
        $config  = new CsrfConfig(headerName: 'X-XSRF-TOKEN');
        $mw      = new CsrfMiddleware($this->makeManager(), $config);
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

    // ── path exclusions bypass CSRF validation ───────────────────────────────

    public function testExcludedPathBypassesTokenValidationOnPost(): void
    {
        $config  = new CsrfConfig(excludedPathPatterns: ['#^/webhooks/#']);
        $mw      = new CsrfMiddleware($this->makeManager(), $config);
        // No token supplied — but path is excluded → inner handler must be called.
        $request = new Request('POST', 'https://example.com/webhooks/stripe');
        $inner   = Response::text('webhook-received');

        $response = $mw->process($request, fn (Request $r): Response => $inner);

        self::assertSame(200, $response->status);
    }

    public function testExcludedPathBypassesTokenValidationOnDelete(): void
    {
        $config  = new CsrfConfig(excludedPathPatterns: ['#^/api/v\d+/public#']);
        $mw      = new CsrfMiddleware($this->makeManager(), $config);
        $request = new Request('DELETE', 'https://example.com/api/v2/public/items/99');

        $response = $mw->process($request, fn (Request $r): Response => Response::noContent());

        self::assertSame(204, $response->status);
    }

    public function testNonExcludedPathStillRequiresToken(): void
    {
        $config  = new CsrfConfig(excludedPathPatterns: ['#^/webhooks/#']);
        $mw      = new CsrfMiddleware($this->makeManager(), $config);
        // Path does NOT match the exclusion → 403 when no token.
        $request = new Request('POST', 'https://example.com/submit');

        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
    }

    public function testMultipleExclusionPatternsFirstMatchWins(): void
    {
        $config = new CsrfConfig(excludedPathPatterns: [
            '#^/health$#',
            '#^/webhooks/#',
        ]);
        $mw = new CsrfMiddleware($this->makeManager(), $config);

        // Matches second pattern.
        $request  = new Request('POST', 'https://example.com/webhooks/github');
        $response = $mw->process($request, fn (Request $r): Response => Response::text('ok'));
        self::assertSame(200, $response->status);

        // Matches first pattern.
        $request  = new Request('POST', 'https://example.com/health');
        $response = $mw->process($request, fn (Request $r): Response => Response::text('ok'));
        self::assertSame(200, $response->status);
    }

    public function testExclusionPatternsDoNotAffectSafeMethodsAlreadyAllowed(): void
    {
        // GET is already safe; exclusion patterns are redundant but must not break anything.
        $config  = new CsrfConfig(excludedPathPatterns: ['#^/webhooks/#']);
        $mw      = new CsrfMiddleware($this->makeManager(), $config);
        $request = new Request('GET', 'https://example.com/webhooks/stripe');

        $response = $mw->process($request, fn (Request $r): Response => Response::text('ok'));

        self::assertSame(200, $response->status);
    }

    public function testExcludedPathInnerHandlerIsCalledWithOriginalRequest(): void
    {
        $config   = new CsrfConfig(excludedPathPatterns: ['#^/hooks/#']);
        $mw       = new CsrfMiddleware($this->makeManager(), $config);
        $request  = new Request('PUT', 'https://example.com/hooks/deploy', parsedBody: ['ref' => 'main']);
        $received = null;

        $mw->process($request, function (Request $r) use (&$received): Response {
            $received = $r;

            return Response::text('ok');
        });

        self::assertSame($request, $received);
    }

    // ── CsrfConfig factory tests ──────────────────────────────────────────────

    public function testCsrfConfigDefaults(): void
    {
        $config = CsrfConfig::defaults();

        self::assertSame('_csrf_token', $config->bodyField);
        self::assertSame('X-CSRF-Token', $config->headerName);
        self::assertSame([], $config->excludedPathPatterns);
    }

    public function testCsrfConfigFromArraySnakeCase(): void
    {
        $config = CsrfConfig::fromArray([
            'body_field'             => '_token',
            'header_name'            => 'X-XSRF-TOKEN',
            'excluded_path_patterns' => ['#^/api/#'],
        ]);

        self::assertSame('_token', $config->bodyField);
        self::assertSame('X-XSRF-TOKEN', $config->headerName);
        self::assertSame(['#^/api/#'], $config->excludedPathPatterns);
    }

    public function testCsrfConfigFromArrayCamelCase(): void
    {
        $config = CsrfConfig::fromArray([
            'bodyField'             => '_token',
            'headerName'            => 'X-XSRF-TOKEN',
            'excludedPathPatterns'  => ['#^/hooks/#'],
        ]);

        self::assertSame('_token', $config->bodyField);
        self::assertSame('X-XSRF-TOKEN', $config->headerName);
        self::assertSame(['#^/hooks/#'], $config->excludedPathPatterns);
    }

    public function testCsrfConfigFromArraySnakeCaseTakesPriorityOverCamelCase(): void
    {
        $config = CsrfConfig::fromArray([
            'body_field' => 'snake-wins',   // snake wins
            'bodyField'  => 'camel-loses',
        ]);

        self::assertSame('snake-wins', $config->bodyField);
    }

    public function testCsrfConfigFromArrayEmptyUsesDefaults(): void
    {
        $config = CsrfConfig::fromArray([]);

        self::assertSame('_csrf_token', $config->bodyField);
        self::assertSame('X-CSRF-Token', $config->headerName);
        self::assertSame([], $config->excludedPathPatterns);
    }

    // ── middleware defaults to CsrfConfig::defaults() when omitted ───────────

    public function testMiddlewareWithDefaultConfigConstructor(): void
    {
        // When no CsrfConfig is passed, defaults apply; POST without token → 403.
        $mw      = new CsrfMiddleware($this->makeManager());
        $request = new Request('POST', 'https://example.com/submit');
        $response = $mw->process($request, fn (Request $r): Response => Response::text('never'));

        self::assertSame(403, $response->status);
    }
}
