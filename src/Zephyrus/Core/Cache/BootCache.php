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
    public const string CLEAR_COMMAND = 'vendor/bin/zephyrus cache:clear';
    public const string IGNORED_LOG_LINE = 'Zephyrus: ignored the boot cache %s: %s. Run ' . self::WARM_COMMAND
        . ' to rebuild it, or ' . self::CLEAR_COMMAND . ' to remove it.';

    private const string HEADER = "<?php\n\n// Written by " . self::WARM_COMMAND . ".\n";
    private const string FOREIGN_FILE = 'the file there was not written by ' . self::WARM_COMMAND
        . ', so it is left in place';
    private const string PACKAGE = 'zephyrus-framework/core';

    /** @var \Closure(string): (int|false) */
    private \Closure $ownerOf;

    /** @var \Closure(): int */
    private \Closure $processOwner;

    /**
     * @param string $path Absolute path of the cache file.
     * @param string $baseDirectory Real path of the directory the cache must stay under.
     * @param string $trustedSource Real path of the file whose owner is trusted alongside root.
     * @param (\Closure(string): (int|false))|null $ownerOf Reads the owner uid of a path, fileowner() by default.
     * @param (\Closure(): int)|null $processOwner Reads the uid of this process, AtomicFileWriter::processOwner()
     *                                            by default.
     */
    public function __construct(
        private string $path,
        private string $baseDirectory,
        private string $trustedSource,
        ?\Closure $ownerOf = null,
        ?\Closure $processOwner = null,
    ) {
        $this->ownerOf = $ownerOf ?? fileowner(...);
        $this->processOwner = $processOwner ?? AtomicFileWriter::processOwner(...);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The cached payload, or null when the file is absent (silently) or unusable (with one error_log line).
     *
     * @param \Closure(array<mixed>): string $fingerprint Computes the current fingerprint of the source from the cached
     *                                                    payload, only when the file exists and holds one.
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
            error_log(sprintf(self::IGNORED_LOG_LINE, $this->path, $refusal->getMessage()));

            return null;
        }
    }

    /**
     * Encode the payload under a header naming the format, the framework version and the fingerprint, and check that
     * this process may write the cache, without writing it.
     *
     * @param array<mixed> $payload
     * @return \Closure(): void Writes the cache, checking its target again.
     * @throws BootCacheException when the payload cannot be encoded, the application would ignore a cache this
     *                            process writes, the file in place was not written by cache:warm, or the file or a
     *                            directory above it is refused.
     */
    public function prepareStore(
        array $payload,
        string $fingerprint,
        int $mode = AtomicFileWriter::FILE_MODE,
        ?int $group = null,
    ): \Closure {
        $processOwner = ($this->processOwner)();
        if ($processOwner !== 0 && $processOwner !== ($this->ownerOf)($this->trustedSource)) {
            throw BootCacheException::refusedWrite($this->path, sprintf(
                'this process is neither root nor the owner of %s, so the application would ignore the cache: run %s '
                . 'as root (before USER in a Dockerfile) or as that owner',
                $this->trustedSource,
                self::WARM_COMMAND,
            ));
        }

        if ($this->holdsForeignFile()) {
            throw BootCacheException::refusedWrite($this->path, self::FOREIGN_FILE);
        }

        $contents = self::HEADER . sprintf(
            "\nreturn ['format' => %s, 'framework' => %s, 'fingerprint' => %s, 'payload' => %s];\n",
            var_export(self::FORMAT, true),
            var_export(self::frameworkVersion(), true),
            var_export($fingerprint, true),
            PhpValueEncoder::encode($payload),
        );

        return (new AtomicFileWriter($this->baseDirectory, $this->ownerOf))
            ->prepareWrite($this->path, $contents, $mode, $group, $processOwner);
    }

    /**
     * Write a file beside the cache, under the same guards, unless one is already there.
     *
     * @throws BootCacheException when the file cannot be written safely.
     */
    public function writeBesideIfAbsent(string $name, string $contents): void
    {
        $path = dirname($this->path) . '/' . $name;
        if (!file_exists($path) && !is_link($path)) {
            (new AtomicFileWriter($this->baseDirectory, $this->ownerOf))
                ->write($path, $contents, AtomicFileWriter::FILE_MODE, null, ($this->processOwner)());
        }
    }

    /**
     * Check that the cache file can be deleted, without deleting it.
     *
     * @return \Closure(): bool Deletes the file and returns whether one was deleted.
     * @throws BootCacheException when the file in place was not written by cache:warm or cannot be deleted safely.
     */
    public function prepareClear(): \Closure
    {
        if ($this->holdsForeignFile()) {
            throw BootCacheException::refusedRemoval($this->path, self::FOREIGN_FILE);
        }

        return (new AtomicFileWriter($this->baseDirectory, $this->ownerOf))->prepareRemove($this->path);
    }

    private function holdsForeignFile(): bool
    {
        return is_file($this->path) && !is_link($this->path)
            && @file_get_contents($this->path, length: strlen(self::HEADER)) !== self::HEADER;
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
     * @param \Closure(array<mixed>): string $fingerprint
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

        if (!is_array($cached['payload'] ?? null)) {
            throw new CacheRefusal('it does not return a boot cache array');
        }

        if (($cached['fingerprint'] ?? null) !== $fingerprint($cached['payload'])) {
            throw new CacheRefusal('it is stale, its source changed after it was written');
        }

        return $cached['payload'];
    }

    private static function frameworkVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion(self::PACKAGE) . '@' . InstalledVersions::getReference(self::PACKAGE);
        } catch (\OutOfBoundsException) {
            return 'unknown';
        }
    }
}
