<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

/**
 * Registers the routes of a class or method only when an environment variable has the expected value.
 *
 * Separates application modes such as WEB and API at route discovery. Subclasses inherit the
 * requirement of their parent classes.
 *
 * Usage:
 *   #[RequiresEnv('MODE', 'WEB')]
 *   class DashboardController extends Controller { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequiresEnv
{
    /**
     * @param string $variable Environment variable name.
     * @param string $value    Value the variable must equal.
     */
    public function __construct(
        public readonly string $variable,
        public readonly string $value,
    ) {}
}
