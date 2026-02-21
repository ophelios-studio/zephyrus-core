<?php

declare(strict_types=1);

namespace Zephyrus\Http;

final class MiddlewarePipeline
{
    /**
     * @var array<int, MiddlewareInterface>
     */
    private array $middlewares;

    /**
     * @param array<int, MiddlewareInterface> $middlewares
     */
    public function __construct(array $middlewares = [])
    {
        $this->middlewares = $middlewares;
    }

    public function pipe(MiddlewareInterface $middleware): self
    {
        $middlewares = $this->middlewares;
        $middlewares[] = $middleware;

        return new self($middlewares);
    }

    /**
     * @param callable(Request): Response $destination
     */
    public function handle(Request $request, callable $destination): Response
    {
        $next = $destination;

        for ($index = count($this->middlewares) - 1; $index >= 0; $index--) {
            $middleware = $this->middlewares[$index];
            $currentNext = $next;

            $next = static fn (Request $request): Response => $middleware->process($request, $currentNext);
        }

        return $next($request);
    }
}
