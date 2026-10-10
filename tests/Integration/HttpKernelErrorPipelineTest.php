<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Core\RequestEvent;
use Zephyrus\Core\ResponseEvent;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Router;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\CsrfConfig;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\CsrfTokenManagerInterface;
use Zephyrus\Security\ForceHttpsMiddleware;
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;
use Zephyrus\Session\SessionManager;
use Zephyrus\Session\SessionMiddleware;

/**
 * Error responses (404, 405, handler exceptions) must pass through the global middleware pipeline,
 * so security middlewares also decorate them.
 */
final class HttpKernelErrorPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        ErrorPipelineTrace::reset();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function errorPathProvider(): array
    {
        return [
            '404 no route matched'        => ['GET', '/does-not-exist', 404],
            '405 wrong method'            => ['POST', '/only-get', 405],
            // Reaches the handler-side catch, not the routing failure: the route is unconstrained,
            // so the segment hits the int parameter.
            '404 parameter type mismatch' => ['GET', '/coerce/not-an-int', 404],
            '500 handler threw'           => ['GET', '/boom', 500],
        ];
    }

    #[DataProvider('errorPathProvider')]
    public function testSecureHeadersMiddlewareDecoratesEveryErrorResponse(
        string $method,
        string $path,
        int $expectedStatus,
    ): void {
        $router = (new Router())
            ->get('/only-get', ErrorPipelinePingController::class . '@ping')
            ->get('/coerce/{id}', ErrorPipelineTypedController::class . '@show')
            ->get('/boom', ErrorPipelineBoomController::class . '@boom');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::fromArray([
                'csp' => "default-src 'self'",
            ])))
            ->build();

        $response = $kernel->handle(Request::fromArray($method, $path));

        self::assertSame($expectedStatus, $response->status);
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
        self::assertSame('nosniff', $response->headers['x-content-type-options']);
        self::assertSame('strict-origin-when-cross-origin', $response->headers['referrer-policy']);
        self::assertSame("default-src 'self'", $response->headers['content-security-policy']);
    }

    public function testMatchedRouteStillCarriesTheSameHeaders(): void
    {
        $router = (new Router())->get('/only-get', ErrorPipelinePingController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/only-get'));

        self::assertSame(200, $response->status);
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
    }

    public function testNotFoundResponseCarriesGlobalMiddlewareDecoration(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new ErrorPipelineHeaderMiddleware('X-Global', 'yes'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('yes', $response->headers['x-global']);
    }

    public function testMethodNotAllowedResponseCarriesGlobalMiddlewareDecoration(): void
    {
        $router = (new Router())->get('/resource', ErrorPipelinePingController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineHeaderMiddleware('X-Global', 'yes'))
            ->build();

        $response = $kernel->handle(Request::fromArray('POST', '/resource'));

        self::assertSame(405, $response->status);
        self::assertSame('yes', $response->headers['x-global']);
        self::assertNotEmpty($response->headers['allow']);
    }

    public function testHandlerThrownExceptionResponseCarriesGlobalMiddlewareDecoration(): void
    {
        $router = (new Router())->get('/boom', ErrorPipelineBoomController::class . '@boom');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineHeaderMiddleware('X-Global', 'yes'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertSame('yes', $response->headers['x-global']);
    }

    public function testGlobalMiddlewareSeesTheErrorStatusItIsDecorating(): void
    {
        $seen = [];

        $observer = new ErrorPipelineStatusObserverMiddleware($seen);

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware($observer)
            ->build();

        $kernel->handle(Request::fromArray('GET', '/missing'));

        // The 404 is a return value, not an exception unwinding past the middleware.
        self::assertSame([404], $seen);
    }

    public function testMiddlewareExecutionOrderIsUnchangedForAMatchedRoute(): void
    {
        $router = (new Router())
            ->get('/ordered', ErrorPipelineTracingController::class . '@act', middlewares: ['first', 'second']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineTraceMiddleware('global-outer'))
            ->withMiddleware(new ErrorPipelineTraceMiddleware('global-inner'))
            ->registerMiddleware('first', new ErrorPipelineTraceMiddleware('route-first'))
            ->registerMiddleware('second', new ErrorPipelineTraceMiddleware('route-second'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/ordered'));

        self::assertSame(200, $response->status);
        self::assertSame([
            'global-outer-before',
            'global-inner-before',
            'route-first-before',
            'route-second-before',
            'handler',
            'route-second-after',
            'route-first-after',
            'global-inner-after',
            'global-outer-after',
        ], ErrorPipelineTrace::entries());
    }

    public function testGlobalMiddlewareStillReceivesRouteParametersOnTheRequest(): void
    {
        $captured = [];

        $router = (new Router())
            ->get('/users/{id}', ErrorPipelineTypedController::class . '@show', ['id' => '\d+']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineAttributeCaptorMiddleware($captured))
            ->build();

        $kernel->handle(Request::fromArray('GET', '/users/42'));

        // Route matching runs before the global pipeline, so route parameters are already on the request
        // a global middleware sees.
        self::assertSame('42', $captured['id'] ?? null);
    }

    public function testRouteMiddlewaresDoNotRunWhenNoRouteMatched(): void
    {
        $router = (new Router())
            ->get('/guarded', ErrorPipelinePingController::class . '@ping', middlewares: ['guard']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineTraceMiddleware('global'))
            ->registerMiddleware('guard', new ErrorPipelineTraceMiddleware('route-guard'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/nowhere'));

        self::assertSame(404, $response->status);
        self::assertSame(['global-before', 'global-after'], ErrorPipelineTrace::entries());
    }

    public function testRouteMiddlewaresDoNotRunOnAMethodNotAllowedResponse(): void
    {
        $router = (new Router())
            ->get('/guarded', ErrorPipelinePingController::class . '@ping', middlewares: ['guard']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineTraceMiddleware('global'))
            ->registerMiddleware('guard', new ErrorPipelineTraceMiddleware('route-guard'))
            ->build();

        $response = $kernel->handle(Request::fromArray('DELETE', '/guarded'));

        self::assertSame(405, $response->status);
        self::assertSame(['global-before', 'global-after'], ErrorPipelineTrace::entries());
    }

    /**
     * Route middlewares do not post-process a response built from a handler exception; only global middlewares do.
     */
    public function testRouteMiddlewaresDoNotPostProcessAHandlerThrownError(): void
    {
        $router = (new Router())
            ->get('/boom', ErrorPipelineBoomController::class . '@boom', middlewares: ['guard']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineTraceMiddleware('global'))
            ->registerMiddleware('guard', new ErrorPipelineTraceMiddleware('route-guard'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        // The exception unwinds past route-guard, so its after-hook is skipped.
        self::assertSame(
            ['global-before', 'route-guard-before', 'global-after'],
            ErrorPipelineTrace::entries(),
        );
    }

    public function testRouteMiddlewareHeaderIsAbsentFromErrorResponses(): void
    {
        $router = (new Router())
            ->get('/guarded', ErrorPipelinePingController::class . '@ping', middlewares: ['stamp']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineHeaderMiddleware('X-Global', 'yes'))
            ->registerMiddleware('stamp', new ErrorPipelineHeaderMiddleware('X-Route', 'yes'))
            ->build();

        $notFound = $kernel->handle(Request::fromArray('GET', '/elsewhere'));
        $matched  = $kernel->handle(Request::fromArray('GET', '/guarded'));

        self::assertArrayNotHasKey('x-route', $notFound->headers);
        self::assertSame('yes', $notFound->headers['x-global']);

        self::assertSame('yes', $matched->headers['x-route']);
        self::assertSame('yes', $matched->headers['x-global']);
    }

    public function testGlobalMiddlewareThatThrowsStillYieldsAResponseWithoutLooping(): void
    {
        $middleware = new ErrorPipelineThrowingMiddleware();

        $router = (new Router())->get('/ping', ErrorPipelinePingController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware($middleware)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame(500, $response->status);
        self::assertSame('Internal Server Error', $response->body);
        // The backstop never re-enters the pipeline, so a middleware that always throws runs once.
        self::assertSame(1, $middleware->calls);
    }

    public function testBackstopAlsoCatchesAGlobalMiddlewareThrowingOnAnErrorPath(): void
    {
        $middleware = new ErrorPipelineThrowingMiddleware();

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware($middleware)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(500, $response->status);
        self::assertSame(1, $middleware->calls);
    }

    /**
     * Known gap, listed in the HttpKernel class docblock: the backstop response is not decorated
     * when a global middleware throws.
     */
    public function testBackstopResponseCarriesNoGlobalDecorations(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            // Registered first, so it is outermost.
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withMiddleware(new ErrorPipelineThrowingMiddleware())
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(500, $response->status);
        self::assertArrayNotHasKey('x-frame-options', $response->headers);
    }

    /**
     * A registered exception handler that throws must not strip the security headers from error responses.
     */
    public function testBrokenExceptionHandlerStillYieldsADecoratedResponse(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withExceptionHandler(
                RouteNotFoundException::class,
                static fn (): Response => throw new RuntimeException('the 404 template is missing'),
            )
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(500, $response->status);
        self::assertSame('Internal Server Error', $response->body);
        // A broken error template must still leave the response decorated.
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
        self::assertSame('nosniff', $response->headers['x-content-type-options']);
    }

    public function testAnExceptionHandlerThatReturnsNullFallsBackToTheBuiltInMapping(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withExceptionHandler(
                RouteNotFoundException::class,
                static fn (\Throwable $e, ?Request $request) => null,
            )
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
    }

    /**
     * A handler that rethrows the same exception must let it through unchanged, so debuggers still render it.
     */
    public function testAnExceptionHandlerThatRethrowsTheOriginalPropagatesOutOfHandle(): void
    {
        $original = new RuntimeException('let the debugger see this');

        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get('/boom', ErrorPipelineBoomController::class . '@boom'))
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withExceptionHandler(
                RuntimeException::class,
                static fn (\Throwable $e) => throw $e,
            )
            ->build();

        $caught = null;

        try {
            $kernel->handle(Request::fromArray('GET', '/boom'));
        } catch (\Throwable $e) {
            $caught = $e;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame('handler exploded', $caught->getMessage());
        // The application sees its own exception, never an internal marker.
        self::assertNotInstanceOf(\Zephyrus\Core\KernelRethrowSignal::class, $caught);
        unset($original);
    }

    public function testARethrownRoutingFailureAlsoPropagates(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withExceptionHandler(
                RouteNotFoundException::class,
                static fn (\Throwable $e) => throw $e,
            )
            ->build();

        $this->expectException(RouteNotFoundException::class);

        $kernel->handle(Request::fromArray('GET', '/missing'));
    }

    /**
     * The rethrow is wrapped in a signal: a bare rethrow would reach the backstop, which runs the responder again.
     */
    public function testADeliberateRethrowInvokesTheHandlerOnceAndReportsOnce(): void
    {
        $handlerCalls = 0;
        $sources = [];

        $events = new EventDispatcher();
        $events->addListener(\Zephyrus\Core\ExceptionEvent::class, static function ($e) use (&$sources): void {
            $sources[] = $e->getSource();
        });

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withEventDispatcher($events)
            ->withExceptionHandler(
                RouteNotFoundException::class,
                static function (\Throwable $e) use (&$handlerCalls) {
                    $handlerCalls++;

                    throw $e;
                },
            )
            ->build();

        try {
            $kernel->handle(Request::fromArray('GET', '/missing'));
        } catch (RouteNotFoundException) {
            // expected
        }

        self::assertSame(1, $handlerCalls, 'the handler must not run twice');
        self::assertSame([\Zephyrus\Core\ExceptionEvent::SOURCE_ROUTING], $sources);
    }

    public function testACatchAllExceptionHandlerThatThrowsDoesNotEscapeTheKernel(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withExceptionHandler(
                \Throwable::class,
                static fn (): Response => throw new RuntimeException('responder always blows up'),
            )
            ->build();

        // An uncaught throwable here would surface as a bare SAPI 500 with no headers.
        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(500, $response->status);
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
    }

    /**
     * Known gap, listed in the HttpKernel class docblock: a ResponseEvent listener runs after the pipeline,
     * so replacing the response drops the decorations.
     */
    public function testResponseEventReplacementDropsGlobalDecorations(): void
    {
        $events = new EventDispatcher();
        $events->addListener(ResponseEvent::class, static function (ResponseEvent $e): void {
            $e->setResponse(Response::text('replaced', 200));
        });

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withEventDispatcher($events)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame('replaced', $response->body);
        self::assertArrayNotHasKey('x-frame-options', $response->headers);
    }

    /**
     * A global middleware's $next() returns a 500 instead of letting a handler exception propagate,
     * so "catch, report, rethrow" middlewares no longer see it.
     */
    public function testGlobalMiddlewareNoLongerObservesAHandlerThrowable(): void
    {
        $observer = new ErrorPipelineCatchObserverMiddleware();

        $router = (new Router())->get('/boom', ErrorPipelineBoomController::class . '@boom');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware($observer)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertFalse($observer->caught, 'the throwable no longer reaches a global middleware');
        self::assertSame(500, $observer->observedStatus, 'it arrives as a returned response instead');
    }

    public function testBackstopStillHonoursRegisteredExceptionHandlers(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new ErrorPipelineThrowingMiddleware())
            ->withExceptionHandler(
                RuntimeException::class,
                static fn (): Response => Response::text('handled by backstop', 503),
            )
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(503, $response->status);
        self::assertSame('handled by backstop', $response->body);
    }

    // Global middlewares also run on unmatched paths: connection checks (HTTPS, Host) answer first,
    // while CSRF passes over them so the 404 or 405 stands.

    /**
     * An unmatched path answers 404, not a CSRF 403: there is no resource to authorise.
     */
    public function testGlobalCsrfMiddlewareDoesNotGateAnUnmatchedRoute(): void
    {
        $router = (new Router())->post('/exists', ErrorPipelinePingController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withMiddleware(new CsrfMiddleware(new ErrorPipelineTokenManager(), new CsrfConfig()))
            ->build();

        $unsafe = $kernel->handle(Request::fromArray('POST', '/no-such-path'));
        $safe   = $kernel->handle(Request::fromArray('GET', '/no-such-path'));

        self::assertSame(404, $unsafe->status, 'a POST to a path that does not exist is a 404');
        self::assertSame(404, $safe->status);
        // Declining to gate must not cost the 404 its decorations.
        self::assertSame('SAMEORIGIN', $unsafe->headers['x-frame-options']);
        self::assertSame('nosniff', $unsafe->headers['x-content-type-options']);
    }

    public function testGlobalCsrfMiddlewareStillRejectsAMatchedRouteWithoutAToken(): void
    {
        $router = (new Router())->post('/exists', ErrorPipelinePingController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new CsrfMiddleware(new ErrorPipelineTokenManager(), new CsrfConfig()))
            ->build();

        $missing = $kernel->handle(Request::fromArray('POST', '/exists'));
        $bad     = $kernel->handle(Request::fromArray('POST', '/exists', body: ['_csrf_token' => 'wrong']));

        // CSRF still applies to matched routes.
        self::assertSame(403, $missing->status);
        self::assertSame(403, $bad->status);
    }

    public function testGlobalCsrfMiddlewareAllowsAMatchedRouteWithAValidToken(): void
    {
        $router = (new Router())->post('/exists', ErrorPipelinePingController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new CsrfMiddleware(new ErrorPipelineTokenManager(), new CsrfConfig()))
            ->build();

        $response = $kernel->handle(Request::fromArray(
            'POST',
            '/exists',
            body: ['_csrf_token' => 'valid-token'],
        ));

        self::assertSame(200, $response->status);
        self::assertSame('pong', $response->body);
    }

    public function testMethodNotAllowedKeepsItsStatusAndDecorationsUnderGlobalCsrf(): void
    {
        $router = (new Router())->get('/only-get', ErrorPipelinePingController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withMiddleware(new CsrfMiddleware(new ErrorPipelineTokenManager(), new CsrfConfig()))
            ->build();

        // No route matched the unsafe DELETE, so the answer is a 405, not a 403.
        $response = $kernel->handle(Request::fromArray('DELETE', '/only-get'));

        self::assertSame(405, $response->status);
        self::assertNotEmpty($response->headers['allow']);
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
    }

    public function testUnmatchedRouteAttributeIsSetOnlyWhenNoRouteMatched(): void
    {
        $captured = [];

        $router = (new Router())->get('/exists', ErrorPipelinePingController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineAttributeCaptorMiddleware($captured))
            ->build();

        $kernel->handle(Request::fromArray('GET', '/nowhere'));
        self::assertTrue($captured[Request::ATTRIBUTE_UNMATCHED_ROUTE] ?? null);

        $kernel->handle(Request::fromArray('GET', '/exists'));
        // Only set on routing failures, never on a matched route.
        self::assertArrayNotHasKey(Request::ATTRIBUTE_UNMATCHED_ROUTE, $captured);
    }

    public function testForceHttpsMiddlewareRedirectsAnUnmatchedPath(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new ForceHttpsMiddleware())
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', 'http://example.com/no-such-path'));

        self::assertSame(308, $response->status);
        self::assertSame('https://example.com/no-such-path', $response->headers['location']);
    }

    public function testAllowedHostsMiddlewareRejectsABadHostOnAnUnmatchedPath(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new AllowedHostsMiddleware(['example.com']))
            ->build();

        $response = $kernel->handle(Request::fromArray(
            'GET',
            'http://evil.test/no-such-path',
            headers: ['Host' => 'evil.test'],
        ));

        self::assertSame(400, $response->status);
        self::assertStringContainsString('Invalid Host header', $response->body);
    }

    /**
     * SessionMiddleware runs on an unmatched route, so a 404 starts a session.
     *
     * The SessionManager uses override storage so no real PHP session is spawned.
     */
    public function testSessionMiddlewareRunsOnAnUnmatchedRoute(): void
    {
        $captured = [];

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new SessionMiddleware(
                SessionConfig::fromArray(['name' => 'ERRORPIPELINE_SESSION']),
                new SessionManager([]),
            ))
            ->withMiddleware(new ErrorPipelineAttributeCaptorMiddleware($captured))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/no-such-path'));

        self::assertSame(404, $response->status);
        // The session attribute is present, so SessionMiddleware ran on a request that matched no route.
        self::assertInstanceOf(SessionManager::class, $captured['session'] ?? null);
    }

    public function testCustomExceptionHandlerResponseIsAlsoDecoratedByGlobalMiddleware(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new ErrorPipelineHeaderMiddleware('X-Global', 'yes'))
            ->withExceptionHandler(
                RouteNotFoundException::class,
                static fn (): Response => Response::text('Custom 404 page', 404),
            )
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('Custom 404 page', $response->body);
        self::assertSame('yes', $response->headers['x-global']);
    }

    public function testRequestEventShortCircuitStillBypassesTheGlobalPipeline(): void
    {
        $events = new EventDispatcher();
        $events->addListener(RequestEvent::class, static function (RequestEvent $e): void {
            $e->setResponse(Response::text('maintenance', status: 503));
        });

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new ErrorPipelineTraceMiddleware('global'))
            ->withEventDispatcher($events)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/anything'));

        self::assertSame(503, $response->status);
        self::assertSame('maintenance', $response->body);
        // Unchanged behaviour: a short-circuit returns before any dispatching.
        self::assertSame([], ErrorPipelineTrace::entries());
    }

    public function testResponseEventStillFiresAfterTheGlobalPipelineOnErrorPaths(): void
    {
        $captured = null;

        $events = new EventDispatcher();
        $events->addListener(ResponseEvent::class, static function (ResponseEvent $e) use (&$captured): void {
            $captured = $e->getResponse()->headers;
        });

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new ErrorPipelineHeaderMiddleware('X-Global', 'yes'))
            ->withEventDispatcher($events)
            ->build();

        $kernel->handle(Request::fromArray('GET', '/missing'));

        // The listener observes the fully decorated error response.
        self::assertSame('yes', $captured['x-global'] ?? null);
    }

    public function testErrorResponseContentNegotiationSurvivesThePipeline(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new ErrorPipelineHeaderMiddleware('X-Global', 'yes'))
            ->build();

        $response = $kernel->handle(Request::fromArray(
            'GET',
            '/missing',
            headers: ['Accept' => 'application/json'],
        ));

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
        self::assertStringContainsString('"status":404', $response->body);
        self::assertSame('yes', $response->headers['x-global']);
    }

    public function testUnknownNamedRouteMiddlewareYieldsADecorated500(): void
    {
        $router = (new Router())
            ->get('/broken', ErrorPipelinePingController::class . '@ping', middlewares: ['nope']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineHeaderMiddleware('X-Global', 'yes'))
            ->registerMiddleware('other', new ErrorPipelineHeaderMiddleware('X-Other', 'yes'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/broken'));

        self::assertSame(500, $response->status);
        self::assertSame('yes', $response->headers['x-global']);
    }

    public function testExceptionHandlerSeesGlobalMiddlewareAttributesButNotRouteMiddlewareAttributes(): void
    {
        $seen = [];
        $router = (new Router())
            ->get('/boom', ErrorPipelineBoomController::class . '@boom', middlewares: ['route.tag']);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new ErrorPipelineAttributeMiddleware('global.tag'))
            ->registerMiddleware('route.tag', new ErrorPipelineAttributeMiddleware('route.tag'))
            ->withExceptionHandler(
                RuntimeException::class,
                function (\Throwable $e, Request $request) use (&$seen): Response {
                    $seen = [
                        'global' => $request->attribute('global.tag'),
                        'route' => $request->attribute('route.tag'),
                    ];

                    return Response::text('handled', 500);
                },
            )
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame('handled', $response->body);
        self::assertSame(['global' => 'set', 'route' => null], $seen);
    }
}

final class ErrorPipelinePingController
{
    public function ping(): Response
    {
        return Response::text('pong');
    }
}

final class ErrorPipelineTypedController
{
    public function show(int $id): Response
    {
        return Response::json(['id' => $id]);
    }
}

final class ErrorPipelineBoomController
{
    public function boom(): Response
    {
        throw new RuntimeException('handler exploded');
    }
}

final class ErrorPipelineTracingController
{
    public function act(): Response
    {
        ErrorPipelineTrace::record('handler');

        return Response::text('ok');
    }
}

final class ErrorPipelineHeaderMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $value,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeader($this->name, $this->value);
    }
}

/**
 * Shared execution recorder, so middlewares and the handler write to one trace.
 */
final class ErrorPipelineTrace
{
    /** @var list<string> */
    private static array $entries = [];

    public static function reset(): void
    {
        self::$entries = [];
    }

    public static function record(string $entry): void
    {
        self::$entries[] = $entry;
    }

    /** @return list<string> */
    public static function entries(): array
    {
        return self::$entries;
    }
}

/**
 * Records "<label>-before" on the way in and "<label>-after" on the way out, so
 * a single trace shows the complete onion including the handler.
 */
final class ErrorPipelineTraceMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $label)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        ErrorPipelineTrace::record($this->label . '-before');
        $response = $next($request);
        ErrorPipelineTrace::record($this->label . '-after');

        return $response;
    }
}

