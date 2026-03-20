<?php

declare(strict_types=1);

namespace Zephyrus\Data;

use Attribute;

/**
 * Marks a public property to be excluded from JSON serialization
 * when using Entity::jsonSerialize().
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class JsonIgnore
{
}
