<?php

declare(strict_types=1);

namespace Zephyrus\Core\Cache;

use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\ConfigurationFile;

/**
 * The cached parse of a YAML configuration file, at var/zephyrus/<file name>.php beside it. It holds the YAML before
 * any tag is resolved, so !env values are read from the environment on every load, never written to disk.
 *
 * @internal
 */
final readonly class ConfigurationCache implements WarmableCache
{
    public const string DIRECTORY = 'var/zephyrus';
    private const string FINGERPRINT_ALGORITHM = 'xxh128';

    private function __construct(
        private string $source,
        private BootCache $cache,
    ) {
    }

    /**
     * @param (\Closure(string): (int|false))|null $ownerOf Reads the owner uid of a path, fileowner() by default.
     * @throws ConfigurationException when the source is not a file.
     */
    public static function forSource(string $sourcePath, ?\Closure $ownerOf = null): self
    {
        $source = realpath($sourcePath);
        if ($source === false || !is_file($source)) {
            throw ConfigurationException::fileNotFound($sourcePath);
        }

        $baseDirectory = dirname($source);
        $path = sprintf('%s/%s/%s.php', rtrim($baseDirectory, '/'), self::DIRECTORY, basename($source));

        return new self($source, new BootCache($path, $baseDirectory, $source, $ownerOf));
    }

    public function path(): string
    {
        return $this->cache->path();
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
     * Write the cache with the source's mode, minus every write bit, and its group.
     *
     * @throws ConfigurationException when the source cannot be parsed.
     * @throws BootCacheException when the cache cannot be written safely.
     */
    public function warm(): string
    {
        // Fingerprint before parsing: a source edited meanwhile then reads as stale, never as fresh.
        $fingerprint = $this->fingerprint();
        $permissions = fileperms($this->source);
        $group = filegroup($this->source);

        $this->cache->store(
            ConfigurationFile::parseYaml($this->source),
            $fingerprint,
            $permissions === false ? 0o400 : $permissions & 0o444,
            $group === false ? null : $group,
        );

        return 'Wrote ' . $this->path();
    }

    public function clear(): bool
    {
        return $this->cache->clear();
    }

    private function fingerprint(): string
    {
        return (string) hash_file(self::FINGERPRINT_ALGORITHM, $this->source);
    }
}