/**
 * The "unit of work / error reporting" middleware shape: catch, report, rethrow.
 * Records whether the throwable ever reached it.
 */
final class ErrorPipelineCatchObserverMiddleware implements MiddlewareInterface
{
    public bool $caught = false;
    public ?int $observedStatus = null;

    public function process(Request $request, callable $next): Response
    {
        try {
            $response = $next($request);
            $this->observedStatus = $response->status;

            return $response;
        } catch (\Throwable $exception) {
            $this->caught = true;

            throw $exception;
        }
    }
}

final class ErrorPipelineStatusObserverMiddleware implements MiddlewareInterface
{
    /** @param list<int> $seen */
    public function __construct(private array &$seen)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $response = $next($request);
        $this->seen[] = $response->status;

        return $response;
    }
}

final class ErrorPipelineAttributeCaptorMiddleware implements MiddlewareInterface
{
    /** @param array<string, mixed> $captured */
    public function __construct(private array &$captured)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        $this->captured = $request->attributes;

        return $next($request);
    }
}

/** Accepts exactly one token, so an absent token is invalid. */
final class ErrorPipelineTokenManager implements CsrfTokenManagerInterface
{
    public function getToken(): string
    {
        return 'valid-token';
    }

    public function isTokenValid(string $token): bool
    {
        return $token === 'valid-token';
    }
}

final class ErrorPipelineAttributeMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $key)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        return $next($request->withAttribute($this->key, 'set'));
    }
}

final class ErrorPipelineThrowingMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    public function process(Request $request, callable $next): Response
    {
        $this->calls++;

        throw new RuntimeException('global middleware exploded');
    }
}
