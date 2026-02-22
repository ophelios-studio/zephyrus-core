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
        public ?string $name = null,
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
        ?string $name = null,
    ): self {
        $normalizedPath = '/' . trim($path, '/');

        return new self(
            method: strtoupper($method),
            path: $normalizedPath === '/' ? '/' : $normalizedPath,
            handler: $handler,
            constraints: $constraints,
            middlewares: $middlewares,
            name: $name,
        );
    }

    public function matchesMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function withName(string $name): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            handler: $this->handler,
            constraints: $this->constraints,
            middlewares: $this->middlewares,
            name: $name,
        );
    }
}
