<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

/**
 * Writes and removes files under a base directory, refusing any path a symbolic link, a foreign owner or a shared
 * write bit could redirect. A write goes through a temporary file renamed over the target.
 *
 * @internal
 */
final readonly class AtomicFileWriter
{
    public const int DIRECTORY_MODE = 0o755;
    public const int FILE_MODE = 0o644;
    private const int PRIVATE_MODE = 0o600;

    private string $baseDirectory;

    /**
     * @param string $baseDirectory The real path of the directory every target must stay under.
     * @param \Closure(string): (int|false) $ownerOf Reads the owner uid of a path.
     */
    public function __construct(
        string $baseDirectory,
        private \Closure $ownerOf,
    ) {
        $this->baseDirectory = rtrim($baseDirectory, '/');
    }

    /**
     * The effective uid of this process, read from a file it creates (no ext-posix needed).
     *
     * @throws BootCacheException when no temporary file can be created.
     */
    public static function processOwner(): int
    {
        $probe = @tmpfile();
        $status = $probe === false ? false : fstat($probe);
        if ($probe !== false) {
            fclose($probe);
        }

        if ($status === false) {
            throw new BootCacheException('Cannot write a boot cache: the owner of this process cannot be determined.');
        }

        return $status['uid'];
    }

    /**
     * Write the contents to the target, creating the missing directories.
     *
     * @param int|null $processOwner The uid of this process, read with processOwner() when null.
     * @throws BootCacheException when the target or a directory under the base directory is refused, or the write fails.
     */
    public function write(
        string $target,
        string $contents,
        int $mode = self::FILE_MODE,
        ?int $group = null,
        ?int $processOwner = null,
    ): void {
        try {
            $this->replace($this->preparedPath($target, $processOwner ?? self::processOwner()), $contents, $mode, $group);
        } catch (CacheRefusal $refusal) {
            throw BootCacheException::refusedWrite($target, $refusal->getMessage());
        }
    }

    /**
     * Delete the target if it exists.
     *
     * @return bool Whether a file was deleted.
     * @throws BootCacheException when the target is outside the base directory, behind a symbolic link, or not deletable.
     */
    public function remove(string $target): bool
    {
        try {
            $directory = $this->baseDirectory;
            $segments = $this->segmentsUnderBase($target);
            $file = array_pop($segments);
            foreach ($segments as $segment) {
                $directory .= '/' . $segment;
                if (is_link($directory)) {
                    throw new CacheRefusal(sprintf('%s is a symbolic link', $directory));
                }
            }

            $path = $directory . '/' . $file;
            if (!file_exists($path) && !is_link($path)) {
                return false;
            }

            if (!is_link($path) && !is_file($path)) {
                throw new CacheRefusal(sprintf('%s is not a regular file', $path));
            }

            if (!@unlink($path)) {
                throw new CacheRefusal(sprintf('%s could not be deleted', $path));
            }

            return true;
        } catch (CacheRefusal $refusal) {
            throw BootCacheException::refusedRemoval($target, $refusal->getMessage());
        }
    }

    private function preparedPath(string $target, int $processOwner): string
    {
        $segments = $this->segmentsUnderBase($target);
        $file = array_pop($segments);
        $directory = $this->baseDirectory;
        $missing = [];
        foreach ($segments as $segment) {
            $directory .= '/' . $segment;
            if ($missing === [] && (file_exists($directory) || is_link($directory))) {
                $this->assertWritableDirectory($directory, $processOwner);
            } else {
                $missing[] = $directory;
            }
        }

        $path = $directory . '/' . $file;
        if (is_link($path)) {
            throw new CacheRefusal(sprintf('%s is a symbolic link', $path));
        }

        if (file_exists($path) && !is_file($path)) {
            throw new CacheRefusal(sprintf('%s is not a regular file', $path));
        }

        foreach ($missing as $created) {
            if (!@mkdir($created) || !@chmod($created, self::DIRECTORY_MODE)) {
                throw new CacheRefusal(sprintf('%s could not be created', $created));
            }
        }

        return $path;
    }

    /**
     * The segments of the target below the base directory, after resolving "." and ".." lexically.
     *
     * @return non-empty-list<string>
     */
    private function segmentsUnderBase(string $target): array
    {
        if (!str_starts_with($target, '/')) {
            throw new CacheRefusal('it is not an absolute path');
        }

        $normalized = PathPrefix::withoutDotSegments($target);
        if (!PathPrefix::isUnder($normalized, $this->baseDirectory)) {
            throw new CacheRefusal(sprintf('it is outside %s', $this->baseDirectory));
        }

        return explode('/', substr($normalized, strlen($this->baseDirectory) + 1));
    }

    private function assertWritableDirectory(string $directory, int $processOwner): void
    {
        if (is_link($directory)) {
            throw new CacheRefusal(sprintf('%s is a symbolic link', $directory));
        }

        if (!is_dir($directory)) {
            throw new CacheRefusal(sprintf('%s is not a directory', $directory));
        }

        if (($this->ownerOf)($directory) !== $processOwner) {
            throw new CacheRefusal(sprintf('%s is owned by another user than this process', $directory));
        }

        $permissions = fileperms($directory);
        if ($permissions === false || ($permissions & CacheFileGuard::GROUP_OR_OTHERS_WRITE) !== 0) {
            throw new CacheRefusal(sprintf('%1$s is writable by its group or by others: run chmod g-w,o-w %1$s', $directory));
        }
    }

    private function replace(string $path, string $contents, int $mode, ?int $group): void
    {
        $temporary = sprintf('%s/.%s.%s.tmp', dirname($path), basename($path), bin2hex(random_bytes(8)));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            throw new CacheRefusal(sprintf('%s could not be created', $temporary));
        }

        $written = @chmod($temporary, self::PRIVATE_MODE)
            && @fwrite($handle, $contents) === strlen($contents)
            && fflush($handle);
        fclose($handle);

        $failure = $written
            ? $this->publish($temporary, $path, $mode, $group)
            : sprintf('%s could not be written', $temporary);

        if ($failure !== null) {
            @unlink($temporary);
            throw new CacheRefusal($failure);
        }
    }

    /**
     * Give the temporary file its group and mode, then rename it over the target. Returns why it failed, or null.
     */
    private function publish(string $temporary, string $path, int $mode, ?int $group): ?string
    {
        if ($group !== null && filegroup($temporary) !== $group && !@chgrp($temporary, $group)) {
            return sprintf('the group of %s could not be set to gid %d', $temporary, $group);
        }

        if (!@chmod($temporary, $mode)) {
            return sprintf('the mode of %s could not be set', $temporary);
        }

        if (!@rename($temporary, $path)) {
            return sprintf('%s could not be renamed over %s', $temporary, $path);
        }

        return null;
    }
}
