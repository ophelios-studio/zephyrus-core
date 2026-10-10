<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

/**
 * Declares a PUT route on a controller method.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Put
{
    /**
     * @param string                $path        Path pattern with {placeholder} segments.
     * @param array<string, string> $constraints Regex per placeholder name; unlisted placeholders match "[^/]+".
     * @param array<int, string>    $middlewares Names of the middlewares or middleware groups applied to this route.
     * @param string                $name        Route name for URL generation; empty for none.
     */
    public function __construct(
        public readonly string $path,
        public readonly array $constraints = [],
        public readonly array $middlewares = [],
        public readonly string $name = '',
    ) {
    }
}
