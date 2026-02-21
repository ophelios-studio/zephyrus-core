<?php

declare(strict_types=1);

namespace Zephyrus\Http;

interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
