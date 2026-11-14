<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

/**
 * Decides whether a cache file may be included. Protected by owners and modes: the file and every directory up to
 * the base directory must belong to root or to the owner of the trusted source, with no write bit for group or others.
 *
 * @internal
 */
final readonly class CacheFileGuard
{
    public const int GROUP_OR_OTHERS_WRITE = 0o022;

    private string $baseDirectory;

    /**
     * @param string $baseDirectory The real path of the directory the cache must stay under.
     * @param \Closure(string): (int|false) $ownerOf Reads the owner uid of a path.
     */
    public function __construct(
        string $baseDirectory,
        private string $trustedSource,
        private \Closure $ownerOf,
    ) {
        $this->baseDirectory = rtrim($baseDirectory, '/') ?: '/';
    }

    /**
     * The real path of the cache file, safe to include.
     *
     * @throws CacheRefusal when the file must not be included.
     */
    public function trustedRealPath(string $path): string
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            throw new CacheRefusal('it does not resolve to an existing file');
        }

        if (!str_starts_with($realPath, rtrim($this->baseDirectory, '/') . '/')) {
            throw new CacheRefusal(sprintf('it resolves outside %s', $this->baseDirectory));
        }

        if (!is_file($realPath)) {
            throw new CacheRefusal('it is not a regular file');
        }

        if (!is_readable($realPath)) {
            throw new CacheRefusal('it is not readable by this process');
        }

        $trustedOwner = ($this->ownerOf)($this->trustedSource);
        for ($current = $realPath; $current !== $this->baseDirectory; $current = dirname($current)) {
            $this->assertProtected($current, $trustedOwner);
        }

        return $realPath;
    }

    private function assertProtected(string $path, int|false $trustedOwner): void
    {
        $owner = ($this->ownerOf)($path);
        if ($owner === false || ($owner !== 0 && $owner !== $trustedOwner)) {
            throw new CacheRefusal(sprintf(
                '%s is owned by neither root nor the owner of %s',
                $path,
                $this->trustedSource,
            ));
        }

        $permissions = fileperms($path);
        if ($permissions === false || ($permissions & self::GROUP_OR_OTHERS_WRITE) !== 0) {
            throw new CacheRefusal(sprintf('%s is writable by its group or by others', $path));
        }
    }
}
