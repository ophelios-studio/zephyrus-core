<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testALinkedSourceIsCachedBesideTheLinkNotBesideItsTarget(): void
    {
        $source = $this->directory->file('config.yml', "application: {}\n");
        symlink($source, $this->outside->path . '/linked.yml');

        $cache = ConfigurationCache::forSource($this->outside->path . '/linked.yml');

        self::assertSame($this->outside->path . '/var/zephyrus/linked.yml.php', $cache->path());
    }

    #[DataProvider('sourceModes')]
    public function testWarmGivesTheCacheTheReadBitsOfItsSourceOnly(int $sourceMode, int $cacheMode): void
    {
        $source = $this->directory->file('config.yml', "application: {}\n", $sourceMode);

        $cache = ConfigurationCache::forSource($source);
        $cache->prepare()();

        clearstatcache();
        self::assertSame($cacheMode, fileperms($cache->path()) & 0o7777);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function sourceModes(): iterable
    {
        yield 'a source others cannot read' => [0o640, 0o440];
        yield 'a source anyone may write and run' => [0o777, 0o444];
    }

    public function testWarmGivesTheCacheTheGroupOfItsSource(): void
    {
        $source = $this->directory->file('config.yml', "application: {}\n", 0o640);
        $groups = function_exists('posix_getgroups') ? (posix_getgroups() ?: []) : [];
        $otherGroups = array_values(array_diff($groups, [filegroup($source)]));
        if ($otherGroups === []) {
            self::markTestSkipped('This process belongs to no group other than the one its files get by default.');
        }
        chgrp($source, $otherGroups[0]);

        $cache = ConfigurationCache::forSource($source);
        $cache->prepare()();

        clearstatcache();
        self::assertSame($otherGroups[0], filegroup($cache->path()));
    }

    public function testWarmKeepsTheCacheDirectoryOutOfGit(): void
    {
        $source = $this->directory->file('config.yml', "application: {}\n");

        ConfigurationCache::forSource($source)->prepare()();

        self::assertSame("*\n", file_get_contents($this->directory->path . '/var/zephyrus/.gitignore'));
    }

    public function testWarmLeavesAGitignoreAlreadyThereAsItIs(): void
    {
        $source = $this->directory->file('config.yml', "application: {}\n");
        $ignore = $this->directory->file('var/zephyrus/.gitignore', "/routes.php\n");

        ConfigurationCache::forSource($source)->prepare()();

        self::assertSame("/routes.php\n", file_get_contents($ignore));
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
