<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Middleware
{
    public readonly string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}
