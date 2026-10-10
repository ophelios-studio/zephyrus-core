<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Immutable chain of middlewares. The first one piped runs outermost.
 */
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

    /** Returns a copy with $middleware appended to the chain. */
    public function pipe(MiddlewareInterface $middleware): self
    {
        $middlewares = $this->middlewares;
        $middlewares[] = $middleware;

        return new self($middlewares);
    }

    /**
     * Returns a copy with each middleware appended, in order.
     *
     * @param array<int, MiddlewareInterface> $middlewares
     */
    public function pipeMany(array $middlewares): self
    {
        $pipeline = $this;

        foreach ($middlewares as $middleware) {
            $pipeline = $pipeline->pipe($middleware);
        }

        return $pipeline;
    }

    /**
     * Returns a copy without the middlewares that are instances of any of the given classes or interfaces.
     *
     * @param array<int, class-string> $classes
     */
    public function without(array $classes): self
    {
        if ($classes === []) {
            return $this;
        }

        $kept = array_filter(
            $this->middlewares,
            static function (MiddlewareInterface $middleware) use ($classes): bool {
                foreach ($classes as $class) {
                    if ($middleware instanceof $class) {
                        return false;
                    }
                }

                return true;
            },
        );

        return new self(array_values($kept));
    }

    /**
     * Runs the chain around $destination and returns the response.
     *
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
