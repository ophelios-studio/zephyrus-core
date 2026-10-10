<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

/**
 * Applies a registered middleware group to the routes of a controller class or method.
 *
 * Class-level attributes are inherited and run parent-first. Inside a method, every #[Middleware] runs
 * before every #[MiddlewareGroup].
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class MiddlewareGroup
{
    public readonly string $name;

    /**
     * @param string $name Name of the registered middleware group.
     */
    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
