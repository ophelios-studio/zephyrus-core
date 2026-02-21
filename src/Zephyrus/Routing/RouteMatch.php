<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

final readonly class RouteMatch
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public Route $route,
        public array $parameters = [],
    ) {
    }

    public function parameter(string $name, ?string $default = null): ?string
    {
        return $this->parameters[$name] ?? $default;
    }
}
