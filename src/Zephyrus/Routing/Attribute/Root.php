<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

/**
 * Prefixes every route of a controller class with a URL path.
 *
 * Prefixes of parent classes come first, then the class's own.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Root
{
    /**
     * @param string $prefix Path prepended to the class routes, e.g. '/admin'.
     */
    public function __construct(
        public readonly string $prefix,
    ) {
    }
}
