<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Console\Console;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;

final class ConsoleTest extends TestCase
{
    private const string USAGE = "Usage:\n"
        . "  vendor/bin/zephyrus cache:warm [--config=PATH]...\n"
        . "  vendor/bin/zephyrus cache:clear [--config=PATH]...\n"
        . "PATH defaults to config.yml at the project root; a relative PATH is read from the current directory.\n";

    private CacheDirectory $project;
    private CacheDirectory $elsewhere;

    /** @var resource */
    private $output;

    /** @var resource */
    private $errorOutput;

    protected function setUp(): void
    {
        $this->project = new CacheDirectory();
        $this->elsewhere = new CacheDirectory();
        $this->project->file('config.yml', "application: {}\n");
        $this->project->file('config/tenant.yml', "application: {}\n");
        $this->output = fopen('php://memory', 'w+');
        $this->errorOutput = fopen('php://memory', 'w+');
    }

    protected function tearDown(): void
    {
        $this->project->remove();
        $this->elsewhere->remove();
    }

    public function testWarmWithoutConfigWritesTheCacheOfTheProjectRootConfig(): void
    {
        $exitCode = $this->console()->run(['cache:warm']);

        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertSame('Wrote ' . $this->project->path . "/var/zephyrus/config.yml.php\n", $this->printed());
        self::assertFileExists($this->project->path . '/var/zephyrus/config.yml.php');
    }

    public function testWarmWritesOneCachePerConfigAndReadsRelativePathsFromTheWorkingDirectory(): void
    {
        $exitCode = $this->console(workingDirectory: $this->project->path . '/config')
            ->run(['cache:warm', '--config=tenant.yml', '--config=' . $this->project->path . '/config.yml']);

        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertSame(
            'Wrote ' . $this->project->path . "/config/var/zephyrus/tenant.yml.php\n"
            . 'Wrote ' . $this->project->path . "/var/zephyrus/config.yml.php\n",
            $this->printed(),
        );
        self::assertSame('', $this->printedErrors());
    }

    public function testAMissingConfigIsRefusedWithExitCodeOne(): void
    {
        $exitCode = $this->console()->run(['cache:warm', '--config=missing.yml']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            'cache:warm failed for ' . $this->elsewhere->path . "/missing.yml: Configuration file not found: missing.yml\n",
            $this->printedErrors(),
        );
    }

    public function testAnUnsafeCacheDirectoryIsRefusedWithExitCodeOne(): void
    {
        $this->project->directory('var/zephyrus', 0o777);

        $exitCode = $this->console()->run(['cache:warm']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %1\$s/config.yml: Cannot write the boot cache %1\$s/var/zephyrus/config.yml.php: %1\$s/var/zephyrus is writable by its group or by others.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
    }

    public function testClearRemovesTheCacheAndSucceedsWhenItIsAlreadyAbsent(): void
    {
        $this->console()->run(['cache:warm']);
        $cache = $this->project->path . '/var/zephyrus/config.yml.php';

        $first = $this->console()->run(['cache:clear']);
        $second = $this->console()->run(['cache:clear']);

        self::assertSame([Console::SUCCESS, Console::SUCCESS], [$first, $second]);
        self::assertSame("Wrote {$cache}\nRemoved {$cache}\nNothing to remove at {$cache}\n", $this->printed());
        self::assertFileDoesNotExist($cache);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function usageErrors(): array
    {
        return [
            'no command' => [[], 'No command given.'],
            'unknown command' => [['cache:purge'], 'Unknown command "cache:purge".'],
            'unknown option' => [['cache:warm', '--force'], 'Unknown option "--force".'],
            'config without a path' => [['cache:clear', '--config'], 'The --config option needs a path, as in --config=PATH.'],
            'config with an empty path' => [['cache:warm', '--config='], 'The --config option needs a path, as in --config=PATH.'],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('usageErrors')]
    public function testAUsageErrorExitsWithCodeTwoAndPrintsTheUsage(array $arguments, string $message): void
    {
        $exitCode = $this->console()->run($arguments);

        self::assertSame(Console::USAGE_ERROR, $exitCode);
        self::assertSame($message . "\n" . self::USAGE, $this->printedErrors());
        self::assertSame('', $this->printed());
        self::assertSame(['config', 'config/tenant.yml', 'config.yml'], $this->project->entries());
    }

    private function console(?string $workingDirectory = null): Console
    {
        return new Console($workingDirectory ?? $this->elsewhere->path, $this->project->path, $this->output, $this->errorOutput);
    }

    private function printed(): string
    {
        return (string) stream_get_contents($this->output, offset: 0);
    }

    private function printedErrors(): string
    {
        return (string) stream_get_contents($this->errorOutput, offset: 0);
    }
}
