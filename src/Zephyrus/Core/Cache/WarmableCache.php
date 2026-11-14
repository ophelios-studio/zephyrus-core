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
     * Read and validate the source, without writing the cache or reading the environment. The directory and target-file guards run here too, so the closure writes only what was checked.
     *
     * @return \Closure(): string Writes the cache and returns the line cache:warm prints: the file written, never a
     *                            cached value.
     * @throws ZephyrusException when the source is refused or a file already at the cache path is not a warm file.
     */
    public function prepare(): \Closure;

    /**
     * Check that the cache file can be deleted, without deleting it.
     *
     * @return \Closure(): bool Deletes the file and returns whether one was deleted.
     * @throws ZephyrusException when the file in place was not written by cache:warm or cannot be deleted safely.
     */
    public function prepareClear(): \Closure;
}
