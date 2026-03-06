<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Patch
{
    /**
     * @param array<string, string> $constraints
     * @param array<int, string>    $middlewares
     */
    public function __construct(
        public readonly string $path,
        public readonly array $constraints = [],
        public readonly array $middlewares = [],
        public readonly string $name = '',
    ) {
    }
}
