<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when the routes cache cannot be written.
 */
final class RouteCacheException extends ZephyrusRuntimeException
{
    /**
     * The controllers of router.controllers declare no route, so there is nothing to cache.
     */
    public static function noRoutes(string $file): self
    {
        return new self(sprintf(
            'The controllers of router.controllers declare no route, so the routes cache %s was not written: check '
            . 'that each namespace matches its directory.',
            $file,
        ));
    }
}
