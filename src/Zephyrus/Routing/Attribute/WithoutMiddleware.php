<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Attribute;

use Attribute;

/**
 * Skips a global middleware on the routes of a controller class or method.
 *
 * Every global middleware that is an instance of the class is skipped, so a parent class or an interface
 * skips all its implementations. Class-level attributes are inherited, parent-first, and merged with the
 * method-level ones. A route middleware (#[Middleware]) is never skipped.
 *
 * Only a matched route skips: a 404 or 405, including an OPTIONS request without an OPTIONS route, runs
 * every global middleware. A HEAD request served by a GET route follows that route. The framework
 * security middlewares (and their parents) are refused when the route is registered.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class WithoutMiddleware
{
    public readonly string $middleware;

    /**
     * @param class-string $middleware Class or interface of the global middleware to skip.
     */
    public function __construct(string $middleware)
    {
        $this->middleware = $middleware;
    }
}
