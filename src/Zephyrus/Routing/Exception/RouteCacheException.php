<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when route cache operations fail.
 *
 * Covers file I/O, serialization, payload validation, integrity checks,
 * and refused cache files.
 */
final class RouteCacheException extends ZephyrusRuntimeException
{
    /**
     * A cache file is refused: the message names the file, the problem and the fix.
     * $problem completes the phrase "Route cache file <file> has ...".
     */
    public static function refused(string $file, string $problem): self
    {
        return new self(sprintf('Route cache file %s has %s; rebuild the cache with save() or warm()', $file, $problem));
    }
}
