<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Attribute\Get;
use Zephyrus\Routing\Attribute\WithoutMiddleware;
use Zephyrus\Routing\Router;

final class HttpKernelWithoutMiddlewareTest extends TestCase
{
    public function testExcludedGlobalMiddlewareIsSkippedOnTheMatchedRoute(): void
    {
        $response = $this->kernel($this->fluentRouter())->handle(Request::fromArray('GET', '/public'));

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('x-alpha', $response->headers);
        self::assertSame('yes', $response->headers['x-beta']);
    }

    public function testNeighbourRouteStillRunsTheExcludedMiddleware(): void
    {
        $response = $this->kernel($this->fluentRouter())->handle(Request::fromArray('GET', '/private'));

        self::assertSame(200, $response->status);
        self::assertSame('yes', $response->headers['x-alpha']);
        self::assertSame('yes', $response->headers['x-beta']);
    }

    public function testNotFoundRunsEveryGlobalMiddleware(): void
    {
        $response = $this->kernel($this->fluentRouter())->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('yes', $response->headers['x-alpha']);
    }

    public function testMethodNotAllowedOnAnExcludingRouteRunsEveryGlobalMiddleware(): void
    {
        $response = $this->kernel($this->fluentRouter())->handle(Request::fromArray('POST', '/public'));

        self::assertSame(405, $response->status);
        self::assertSame('yes', $response->headers['x-alpha']);
    }

    public function testOptionsWithoutAnOptionsRouteRunsEveryGlobalMiddleware(): void
    {
        $response = $this->kernel($this->fluentRouter())->handle(Request::fromArray('OPTIONS', '/public'));

        self::assertSame(405, $response->status);
        self::assertSame('yes', $response->headers['x-alpha']);
    }

    public function testHeadServedByAGetRouteFollowsItsExclusions(): void
    {
        $response = $this->kernel($this->fluentRouter())->handle(Request::fromArray('HEAD', '/public'));

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('x-alpha', $response->headers);
    }

    public function testExcludingAnInterfaceSkipsEveryMiddlewareImplementingIt(): void
    {
        $router = (new Router())
            ->get('/plain', SkipPageController::class . '@show')
            ->withoutMiddleware(SkipStampMiddleware::class);

        $response = $this->kernel($router)->handle(Request::fromArray('GET', '/plain'));

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('x-alpha', $response->headers);
        self::assertArrayNotHasKey('x-beta', $response->headers);
        self::assertSame('yes', $response->headers['x-unrelated']);
    }

    public function testExcludingAnAbstractParentSkipsEverySubclass(): void
    {
        $router = (new Router())
            ->get('/plain', SkipPageController::class . '@show')
            ->withoutMiddleware(SkipAbstractStampMiddleware::class);

        $response = $this->kernel($router)->handle(Request::fromArray('GET', '/plain'));

        self::assertArrayNotHasKey('x-alpha', $response->headers);
        self::assertArrayNotHasKey('x-beta', $response->headers);
        self::assertSame('yes', $response->headers['x-unrelated']);
    }

    public function testRouteMiddlewareOfAnExcludedClassStillRuns(): void
    {
        $router = (new Router())
            ->get('/guarded', SkipPageController::class . '@show', middlewares: ['alpha'])
            ->withoutMiddleware(SkipAlphaMiddleware::class);

        $kernel = KernelBuilder::create()
            ->withRouter($router)
            ->registerMiddleware('alpha', new SkipAlphaMiddleware())
            ->withMiddleware(new SkipAlphaMiddleware())
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/guarded'));

        self::assertSame(200, $response->status);
        self::assertSame('yes', $response->headers['x-alpha']);
    }

    public function testClassLevelAttributeIsInheritedBySubclassRoutes(): void
    {
        $router = (new Router())->controller(SkipChildController::class);

        $response = $this->kernel($router)->handle(Request::fromArray('GET', '/inherited'));

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('x-alpha', $response->headers);
        self::assertSame('yes', $response->headers['x-beta']);
    }

    public function testMethodLevelAttributeIsMergedWithTheInheritedOne(): void
    {
        $router = (new Router())->controller(SkipChildController::class);

        $response = $this->kernel($router)->handle(Request::fromArray('GET', '/both'));

        self::assertSame(200, $response->status);
        self::assertArrayNotHasKey('x-alpha', $response->headers);
        self::assertArrayNotHasKey('x-beta', $response->headers);
        self::assertSame('yes', $response->headers['x-unrelated']);
    }

    private function fluentRouter(): Router
    {
        return (new Router())
            ->get('/public', SkipPageController::class . '@show')
            ->withoutMiddleware(SkipAlphaMiddleware::class)
            ->get('/private', SkipPageController::class . '@show');
    }

    private function kernel(Router $router): HttpKernel
    {
        return KernelBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new SkipAlphaMiddleware())
            ->withMiddleware(new SkipBetaMiddleware())
            ->withMiddleware(new SkipUnrelatedMiddleware())
            ->build();
    }
}

interface SkipStampMiddleware extends MiddlewareInterface
{
}

abstract class SkipAbstractStampMiddleware implements SkipStampMiddleware
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('x-' . $this->label(), 'yes');
    }

    abstract protected function label(): string;
}

final class SkipAlphaMiddleware extends SkipAbstractStampMiddleware
{
    protected function label(): string
    {
        return 'alpha';
    }
}

final class SkipBetaMiddleware extends SkipAbstractStampMiddleware
{
    protected function label(): string
    {
        return 'beta';
    }
}

final class SkipUnrelatedMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        return $next($request)->withHeader('x-unrelated', 'yes');
    }
}

final class SkipPageController
{
    public function show(): Response
    {
        return Response::text('page');
    }
}

#[WithoutMiddleware(SkipAlphaMiddleware::class)]
abstract class SkipParentController
{
}

final class SkipChildController extends SkipParentController
{
    #[Get('/inherited')]
    public function inherited(): Response
    {
        return Response::text('inherited');
    }

    #[Get('/both')]
    #[WithoutMiddleware(SkipBetaMiddleware::class)]
    public function both(): Response
    {
        return Response::text('both');
    }
}
