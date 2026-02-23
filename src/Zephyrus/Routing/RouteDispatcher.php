<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Closure;
use Throwable;
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

        $routeMiddlewares = $this->resolveRouteMiddlewares($match->route->middlewares);
        $pipeline = $this->pipeline->pipeMany($routeMiddlewares);

        return $pipeline->handle(
            $request,
            fn (Request $request): Response => ($this->resolver)($match, $request),
        );
    }

    /**
     * @param array<int, string> $middlewareNames
     * @return array<int, MiddlewareInterface>
     */
    private function resolveRouteMiddlewares(array $middlewareNames): array
    {
        $resolved = [];

        foreach (array_values(array_unique($middlewareNames)) as $name) {
            try {
                $resolved[] = ($this->routeMiddlewareResolver)($name);
            } catch (Throwable $e) {
                if ($e instanceof RouteMiddlewareException) {
                    throw $e;
                }

                throw RouteMiddlewareException::resolutionFailed($name, $e);
            }
        }

        return $resolved;
    }
}
