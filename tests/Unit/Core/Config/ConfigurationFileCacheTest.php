<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Cache\ConfigurationCache;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationFile;
use Zephyrus\Tests\Support\CapturedErrorLog;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

final class ConfigurationFileCacheTest extends TestCase
{
    use CapturedErrorLog;

    private const string VARIABLE = 'ZEPHYRUS_CACHE_TEST_TOKEN';
    private const string YAML = <<<'YAML'
        application:
          environment: development
        reporting:
          defaults: &defaults
            region: ca-central
            token: !env ZEPHYRUS_CACHE_TEST_TOKEN, unset
          daily:
            <<: *defaults
            ratio: 0.25
          recipients: [ops@example.com, !env ZEPHYRUS_CACHE_TEST_TOKEN]
          label: from-yaml

        YAML;

    private CacheDirectory $directory;
    private string $source;

    protected function setUp(): void
    {
        $this->captureErrorLog();
        $this->directory = new CacheDirectory();
        $this->source = $this->directory->file('config.yml', self::YAML);
        $_ENV[self::VARIABLE] = 'before-warm';
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::VARIABLE]);
        $this->directory->remove();
        $this->releaseErrorLog();
    }

    public function testAWarmedCacheIsReadInsteadOfTheYaml(): void
    {
        $cache = ConfigurationCache::forSource($this->source);
        $cache->warm();
        $this->directory->file(
            'var/zephyrus/config.yml.php',
            str_replace("'from-yaml'", "'from-cache'", (string) file_get_contents($cache->path())),
            0o444,
        );

        $label = (new ConfigurationFile($this->source))->read('reporting')['label'];

        self::assertSame('from-cache', $label);
        self::assertSame('', $this->capturedErrorLog());
    }

    public function testTheCachedConfigurationEqualsTheParsedOneAndResolvesTheEnvironmentOnEveryRead(): void
    {
        $parsed = $this->configuration();

        ConfigurationCache::forSource($this->source)->warm();
        $cached = $this->configuration();
        $_ENV[self::VARIABLE] = 'after-warm';
        $changed = $this->configuration();

        self::assertSame($parsed->toArray(revealSecrets: true), $cached->toArray(revealSecrets: true));
        self::assertSame($parsed->section('reporting')?->toArray(), $cached->section('reporting')?->toArray());
        self::assertSame('after-warm', $changed->section('reporting')?->getString('daily.token'));
        self::assertSame(['ops@example.com', 'after-warm'], $changed->section('reporting')?->get('recipients'));
        self::assertSame('', $this->capturedErrorLog());
    }

    public function testARefusedCacheIsNeverIncludedAndTheYamlIsParsed(): void
    {
        $marker = $this->directory->path . '/included';
        $cachePath = $this->directory->file(
            'var/zephyrus/config.yml.php',
            sprintf("<?php touch(%s); return [];\n", var_export($marker, true)),
            0o664,
        );

        $label = (new ConfigurationFile($this->source))->read('reporting')['label'];

        self::assertSame('from-yaml', $label);
        self::assertFileDoesNotExist($marker);
        self::assertStringEndsWith(
            sprintf(
                "Zephyrus: ignored the boot cache %s: %s is writable by its group or by others. Run vendor/bin/zephyrus cache:warm to rebuild it, or vendor/bin/zephyrus cache:clear to remove it.\n",
                $cachePath,
                $cachePath,
            ),
            $this->capturedErrorLog(),
        );
    }

    public function testAnEditedSourceIsParsedAgainRatherThanServedFromAStaleCache(): void
    {
        $cache = ConfigurationCache::forSource($this->source);
        $cache->warm();
        $this->directory->file('config.yml', str_replace('from-yaml', 'edited', self::YAML));

        $label = (new ConfigurationFile($this->source))->read('reporting')['label'];

        self::assertSame('edited', $label);
        self::assertStringEndsWith(
            sprintf(
                "Zephyrus: ignored the boot cache %s: it is stale, its source changed after it was written. Run vendor/bin/zephyrus cache:warm to rebuild it, or vendor/bin/zephyrus cache:clear to remove it.\n",
                $cache->path(),
            ),
            $this->capturedErrorLog(),
        );
    }

    private function configuration(): Configuration
    {
        return Configuration::fromYamlFile($this->source, ['reporting' => ReportingSection::class]);
    }
}

final class ReportingSection extends ConfigSection
{
    public static function fromArray(array $values): static
    {
        return new static($values);
    }
}
