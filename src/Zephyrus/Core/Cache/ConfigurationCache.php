<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\ConfigurationFile;

/**
 * The cached parse of a YAML configuration file, at var/zephyrus/<file name>.php beside it. It holds the YAML before
 * any tag is resolved, so !env values are read from the environment on every load, never written to disk. Warming
 * also writes var/zephyrus/.gitignore, so the cache stays out of the repository.
 *
 * @internal
 */
final readonly class ConfigurationCache implements WarmableCache
{
    public const string DIRECTORY = 'var/zephyrus';
    private const string FINGERPRINT_ALGORITHM = 'xxh128';
    public const string GIT_IGNORE = '.gitignore';

    private function __construct(
        private string $source,
        private BootCache $cache,
    ) {
    }

    /**
     * The directory of the path given, with its links resolved, is the root of the cache; the file itself is not
     * resolved, so a linked config.yml is cached beside the link, where the application root is.
     *
     * @param (\Closure(string): (int|false))|null $ownerOf Reads the owner uid of a path, fileowner() by default.
     * @throws ConfigurationException when the source is not a file.
     */
    public static function forSource(string $sourcePath, ?\Closure $ownerOf = null): self
    {
        $baseDirectory = realpath(dirname($sourcePath));
        $source = $baseDirectory === false ? '' : rtrim($baseDirectory, '/') . '/' . basename($sourcePath);
        if ($baseDirectory === false || !is_file($source)) {
            throw ConfigurationException::fileNotFound($sourcePath);
        }

        $path = sprintf('%s/%s/%s.php', rtrim($baseDirectory, '/'), self::DIRECTORY, basename($source));

        return new self($source, new BootCache($path, $baseDirectory, $source, $ownerOf));
    }

    public function path(): string
    {
        return $this->cache->path();
    }

    /**
     * The configuration file under the real path of its directory.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * The parsed YAML with its tags unresolved, or null when there is no usable cache.
     *
     * @return array<string, mixed>|null
     */
    public function load(): ?array
    {
        /** @var array<string, mixed>|null */
        return $this->cache->load($this->fingerprint(...));
    }

    /**
     * Parse the source; the closure writes the cache with the source's mode, minus every write bit, and its group.
     *
     * @return \Closure(): string
     * @throws ConfigurationException when the source cannot be parsed.
     * @throws BootCacheException when the cache cannot be written safely.
     */
    public function prepare(): \Closure
    {
        // Fingerprint before parsing: a source edited meanwhile then reads as stale, never as fresh.
        $fingerprint = $this->fingerprint();
        $permissions = fileperms($this->source);
        $group = filegroup($this->source);

        $store = $this->cache->prepareStore(
            ConfigurationFile::parseYaml($this->source),
            $fingerprint,
            $permissions === false ? 0o400 : $permissions & 0o444,
            $group === false ? null : $group,
        );

        return function () use ($store): string {
            $store();
            $this->cache->writeBesideIfAbsent(self::GIT_IGNORE, "*\n");

            return 'Wrote ' . $this->path();
        };
    }

    public function prepareClear(): \Closure
    {
        return $this->cache->prepareClear();
    }

    private function fingerprint(): string
    {
        return (string) hash_file(self::FINGERPRINT_ALGORITHM, $this->source);
    }
}
