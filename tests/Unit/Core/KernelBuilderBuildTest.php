<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Core\KernelRethrowSignal;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Rendering\RenderEngine;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Router;

final class KernelBuilderBuildTest extends TestCase
{
    public function testAGlobalMiddlewareIsReportedAsMounted(): void
    {
        $middleware = self::middleware();
        $builder = KernelBuilder::create()->withMiddleware($middleware);

        self::assertTrue($builder->hasMiddleware($middleware::class));
    }

    public function testAMiddlewareRegisteredOnlyUnderARouteNameIsReportedAsMounted(): void
    {
        $middleware = self::middleware();
        $builder = KernelBuilder::create()->registerMiddleware('named', $middleware);

        self::assertTrue($builder->hasMiddleware($middleware::class));
    }

    public function testAMiddlewareNeverRegisteredIsReportedAsNotMounted(): void
    {
        self::assertFalse(KernelBuilder::create()->withMiddleware(self::middleware())->hasMiddleware(\stdClass::class));
    }

    public function testTheGlobalMiddlewaresOfAClassAreAListInRegistrationOrder(): void
    {
        $first = self::middleware();
        $second = self::otherMiddleware();
        $third = self::otherMiddleware();
        $builder = KernelBuilder::create()->withMiddleware($first)->withMiddleware($second)->withMiddleware($third);

        self::assertSame([$second, $third], $builder->globalMiddlewaresOf($second::class));
    }

    public function testACustomControllerFactoryStillBuildsTheControllersWhenARenderEngineIsSet(): void
    {
        $router = (new Router())->get('/hello', KernelBuilderFactoryController::class . '@hello');
        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->withRenderEngine(new class implements RenderEngine {
                public function render(string $page, array $args = []): string
                {
                    return '';
                }

                public function exists(string $page): bool
                {
                    return false;
                }
            })
            ->withControllerFactory(static fn (string $class): object => new $class('built by the factory'))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/hello'));

        self::assertSame('built by the factory', $response->body);
    }

    public function testARouteNamingTheSameUnregisteredMiddlewareTwiceIsReportedOnce(): void
    {
        $router = (new Router())->get('/hello', KernelBuilderFactoryController::class . '@hello', middlewares: ['auth', 'auth']);

        try {
            $ignored = KernelBuilder::create()->withRouter($router)->registerMiddleware('404', self::middleware())->build();
            self::fail('expected a refusal');
        } catch (RouteMiddlewareException $e) {
            self::assertSame(
                'Route middleware is not registered: "auth" on GET /hello; registered names: "404". Register it with '
                . 'registerMiddleware() before build(), or, for a group, define it with Router::middlewareGroup() '
                . 'before the routes that name it.',
                $e->getMessage(),
            );
        }
    }

    public function testRenderEngineExceptionHandlerAndRethrowOptionsEachReturnANewBuilder(): void
    {
        $original = KernelBuilder::create();
        $engine = new class implements RenderEngine {
            public function render(string $page, array $args = []): string
            {
                return '';
            }

            public function exists(string $page): bool
            {
                return false;
            }
        };

        $variants = [
            $original->withRenderEngine($engine),
            $original->withExceptionHandler(\RuntimeException::class, static fn (): Response => Response::text('x')),
            $original->withUnhandledExceptionsRethrown(),
        ];

        foreach ($variants as $variant) {
            self::assertNotSame($original, $variant);
        }
    }

    public function testRethrowSignalCarriesTheMessageCodeAndPreviousOfTheOriginal(): void
    {
        $original = new \RuntimeException('boom', 7);

        $signal = new KernelRethrowSignal($original);

        self::assertSame(['boom', 7, $original], [$signal->getMessage(), $signal->getCode(), $signal->getPrevious()]);
    }

    private static function middleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return $next($request);
            }
        };
    }

    private static function otherMiddleware(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return $next($request);
            }
        };
    }
}

final class KernelBuilderFactoryController
{
    public function __construct(private readonly string $greeting)
    {
    }

    public function hello(): Response
    {
        return Response::text($this->greeting);
    }
}
