<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
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
    private function kernel(): \Zephyrus\Core\HttpKernel
    {
        $router = (new Router())
            ->post('/login', RouteIdentityController::class . '@login')
            ->name('login')
            ->get('/', RouteIdentityController::class . '@root');

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

    public function testHandlerSeesTheRouteItIsOn(): void
    {
        $response = $this->kernel()->handle($this->request('POST', '/login/'));

        self::assertSame('login|/login', trim($response->body));
    }

    public function testRouteSurvivesEveryCloneMethod(): void
    {
        $route = new Route('POST', '/login', 'Handler@login', name: 'login');
        $matched = Request::fromArray(method: 'POST', uri: '/login/')
            ->withMatchedRoute(new RouteMatch($route, ['id' => '7']));

        self::assertSame($route, $matched->withAttribute('k', 'v')->route());
        self::assertSame($route, $matched->withAttributes(['k' => 'v'])->route());
        self::assertSame($route, $matched->withRouteParameters(['id' => '8'])->route());
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

    public function root(): Response
    {
        return Response::text('root');
    }
}
