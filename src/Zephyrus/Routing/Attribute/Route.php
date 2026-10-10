<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

/**
 * Declares a route on a controller method. Repeatable so a single method can
 * handle multiple paths or verbs.
 *
 * Usage:
 *   #[Route('/users', 'GET')]
 *   #[Route('/users', 'GET', name: 'users.index', middlewares: ['auth'])]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * @param string                $path        Path pattern with {placeholder} segments.
     * @param string                $method      HTTP method.
     * @param array<string, string> $constraints Regex per placeholder name; unlisted placeholders match "[^/]+".
     * @param array<int, string>    $middlewares Names of the middlewares or middleware groups applied to this route.
     * @param string                $name        Route name for URL generation; empty for none.
     */
    public function __construct(
        public readonly string $path,
        public readonly string $method = 'GET',
        public readonly array $constraints = [],
        public readonly array $middlewares = [],
        public readonly string $name = '',
    ) {
    }
}
