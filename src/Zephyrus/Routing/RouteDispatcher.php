<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Closure;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

final readonly class RouteDispatcher
{
    /**
     * @var Closure(RouteMatch, Request): Response
     */
    private Closure $resolver;

    /**
     * @param callable(RouteMatch, Request): Response $resolver
     */
    public function __construct(
        private RouteCollection $routes,
        private MiddlewarePipeline $pipeline,
        callable $resolver,
    ) {
        $this->resolver = Closure::fromCallable($resolver);
    }

    public function dispatch(Request $request): Response
    {
        $match = $this->routes->match($request->method, $request->path());

        return $this->pipeline->handle(
            $request,
            fn (Request $request): Response => ($this->resolver)($match, $request),
        );
    }
}
