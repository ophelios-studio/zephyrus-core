<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

final readonly class Route
{
    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $handler,
        public array $constraints = [],
        public array $middlewares = [],
    ) {
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public static function define(
        string $method,
        string $path,
        string $handler,
        array $constraints = [],
        array $middlewares = [],
    ): self {
        $normalizedPath = '/' . trim($path, '/');

        return new self(
            method: strtoupper($method),
            path: $normalizedPath === '/' ? '/' : $normalizedPath,
            handler: $handler,
            constraints: $constraints,
            middlewares: $middlewares,
        );
    }

    public function matchesMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }
}
