<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

/**
 * A route read from attributes, with the #[RequiresEnv] pairs of its class, the parents of its class and its method.
 *
 * @internal
 */
final readonly class DiscoveredRoute
{
    /**
     * @param list<array{string, string}> $requirements (variable, value) pairs the environment must hold.
     * @param list<string> $groups The names its #[MiddlewareGroup] attributes declare.
     */
    public function __construct(
        public Route $route,
        public array $requirements = [],
        public array $groups = [],
    ) {
    }
}
