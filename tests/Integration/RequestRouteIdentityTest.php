<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteMatch;
use Zephyrus\Routing\Router;

/**
 * A handler identifies its route through Request::route(), whatever the URL
 * spelled, because path() keeps a trailing slash the router ignores.
 */
final class RequestRouteIdentityTest extends TestCase
{
    private function kernel(): HttpKernel
    {
        $router = (new Router())
            ->post('/login', RouteIdentityController::class . '@login')
            ->name('login');

        return KernelBuilder::create()->withRouter($router)->build();
    }

    private function request(string $method, string $target): Request
    {
        return Request::fromGlobals(
            server: ['REQUEST_URI' => $target, 'REQUEST_METHOD' => $method, 'HTTP_HOST' => 'app.example.test'],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '',
        );
    }

    public function testTrailingSlashReachesTheSameRouteIdentity(): void
    {
        $plain = $this->kernel()->handle($this->request('POST', '/login'));
        $slashed = $this->kernel()->handle($this->request('POST', '/login/'));

        self::assertSame('login|/login', trim($plain->body));
        self::assertSame(trim($plain->body), trim($slashed->body));
    }

    public function testWithMatchedRouteSetsParametersLikeWithRouteParameters(): void
    {
        $route = new Route('GET', '/users/{id}', 'Handler@show', name: 'users.show');
        $base = Request::fromArray(method: 'GET', uri: '/users/7', attributes: ['keep' => 'yes']);

        $viaRoute = $base->withMatchedRoute(new RouteMatch($route, ['id' => '7']));
        $viaParameters = $base->withRouteParameters(['id' => '7']);

        self::assertSame($viaParameters->attributes, $viaRoute->attributes);
        self::assertSame($viaParameters->routeParameters, $viaRoute->routeParameters);
        self::assertSame('7', $viaRoute->routeParameter('id'));
        self::assertNull($viaParameters->route());
    }
}

// ===========================================================================
// Fixtures
// ===========================================================================

final class RouteIdentityController
{
    public function login(Request $request): Response
    {
        $route = $request->route();

        return Response::text(($route?->name ?? '') . '|' . ($route?->path ?? ''));
    }
}
