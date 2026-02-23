<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Zephyrus\Controller\Controller;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\HandlerResolverException;
use Zephyrus\Routing\HandlerResolver;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteMatch;

// ---------------------------------------------------------------------------
// Fixture controllers used only in this test file
// ---------------------------------------------------------------------------

/**
 * A plain POPO controller (no base class) — resolver should work regardless.
 */
final class PlainHandlerController
{
    public function hello(): Response
    {
        return Response::text('hello');
    }

    public function withRequest(Request $request): Response
    {
        return Response::text($request->path());
    }

    public function withIntParam(int $id): Response
    {
        return Response::json(['id' => $id]);
    }

    public function withFloatParam(float $score): Response
    {
        return Response::json(['score' => $score]);
    }

    public function withStringParam(string $slug): Response
    {
        return Response::text($slug);
    }

    public function withDefault(string $format = 'json'): Response
    {
        return Response::text($format);
    }

    public function withMixed(Request $request, int $id): Response
    {
        return Response::json(['path' => $request->path(), 'id' => $id]);
    }

    public function withNullable(?int $id): Response
    {
        return Response::json(['id' => $id]);
    }

    public function withUnion(int|string $key): Response
    {
        return Response::json(['key' => $key]);
    }

    public function withBool(bool $active): Response
    {
        return Response::json(['active' => $active]);
    }

    public function withRequestUnion(Request|string $payload): Response
    {
        return Response::text($payload instanceof Request ? 'request' : $payload);
    }
}

final class NonInstantiableController
{
    public function __construct(string $required)
    {
    }

    public function act(): Response
    {
        return Response::text('never');
    }
}

/**
 * A controller with a method whose parameter cannot be resolved.
 * (Not Request-typed, has no matching attribute, and no default.)
 */
final class UnresolvableParamController
{
    public function act(string $missing): Response
    {
        return Response::text($missing);
    }
}

/**
 * Controller that guards via before(): returns 403 when request carries
 * X-Block header, otherwise null.
 */
final class BeforeGuardController extends Controller
{
    public bool $handlerInvoked = false;

    public function before(Request $request): ?Response
    {
        if ($request->header('X-Block') === '1') {
            return Response::text('blocked', 403);
        }

        return null;
    }

    public function act(): Response
    {
        $this->handlerInvoked = true;

        return Response::text('ok');
    }
}

/**
 * Controller that decorates responses via after(): adds X-After header.
 */
final class AfterDecoratorController extends Controller
{
    public function after(Request $request, Response $response): Response
    {
        return $response->withHeader('X-After', 'decorated');
    }

    public function act(): Response
    {
        return Response::text('result');
    }
}

/**
 * Controller with both hooks: before blocks on X-Block, after stamps X-After.
 */
final class BothLifecycleController extends Controller
{
    public function before(Request $request): ?Response
    {
        if ($request->header('X-Block') === '1') {
            return Response::text('halted', 401);
        }

        return null;
    }

    public function after(Request $request, Response $response): Response
    {
        return $response->withHeader('X-After', 'yes');
    }

    public function act(): Response
    {
        return Response::text('dispatched');
    }
}

/**
 * A Controller subclass that uses the base-class response helpers.
 */
final class ExtendedHandlerController extends Controller
{
    public function index(): Response
    {
        return $this->json(['ok' => true]);
    }

    public function show(int $id): Response
    {
        return $this->json(['id' => $id]);
    }

    public function store(Request $request): Response
    {
        return $this->created(['name' => $request->input('name')]);
    }
}

// ---------------------------------------------------------------------------

