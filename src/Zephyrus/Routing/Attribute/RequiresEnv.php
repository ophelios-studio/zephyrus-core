<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

/**
 * Conditionally register a route based on an environment variable.
 *
 * When applied to a controller class or method, routes are only
 * registered when the environment variable matches the expected value.
 * This allows separating WEB vs API controllers at route discovery time.
 *
 * Usage:
 *   #[RequiresEnv('MODE', 'WEB')]
 *   class DashboardController extends Controller { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequiresEnv
{
    public function __construct(
        public readonly string $variable,
        public readonly string $value,
    ) {}
}
