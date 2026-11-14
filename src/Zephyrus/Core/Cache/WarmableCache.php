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
     * Read and validate the source, without writing the cache or reading the environment. Directory and target-file guards run when the closure writes.
     *
     * @return \Closure(): string Writes the cache and returns the line cache:warm prints: the file written, never a
     *                            cached value.
     * @throws ZephyrusException when the source is refused or a file already at the cache path is not a warm file.
     */
    public function prepare(): \Closure;

    /**
     * Delete the cache file.
     *
     * @return bool Whether a file was deleted.
     * @throws ZephyrusException when the file cannot be deleted safely.
     */
    public function clear(): bool;
}
