<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Closure;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\RouteMiddlewareException;

final readonly class RouteDispatcher
{
    /**
     * @var Closure(RouteMatch, Request): Response
     */
    private Closure $resolver;

    /**
     * @var Closure(string): MiddlewareInterface
     */
    private Closure $routeMiddlewareResolver;

    /**
     * @param callable(RouteMatch, Request): Response $resolver
     * @param callable(string): MiddlewareInterface|null $routeMiddlewareResolver
     */
    public function __construct(
        private RouteCollection $routes,
        private MiddlewarePipeline $pipeline,
        callable $resolver,
        ?callable $routeMiddlewareResolver = null,
    ) {
        $this->resolver = Closure::fromCallable($resolver);
        $this->routeMiddlewareResolver = $routeMiddlewareResolver !== null
            ? Closure::fromCallable($routeMiddlewareResolver)
            : static fn (string $name): MiddlewareInterface => throw RouteMiddlewareException::unknownMiddleware($name);
    }

    public function dispatch(Request $request): Response
    {
        $match = $this->routes->match($request->method, $request->path());
        $request = $request->withAttributes($match->parameters);

        $routeMiddlewares = array_map(
            fn (string $name): MiddlewareInterface => ($this->routeMiddlewareResolver)($name),
            $match->route->middlewares,
        );

        $pipeline = $this->pipeline->pipeMany($routeMiddlewares);

        return $pipeline->handle(
            $request,
            fn (Request $request): Response => ($this->resolver)($match, $request),
        );
    }
}
