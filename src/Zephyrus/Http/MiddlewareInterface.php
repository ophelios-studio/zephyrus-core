<?php

declare(strict_types=1);

namespace Zephyrus\Http;

/**
 * Wraps the rest of the pipeline. See the Middleware section of the README for an example.
 */
interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
