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
     * A filesystem operation (read, write, delete, mkdir) failed on the cache.
     */
    public static function fileSystemError(string $operation, string $path): self
    {
        return new self(sprintf('Unable to %s route cache file: %s', $operation, $path));
    }

    /**
     * JSON encoding or decoding of the cache payload failed.
     */
    public static function encodingFailed(string $direction, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Unable to %s route cache payload', $direction),
            previous: $previous,
        );
    }

    /**
     * The cache payload is missing a required top-level section or has wrong structure.
     */
    public static function invalidPayloadStructure(string $detail): self
    {
        return new self(sprintf('Route cache payload %s', $detail));
    }

    /**
     * A metadata field in the cache payload is invalid or unsupported.
     */
    public static function invalidMetadata(string $detail): self
    {
        return new self(sprintf('Route cache payload contains %s', $detail));
    }

    /**
     * The cache payload failed a hash or count integrity check.
     */
    public static function integrityCheckFailed(string $detail): self
    {
        return new self(sprintf('Route cache payload %s', $detail));
    }

    /**
     * An individual route entry within the cache has invalid or missing fields.
     */
    public static function invalidRouteEntry(string $detail): self
    {
        return new self(sprintf('Route cache entry %s', $detail));
    }

    /**
     * The cache is stale, expired, or does not match current routes.
     */
    public static function staleCache(string $reason): self
    {
        return new self($reason);
    }
}
