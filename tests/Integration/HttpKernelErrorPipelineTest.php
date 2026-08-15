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
 * Error responses must leave the kernel through the global middleware pipeline.
 *
 * Before the pipeline wrapped the error responder, a 404, a 405 and a handler
 * exception were all answered outside it, so SecureHeadersMiddleware,
 * ContentSecurityPolicyMiddleware, AllowedHostsMiddleware and
 * ForceHttpsMiddleware were silently inert on exactly the responses an attacker
 * probes most. These tests pin the fixed behaviour.
 */
final class HttpKernelErrorPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        ErrorPipelineTrace::reset();
    }

    // -- The real security middleware on the real error paths -----------------

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function errorPathProvider(): array
    {
        return [
            '404 no route matched'        => ['GET', '/does-not-exist', 404],
            '405 wrong method'            => ['POST', '/only-get', 405],
            // Reaches RouteParameterException from HandlerResolver, i.e. the
            // catch INSIDE dispatchMatchedRoute rather than the deferred
            // routing failure. The route must be unconstrained for the bad
            // segment to reach the handler's int parameter at all.
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

    // -- 404 / 405 / 500 through a plain global middleware --------------------

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
        // The Allow header from the responder survives the pipeline.
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

        // The middleware receives the 404 as a normal return value, not as an
        // exception unwinding past it.
        self::assertSame([404], $seen);
    }

    // -- Ordering is unchanged -------------------------------------------------

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

        // Route matching runs before the global pipeline precisely so this
        // keeps working.
        self::assertSame('42', $captured['id'] ?? null);
    }

    // -- Route middlewares and the error paths --------------------------------

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
        // No route matched, so the route has no middlewares to run. Only the
        // global middleware wrapped this response.
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
     * Documents the deliberate choice: route middlewares do NOT post-process a
     * response built from a handler exception. They start, the handler throws,
     * and the exception unwinds past them before it is converted. Only global
     * middlewares decorate error responses, on every error path, uniformly.
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
        // route-guard-before ran, route-guard-after did not: the throw unwound
        // past it. The global middleware still decorated the error response.
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

    // -- The backstop ----------------------------------------------------------

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
        // The backstop converts directly and never re-enters the pipeline, so a
        // middleware that always throws runs exactly once.
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
     * Pins documented limit 2: the backstop response is NOT decorated. When a
     * global middleware throws, the throw has already unwound past the
     * post-processing of every middleware outside it, so nothing can decorate
     * the result. This is a real gap, not an oversight, and the class docblock
     * says so rather than claiming every response carries the headers.
     */
    public function testBackstopResponseCarriesNoGlobalDecorations(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            // Registered FIRST, so it is outermost and would normally decorate.
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withMiddleware(new ErrorPipelineThrowingMiddleware())
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(500, $response->status);
        self::assertArrayNotHasKey('x-frame-options', $response->headers);
    }

    /**
     * Pins the guard in HttpKernel::toErrorResponse(). A registered exception
     * handler that throws (a missing error-page template is the usual cause)
     * must NOT strip the security headers from every error response. Without
     * the guard the second throwable escapes the destination closure, unwinds
     * past the global middlewares, and lands undecorated in the backstop.
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
        // The decoration is the point: a broken error template must not strip
        // the security headers from the site's error responses.
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
        self::assertSame('nosniff', $response->headers['x-content-type-options']);
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

        // Previously this escaped handle() as an uncaught throwable, which in
        // production is a bare SAPI 500 with no headers and no body control.
        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(500, $response->status);
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
    }

    /**
     * Pins documented limit 3: a ResponseEvent listener runs after the pipeline,
     * so a listener that replaces the response wholesale drops the decorations.
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
     * Pins the behaviour change that matters most for error reporting: a global
     * middleware's $next() now RETURNS a 500 where it previously let the
     * exception propagate. A middleware doing "catch, report, rethrow" stops
     * seeing handler exceptions, which is a silent failure, so it is pinned
     * here and called out in the HttpKernel docblock.
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

    // -- Short-circuiting global middlewares now answer unmatched paths -------
    //
    // These pin the documented behaviour change: a global middleware that
    // returns without calling $next can now respond before the 404 is built, so
    // the status code on those paths changes. Each stops an unauthenticated
    // prober from learning which routes exist.

    /**
     * CSRF asks whether a state change to a RESOURCE is authorised. When no
     * route matched there is no resource, so the honest answer is 404, not a
     * security-shaped 403 that sends whoever debugs it hunting a token problem
     * when the URL is simply wrong. A stale webhook posting to a renamed
     * endpoint is the case that costs real time.
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

        // CSRF is NOT weakened on routes that actually exist.
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

        // DELETE is unsafe and carries no token, but no route matched it, so
        // the answer is the 405 rather than a 403.
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
        // Never set on a matched route: HandlerResolver injects handler
        // arguments positionally from $request->attributes, so a stray entry
        // there would shift that binding.
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

    // -- The session behaviour change, pinned --------------------------------

    /**
     * The commit message and the SessionMiddleware docblock both claim a 404
     * now starts a session. That claim is load-bearing for the five projects
     * tracking this framework, so it is pinned rather than only documented.
     *
     * A SessionManager in override-storage mode is used so the test never
     * spawns a real PHP session; what is being proven is that the middleware
     * RUNS on an unmatched route, which is the behaviour change.
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
        // The session attribute is present, so SessionMiddleware ran and called
        // start() on a request that matched no route. Before the pipeline
        // wrapped error responses it did not run at all here.
        self::assertInstanceOf(SessionManager::class, $captured['session'] ?? null);
    }

    // -- Interaction with the rest of the kernel ------------------------------

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
}

// ===========================================================================
// Fixture controllers
// ===========================================================================

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

// ===========================================================================
// Fixture middleware
// ===========================================================================

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

final class ErrorPipelineThrowingMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    public function process(Request $request, callable $next): Response
    {
        $this->calls++;

        throw new RuntimeException('global middleware exploded');
    }
}
