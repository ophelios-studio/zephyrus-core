<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Root
{
    public function __construct(
        public readonly string $prefix,
    ) {
    }
}
