<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Cache;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Cache\ConfigurationCache;
use Zephyrus\Core\Config\ConfigurationException;

final class ConfigurationCacheTest extends TestCase
{
    private CacheDirectory $directory;
    private CacheDirectory $outside;

    protected function setUp(): void
    {
        $this->directory = new CacheDirectory();
        $this->outside = new CacheDirectory();
    }

    protected function tearDown(): void
    {
        $this->directory->remove();
        $this->outside->remove();
    }

    public function testTheCacheIsNamedAfterTheSourceInVarZephyrusBesideItsRealPath(): void
    {
        $source = $this->directory->file('config.yml', "application: {}\n");
        symlink($source, $this->outside->path . '/linked.yml');

        $cache = ConfigurationCache::forSource($this->outside->path . '/linked.yml');

        self::assertSame($this->directory->path . '/var/zephyrus/config.yml.php', $cache->path());
    }

    public function testWarmGivesTheCacheTheModeAndGroupOfItsSourceWithoutAnyWriteBit(): void
    {
        $source = $this->directory->file('config.yml', "application: {}\n", 0o640);

        $cache = ConfigurationCache::forSource($source);
        $cache->warm();

        clearstatcache();
        self::assertSame(0o440, fileperms($cache->path()) & 0o7777);
        self::assertSame(filegroup($source), filegroup($cache->path()));
    }

    public function testAMissingSourceIsRefused(): void
    {
        try {
            ConfigurationCache::forSource($this->directory->path . '/missing.yml');
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame('Configuration file not found: missing.yml', $e->getMessage());
        }
    }
}
