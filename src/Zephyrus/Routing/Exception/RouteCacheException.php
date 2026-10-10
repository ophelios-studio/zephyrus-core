<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when route cache operations fail.
 *
 * Covers file I/O, serialization, payload validation, integrity checks,
 * and cache staleness conditions.
 */
final class RouteCacheException extends ZephyrusRuntimeException
{
    /**
     * A cache file is refused: the message names the file, the problem and the fix.
     */
    public static function refused(string $file, string $problem, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Route cache file %s has %s; rebuild the cache with save() or warm()', $file, $problem),
            previous: $previous,
        );
    }

    /**
     * The cache is stale, expired, or does not match current routes.
     */
    public static function staleCache(string $reason): self
    {
        return new self($reason);
    }
}
