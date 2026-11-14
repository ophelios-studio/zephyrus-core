<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

use Zephyrus\Exceptions\ZephyrusException;

/**
 * A boot cache that vendor/bin/zephyrus writes with cache:warm and deletes with cache:clear.
 *
 * @internal
 */
interface WarmableCache
{
    /**
     * Absolute path of the cache file.
     */
    public function path(): string;

    /**
     * Write the cache from its source, without reading the environment.
     *
     * @return string The line cache:warm prints: the file written, never a cached value.
     * @throws ZephyrusException when the source cannot be read or the cache cannot be written safely.
     */
    public function warm(): string;

    /**
     * Delete the cache file.
     *
     * @return bool Whether a file was deleted.
     * @throws ZephyrusException when the file cannot be deleted safely.
     */
    public function clear(): bool;
}
