<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

use Composer\InstalledVersions;

/**
 * A PHP file holding data derived from a source, written by cache:warm and never at runtime. Loading never throws: a
 * cache that is stale, damaged or refused by {@see CacheFileGuard} is logged once and ignored, and the caller falls
 * back to its source.
 *
 * @internal
 */
final readonly class BootCache
{
    public const int FORMAT = 1;
    public const string WARM_COMMAND = 'vendor/bin/zephyrus cache:warm';
    public const string IGNORED_LOG_LINE = 'Zephyrus: ignored the boot cache %s: %s. Run %s to rebuild it.';
    private const string PACKAGE = 'zephyrus-framework/core';

    /** @var \Closure(string): (int|false) */
    private \Closure $ownerOf;

    /**
     * @param string $path Absolute path of the cache file.
     * @param string $baseDirectory Real path of the directory the cache must stay under.
     * @param string $trustedSource Real path of the file whose owner is trusted alongside root.
     * @param (\Closure(string): (int|false))|null $ownerOf Reads the owner uid of a path, fileowner() by default.
     */
    public function __construct(
        private string $path,
        private string $baseDirectory,
        private string $trustedSource,
        ?\Closure $ownerOf = null,
    ) {
        $this->ownerOf = $ownerOf ?? fileowner(...);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The cached payload, or null when the file is absent (silently) or unusable (with one error_log line).
     *
     * @param \Closure(): string $fingerprint Computes the current fingerprint of the source, only when the file exists.
     * @return array<mixed>|null
     */
    public function load(\Closure $fingerprint): ?array
    {
        if (!file_exists($this->path) && !is_link($this->path)) {
            return null;
        }

        try {
            return $this->payload($this->includeTrusted(), $fingerprint);
        } catch (CacheRefusal $refusal) {
            error_log(sprintf(self::IGNORED_LOG_LINE, $this->path, $refusal->getMessage(), self::WARM_COMMAND));

            return null;
        }
    }

    /**
     * Write the payload under a header naming the format, the framework version and the fingerprint.
     *
     * @param array<mixed> $payload
     * @throws BootCacheException when the payload cannot be encoded or the file cannot be written safely.
     */
    public function store(array $payload, string $fingerprint, int $mode = AtomicFileWriter::FILE_MODE, ?int $group = null): void
    {
        $processOwner = AtomicFileWriter::processOwner();
        if ($processOwner !== 0 && $processOwner !== ($this->ownerOf)($this->trustedSource)) {
            throw BootCacheException::refusedWrite($this->path, sprintf(
                'this process is neither root nor the owner of %s, so the application would ignore the cache',
                $this->trustedSource,
            ));
        }

        $contents = sprintf(
            "<?php\n\n// Written by %s.\n\nreturn ['format' => %s, 'framework' => %s, 'fingerprint' => %s, 'payload' => %s];\n",
            self::WARM_COMMAND,
            var_export(self::FORMAT, true),
            var_export(self::frameworkVersion(), true),
            var_export($fingerprint, true),
            PhpValueEncoder::encode($payload),
        );

        (new AtomicFileWriter($this->baseDirectory, $this->ownerOf))->write($this->path, $contents, $mode, $group);
    }

    /**
     * Delete the cache file.
     *
     * @return bool Whether a file was deleted.
     * @throws BootCacheException when the file cannot be deleted safely.
     */
    public function clear(): bool
    {
        return (new AtomicFileWriter($this->baseDirectory, $this->ownerOf))->remove($this->path);
    }

    private function includeTrusted(): mixed
    {
        $realPath = (new CacheFileGuard($this->baseDirectory, $this->trustedSource, $this->ownerOf))
            ->trustedRealPath($this->path);

        ob_start();
        try {
            $cached = (static fn (string $file): mixed => include $file)($realPath);
        } catch (\Throwable $error) {
            throw new CacheRefusal(sprintf('it does not load (%s)', $error::class));
        } finally {
            $output = ob_get_clean();
        }

        if ($output !== '') {
            throw new CacheRefusal('it prints output, so it is not a boot cache');
        }

        return $cached;
    }

    /**
     * @param \Closure(): string $fingerprint
     * @return array<mixed>
     */
    private function payload(mixed $cached, \Closure $fingerprint): array
    {
        if (!is_array($cached) || !array_key_exists('format', $cached)) {
            throw new CacheRefusal('it does not return a boot cache array');
        }

        if ($cached['format'] !== self::FORMAT) {
            throw new CacheRefusal('it was written in another cache format');
        }

        if (($cached['framework'] ?? null) !== self::frameworkVersion()) {
            throw new CacheRefusal('it was written by another version of the framework');
        }

        if (($cached['fingerprint'] ?? null) !== $fingerprint()) {
            throw new CacheRefusal('it is stale, its source changed after it was written');
        }

        if (!is_array($cached['payload'] ?? null)) {
            throw new CacheRefusal('it does not return a boot cache array');
        }

        return $cached['payload'];
    }

    private static function frameworkVersion(): string
    {
        try {
            return (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? '')
                . '@' . (InstalledVersions::getReference(self::PACKAGE) ?? '');
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }
}
