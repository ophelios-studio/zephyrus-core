<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;

final class KernelBuilderTest extends TestCase
{
    // -- Factory / immutability -----------------------------------------------

    public function testCreateReturnsNewBuilder(): void
    {
        $builder = KernelBuilder::create();

        self::assertInstanceOf(KernelBuilder::class, $builder);
    }

    public function testWithRouterReturnsNewInstance(): void
    {
        $builder = KernelBuilder::create();
        $modified = $builder->withRouter(new Router());

        self::assertNotSame($builder, $modified);
    }

    public function testWithMiddlewareReturnsNewInstance(): void
    {
        $middleware = $this->makeMiddleware('X-Test', 'yes');

        $builder = KernelBuilder::create();
        $modified = $builder->withMiddleware($middleware);

        self::assertNotSame($builder, $modified);
    }

    public function testRegisterMiddlewareReturnsNewInstance(): void
    {
        $middleware = $this->makeMiddleware('X-Named', 'yes');

        $builder = KernelBuilder::create();
        $modified = $builder->registerMiddleware('test', $middleware);

        self::assertNotSame($builder, $modified);
    }

    public function testWithControllerFactoryReturnsNewInstance(): void
    {
        $builder = KernelBuilder::create();
        $modified = $builder->withControllerFactory(static fn (string $class): object => new $class());

        self::assertNotSame($builder, $modified);
    }

    // -- Build returns HttpKernel ---------------------------------------------

    public function testBuildReturnsHttpKernel(): void
    {
        $kernel = KernelBuilder::create()->build();

        self::assertInstanceOf(HttpKernel::class, $kernel);
    }

    public function testBuildWithNoRouterYields404ForAnyRequest(): void
    {
        $kernel = KernelBuilder::create()->build();

        $response = $kernel->handle(Request::fromArray('GET', '/anything'));

        self::assertSame(404, $response->status);
    }

    public function testBuildWithEmptyRouterYields404(): void
    {
        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/anything'));

        self::assertSame(404, $response->status);
    }

    // -- Immutability: original builder unaffected by withX calls ------------

    public function testOriginalBuilderUnaffectedByWithRouter(): void
    {
        $original = KernelBuilder::create();

        $router = (new Router())->get('/ping', 'PingController@index');
        $original->withRouter($router); // result discarded

        // Original builder still has no router → any request 404s.
        $kernel = $original->build();
        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame(404, $response->status);
    }

    public function testBuildCanBeCalledMultipleTimes(): void
    {
        $builder = KernelBuilder::create();

        $k1 = $builder->build();
        $k2 = $builder->build();

        // Each call produces a distinct HttpKernel instance.
        self::assertNotSame($k1, $k2);
        self::assertInstanceOf(HttpKernel::class, $k1);
        self::assertInstanceOf(HttpKernel::class, $k2);
    }

    // -- Global middleware is applied -----------------------------------------

    public function testGlobalMiddlewareIsAppliedToResponse(): void
    {
        $router = (new Router())->get('/ping', KernelBuilderFixtureController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware($this->makeMiddleware('X-Global', 'yes'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame(200, $response->status);
        self::assertSame('yes', $response->headers['X-Global']);
    }

    public function testMultipleGlobalMiddlewaresAreAllApplied(): void
    {
        $router = (new Router())->get('/ping', KernelBuilderFixtureController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware($this->makeMiddleware('X-First', 'a'))
            ->withMiddleware($this->makeMiddleware('X-Second', 'b'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame('a', $response->headers['X-First']);
        self::assertSame('b', $response->headers['X-Second']);
    }

    // -- Named route middleware -----------------------------------------------

    public function testNamedRouteMiddlewareIsApplied(): void
    {
        $router = (new Router())->get(
            '/protected',
            KernelBuilderFixtureController::class . '@ping',
            middlewares: ['auth'],
        );

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('auth', $this->makeMiddleware('X-Auth', 'passed'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/protected'));

        self::assertSame('passed', $response->headers['X-Auth']);
    }

    // -- Controller factory ---------------------------------------------------

    public function testControllerFactoryIsInvokedOnDispatch(): void
    {
        $calls = 0;

        $router = (new Router())->get('/ping', KernelBuilderFixtureController::class . '@ping');

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withControllerFactory(function (string $class) use (&$calls): object {
                $calls++;

                return new $class();
            })
            ->build();

        $kernel->handle(Request::fromArray('GET', '/ping'));

        self::assertSame(1, $calls);
    }

    // -------------------------------------------------------------------------

    private function makeMiddleware(string $header, string $value): MiddlewareInterface
    {
        return new class($header, $value) implements MiddlewareInterface {
            public function __construct(
                private readonly string $header,
                private readonly string $value,
            ) {
            }

            public function process(Request $request, callable $next): Response
            {
                return $next($request)->withHeader($this->header, $this->value);
            }
        };
    }
}

// ---------------------------------------------------------------------------
// Fixture controller — minimal, no base class required
// ---------------------------------------------------------------------------

final class KernelBuilderFixtureController
{
    public function ping(): Response
    {
        return Response::text('pong');
    }
}
