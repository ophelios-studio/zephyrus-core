<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Console\Console;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

final class ZephyrusBinaryTest extends TestCase
{
    private const string CANARY = 's3cr3t';
    private const string YAML = <<<'YAML'
        application:
          environment: !env CANARY
        defaults: &defaults
          token: !env CANARY, local-fallback
          plain: !env CANARY
        merged:
          <<: *defaults
          extra: 1
        aliased: *defaults
        single: &secret !env CANARY
        again: *secret
        list: [!env CANARY, plain-item]
        custom: !custom
          nested: !env CANARY

        YAML;

    private CacheDirectory $project;
    private CacheDirectory $elsewhere;

    protected function setUp(): void
    {
        $this->project = new CacheDirectory();
        $this->elsewhere = new CacheDirectory();
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        $this->elsewhere->remove();
    }

    public function testWarmNeverWritesNorPrintsTheValueOfAnEnvironmentVariable(): void
    {
        $config = $this->project->file('config.yml', self::YAML);

        [$exitCode, $output, $errors] = $this->runBinary(['cache:warm', '--config=' . $config], ['CANARY' => self::CANARY]);

        $cache = (string) file_get_contents($this->project->path . '/var/zephyrus/config.yml.php');
        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertSame(
            sprintf(
                "Wrote %1\$s/var/zephyrus/config.yml.php\nNo router: section in %1\$s/config.yml, so no routes cache was "
                . "written\n",
                $this->project->path,
            ),
            $output,
        );
        self::assertSame('', $errors);
        self::assertStringNotContainsString(self::CANARY, $cache . $output . $errors);
        self::assertStringContainsString('local-fallback', $cache);
    }

    public function testWithoutConfigTheBinaryTargetsTheConfigOfTheRootPackageFromAnyDirectory(): void
    {
        $root = dirname(__DIR__, 2);

        [$exitCode, $output, $errors] = $this->runBinary(['cache:warm']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame('', $output);
        self::assertSame("cache:warm failed for {$root}/config.yml: Configuration file not found: config.yml\n", $errors);
    }

    public function testTheBinaryExitsWithTheUsageErrorCode(): void
    {
        [$exitCode] = $this->runBinary(['cache:purge']);

        self::assertSame(Console::USAGE_ERROR, $exitCode);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{int, string, string}
     */
    private function runBinary(array $arguments, array $environment = []): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/zephyrus', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->elsewhere->path,
            $environment,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $output, $errors];
    }
}