final class HandlerResolverTest extends TestCase
{
    private HandlerResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new HandlerResolver();
    }

    // -- Basic dispatch -------------------------------------------------------

    public function testResolvesPlainMethodWithNoParams(): void
    {
        $match = $this->makeMatch('GET', '/hello', PlainHandlerController::class . '@hello');

        $response = $this->resolver->resolve($match, Request::fromArray('GET', '/hello'));

        self::assertSame(200, $response->status);
        self::assertSame('hello', $response->body);
    }

    // -- Request injection ----------------------------------------------------

    public function testInjectsRequestByTypeHint(): void
    {
        $match = $this->makeMatch('GET', '/path', PlainHandlerController::class . '@withRequest');

        $response = $this->resolver->resolve($match, Request::fromArray('GET', '/path'));

        self::assertSame('/path', $response->body);
    }

    // -- Scalar parameter injection -------------------------------------------

    public function testInjectsIntAttributeFromRouteParam(): void
    {
        $match = $this->makeMatch('GET', '/items/{id}', PlainHandlerController::class . '@withIntParam', ['id' => '42']);

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/items/42')->withAttribute('id', '42'),
        );

        self::assertStringContainsString('"id":42', $response->body);
    }

    public function testInjectsFloatAttributeFromRouteParam(): void
    {
        $match = $this->makeMatch('GET', '/scores/{score}', PlainHandlerController::class . '@withFloatParam', ['score' => '9.5']);

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/scores/9.5')->withAttribute('score', '9.5'),
        );

        self::assertStringContainsString('"score":9.5', $response->body);
    }

    public function testInjectsStringAttributeFromRouteParam(): void
    {
        $match = $this->makeMatch('GET', '/posts/{slug}', PlainHandlerController::class . '@withStringParam', ['slug' => 'hello-world']);

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/posts/hello-world')->withAttribute('slug', 'hello-world'),
        );

        self::assertSame('hello-world', $response->body);
    }

    public function testFallsBackToDefaultValueWhenAttributeAbsent(): void
    {
        $match = $this->makeMatch('GET', '/export', PlainHandlerController::class . '@withDefault');

        $response = $this->resolver->resolve($match, Request::fromArray('GET', '/export'));

        self::assertSame('json', $response->body);
    }

    public function testInjectsMixedRequestAndScalarParam(): void
    {
        $match = $this->makeMatch('GET', '/users/{id}', PlainHandlerController::class . '@withMixed', ['id' => '7']);

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/users/7')->withAttribute('id', '7'),
        );

        self::assertStringContainsString('"path":"\/users\/7"', $response->body);
        self::assertStringContainsString('"id":7', $response->body);
    }

    public function testInjectsNullForNullableAttribute(): void
    {
        $match = $this->makeMatch('GET', '/users/{id}', PlainHandlerController::class . '@withNullable');

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/users/null')->withAttribute('id', null),
        );

        self::assertStringContainsString('"id":null', $response->body);
    }

    public function testInjectsUnionTypeFromStringAttribute(): void
    {
        $match = $this->makeMatch('GET', '/keys/{key}', PlainHandlerController::class . '@withUnion');

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/keys/abc')->withAttribute('key', 'abc'),
        );

        self::assertStringContainsString('"key":"abc"', $response->body);
    }

    public function testInjectsBoolFromStringAttribute(): void
    {
        $match = $this->makeMatch('GET', '/flags/{active}', PlainHandlerController::class . '@withBool');

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/flags/true')->withAttribute('active', 'true'),
        );

        self::assertStringContainsString('"active":true', $response->body);
    }

    public function testRequestInjectionWinsForRequestUnionType(): void
    {
        $match = $this->makeMatch('GET', '/payload/{payload}', PlainHandlerController::class . '@withRequestUnion');

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/payload/value')->withAttribute('payload', 'value'),
        );

        self::assertSame('request', $response->body);
    }

    // -- Controller subclass --------------------------------------------------

    public function testExtendedControllerIndexReturnsJson(): void
    {
        $match = $this->makeMatch('GET', '/items', ExtendedHandlerController::class . '@index');

        $response = $this->resolver->resolve($match, Request::fromArray('GET', '/items'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"ok":true', $response->body);
    }

    public function testExtendedControllerShowInjectsIntParam(): void
    {
        $match = $this->makeMatch('GET', '/items/{id}', ExtendedHandlerController::class . '@show', ['id' => '5']);

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/items/5')->withAttribute('id', '5'),
        );

        self::assertStringContainsString('"id":5', $response->body);
    }

    public function testExtendedControllerStoreInjectsRequest(): void
    {
        $match = $this->makeMatch('POST', '/items', ExtendedHandlerController::class . '@store');

        $response = $this->resolver->resolve(
            $match,
            Request::fromArray('POST', '/items', parsedBody: ['name' => 'Widget']),
        );

        self::assertSame(201, $response->status);
        self::assertStringContainsString('"name":"Widget"', $response->body);
    }

    // -- Custom factory -------------------------------------------------------

    public function testCustomFactoryIsUsedToInstantiateController(): void
    {
        $factoryCallCount = 0;

        $resolver = new HandlerResolver(function (string $class) use (&$factoryCallCount): object {
            $factoryCallCount++;

            return new $class();
        });

        $match = $this->makeMatch('GET', '/hello', PlainHandlerController::class . '@hello');

        $resolver->resolve($match, Request::fromArray('GET', '/hello'));

        self::assertSame(1, $factoryCallCount);
    }

    public function testUnresolvableClassThrowsWrappedException(): void
    {
        $this->expectException(HandlerResolverException::class);
        $this->expectExceptionMessageMatches('/Cannot instantiate handler class/');

        $match = $this->makeMatch('GET', '/act', NonInstantiableController::class . '@act');

        $this->resolver->resolve($match, Request::fromArray('GET', '/act'));
    }

    // -- Error cases ----------------------------------------------------------

    public function testInvalidHandlerFormatThrows(): void
    {
        $this->expectException(HandlerResolverException::class);
        $this->expectExceptionMessageMatches('/not a valid ClassName@method/');

        $match = $this->makeMatch('GET', '/', 'NoAtSign');

        $this->resolver->resolve($match, Request::fromArray('GET', '/'));
    }

    public function testInvalidHandlerFormatThrowsWhenClassOrMethodIsMissing(): void
    {
        $this->expectException(HandlerResolverException::class);
        $this->expectExceptionMessageMatches('/not a valid ClassName@method/');

        $match = $this->makeMatch('GET', '/', '@methodOnly');
        $this->resolver->resolve($match, Request::fromArray('GET', '/'));
    }

    public function testUnresolvableMethodThrows(): void
    {
        $this->expectException(HandlerResolverException::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        $match = $this->makeMatch('GET', '/', PlainHandlerController::class . '@nonexistent');

        $this->resolver->resolve($match, Request::fromArray('GET', '/'));
    }

    public function testUnresolvedParameterThrows(): void
    {
        $this->expectException(HandlerResolverException::class);
        $this->expectExceptionMessageMatches('/Cannot resolve parameter/');

        $match = $this->makeMatch('GET', '/', UnresolvableParamController::class . '@act');

        // Request has no 'missing' attribute and method has no default → must throw.
        $this->resolver->resolve($match, Request::fromArray('GET', '/'));
    }

    public function testInvalidScalarValueThrowsExplicitTypeError(): void
    {
        $this->expectException(HandlerResolverException::class);
        $this->expectExceptionMessageMatches('/expected bool, got string/');

        $match = $this->makeMatch('GET', '/flags/{active}', PlainHandlerController::class . '@withBool');

        $this->resolver->resolve(
            $match,
            Request::fromArray('GET', '/flags/not-bool')->withAttribute('active', 'not-bool'),
        );
    }

    // -- Lifecycle hooks (before / after) -------------------------------------

    public function testPlainPopoControllerHasNoLifecycleHooks(): void
    {
        // PlainHandlerController has no before/after — resolver must not error.
        $match    = $this->makeMatch('GET', '/hello', PlainHandlerController::class . '@hello');
        $response = $this->resolver->resolve($match, Request::fromArray('GET', '/hello'));

        self::assertSame('hello', $response->body);
    }

    public function testBeforeHookShortCircuitsDispatchWhenNonNull(): void
    {
        $match   = $this->makeMatch('GET', '/act', BeforeGuardController::class . '@act');
        $request = Request::fromArray('GET', '/act', headers: ['X-Block' => '1']);

        $response = $this->resolver->resolve($match, $request);

        // Handler method must NOT have been called.
        self::assertSame(403, $response->status);
        self::assertSame('blocked', $response->body);
    }

    public function testBeforeHookNullAllowsDispatchToContinue(): void
    {
        $match    = $this->makeMatch('GET', '/act', BeforeGuardController::class . '@act');
        $response = $this->resolver->resolve($match, Request::fromArray('GET', '/act'));

        // No X-Block header → before() returns null → handler runs.
        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testAfterHookReceivesAndDecoratesHandlerResponse(): void
    {
        $match    = $this->makeMatch('GET', '/act', AfterDecoratorController::class . '@act');
        $response = $this->resolver->resolve($match, Request::fromArray('GET', '/act'));

        self::assertSame('result', $response->body);
        self::assertSame('decorated', $response->headers['X-After']);
    }

    public function testBothHooksApplied(): void
    {
        $match    = $this->makeMatch('GET', '/act', BothLifecycleController::class . '@act');
        $response = $this->resolver->resolve($match, Request::fromArray('GET', '/act'));

        // before() passes, handler runs, after() stamps header.
        self::assertSame('dispatched', $response->body);
        self::assertSame('yes', $response->headers['X-After']);
    }

    public function testBothHooksBeforeShortCircuitsSkipsAfter(): void
    {
        $match   = $this->makeMatch('GET', '/act', BothLifecycleController::class . '@act');
        $request = Request::fromArray('GET', '/act', headers: ['X-Block' => '1']);

        $response = $this->resolver->resolve($match, $request);

        // before() short-circuits → after() never decorates, no X-After header.
        self::assertSame(401, $response->status);
        self::assertSame('halted', $response->body);
        self::assertArrayNotHasKey('X-After', $response->headers);
    }

    // -- RouteDispatcher integration ------------------------------------------

    public function testIntegratesWithRouteDispatcherAsResolver(): void
    {
        $routes = new \Zephyrus\Routing\RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', PlainHandlerController::class . '@withIntParam', ['id' => '\d+']));

        $pipeline = new \Zephyrus\Http\MiddlewarePipeline([]);

        $resolver = new HandlerResolver();

        $dispatcher = new \Zephyrus\Routing\RouteDispatcher(
            routes: $routes,
            pipeline: $pipeline,
            resolver: $resolver->resolve(...),
        );

        $response = $dispatcher->dispatch(Request::fromArray('GET', '/users/99'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('"id":99', $response->body);
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<string, string> $attributes
     */
    private function makeMatch(
        string $method,
        string $path,
        string $handler,
        array $attributes = [],
    ): RouteMatch {
        return new RouteMatch(
            route: Route::define($method, $path, $handler),
            parameters: $attributes,
        );
    }
}
