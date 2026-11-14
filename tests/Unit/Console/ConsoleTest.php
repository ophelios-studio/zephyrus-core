<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Console\Console;
use Zephyrus\Core\Cache\ConfigurationCache;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Routing\Router;
use Zephyrus\Tests\Unit\Core\Cache\CacheDirectory;
use Zephyrus\Tests\Unit\Routing\ControllerTree;

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
        self::assertSame(
            sprintf(
                "Wrote %1\$s/var/zephyrus/config.yml.php\nNo router: section in %1\$s/config.yml, so no routes cache was "
                . "written\n",
                $this->project->path,
            ),
            $this->printed(),
        );
        self::assertFileExists($this->project->path . '/var/zephyrus/config.yml.php');
    }

    public function testWarmWritesOneCachePerConfigAndReadsRelativePathsFromTheWorkingDirectory(): void
    {
        $exitCode = $this->console(workingDirectory: $this->project->path . '/config')
            ->run(['cache:warm', '--config=tenant.yml', '--config=' . $this->project->path . '/config.yml']);

        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertSame(
            sprintf(
                "Wrote %1\$s/config/var/zephyrus/tenant.yml.php\n"
                . "No router: section in %1\$s/config/tenant.yml, so no routes cache was written\n"
                . "Wrote %1\$s/var/zephyrus/config.yml.php\n"
                . "No router: section in %1\$s/config.yml, so no routes cache was written\n",
                $this->project->path,
            ),
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
                "cache:warm failed for %1\$s/config.yml: Cannot write the boot cache %1\$s/var/zephyrus/config.yml.php: "
                . "%1\$s/var/zephyrus is writable by its group or by others: run chmod g-w,o-w %1\$s/var/zephyrus.\n",
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
        self::assertSame(
            "Wrote {$cache}\nNo router: section in {$this->project->path}/config.yml, so no routes cache was written\n"
            . "Removed {$cache}\nNothing to remove at {$cache}\n",
            $this->printed(),
        );
        self::assertFileDoesNotExist($cache);
    }

    public function testWarmWritesTheRoutesCacheOfTheRouterSectionAfterTheConfigurationCache(): void
    {
        $this->project->file('config.yml', $this->routerSection());

        $exitCode = $this->console()->run(['cache:warm']);

        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertSame(
            sprintf(
                "Wrote %1\$s/var/zephyrus/config.yml.php\nWrote %1\$s/var/zephyrus/routes.php (1 route)\n",
                $this->project->path,
            ),
            $this->printed(),
        );
        self::assertFileExists($this->project->path . '/var/zephyrus/routes.php');
    }

    public function testClearRemovesTheRoutesCacheToo(): void
    {
        $this->project->file('config.yml', $this->routerSection());
        $this->console()->run(['cache:warm']);

        $exitCode = $this->console()->run(['cache:clear']);

        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertStringEndsWith(
            sprintf(
                "Removed %1\$s/var/zephyrus/config.yml.php\nRemoved %1\$s/var/zephyrus/routes.php\n",
                $this->project->path,
            ),
            $this->printed(),
        );
        self::assertSame(
            ['config', 'config/tenant.yml', 'config.yml', 'var', 'var/zephyrus', 'var/zephyrus/.gitignore'],
            $this->project->entries(),
        );
    }

    public function testAnEnvTagInTheRouterSectionIsRefusedWithoutReadingTheEnvironment(): void
    {
        $this->project->file(
            'config.yml',
            "router:\n  controllers:\n    App\\Controllers: !env CONTROLLERS_DIRECTORY\n",
        );

        $exitCode = $this->console()->run(['cache:warm']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %s/config.yml: Configuration section 'router' field 'controllers' holds a value "
                . 'under the !env tag, but vendor/bin/zephyrus cache:warm reads this section without the environment: '
                . "write the value itself.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame(['config', 'config/tenant.yml', 'config.yml'], $this->project->entries());
    }

    public function testAnEnvTagOnTheWholeRouterSectionIsRefusedWithoutReadingTheEnvironment(): void
    {
        $this->project->file('config.yml', "router: !env ROUTER_SECTION\n");

        $exitCode = $this->console()->run(['cache:warm']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %s/config.yml: Configuration section 'router' is written under the !env tag, but "
                . "vendor/bin/zephyrus cache:warm reads this section without the environment: write its values in the "
                . "file.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame(['config', 'config/tenant.yml', 'config.yml'], $this->project->entries());
    }

    public function testARefusedConfigurationLeavesTheCachesOfEveryConfigurationUnwritten(): void
    {
        $this->project->file('config.yml', "router:\n  controllers:\n    App\\Controllers: app/Controllers\n");

        $exitCode = $this->console(workingDirectory: $this->project->path)
            ->run(['cache:warm', '--config=config/tenant.yml', '--config=config.yml']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %1\$s/config.yml: Configuration section 'router' field 'controllers' has invalid "
                . 'value "app/Controllers": %1$s/app/Controllers, the directory of App\Controllers, is not a '
                . "directory.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame('', $this->printed());
        self::assertSame(['config', 'config/tenant.yml', 'config.yml'], $this->project->entries());
    }

    public function testARouterSectionOutsideTheConfigYmlOfTheRootIsRefused(): void
    {
        $this->project->file('config/tenant.yml', $this->routerSection());

        $exitCode = $this->console()->run(['cache:warm', '--config=' . $this->project->path . '/config/tenant.yml']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                'cache:warm failed for %s/config/tenant.yml: Configuration file tenant.yml declares router:, but '
                . "router: is cached only from config.yml at the application root: move the router: section to that "
                . "file.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
    }

    public function testControllersWithoutAnyRouteFailTheWarm(): void
    {
        $tree = new ControllerTree();
        $tree->configure();
        $tree->controller('EmptyController', "final class EmptyController\n{\n}");
        $root = $tree->root->path;

        try {
            $exitCode = (new Console($this->elsewhere->path, $root, $this->output, $this->errorOutput))
                ->run(['cache:warm']);
            $written = file_exists($root . '/var');
        } finally {
            $tree->remove();
        }

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                'cache:warm failed for %1$s/config.yml: The controllers of router.controllers declare no route, so the '
                . 'routes cache %1$s/var/zephyrus/routes.php was not written: check that each namespace matches its '
                . "directory.\n",
                $root,
            ),
            $this->printedErrors(),
        );
        self::assertFalse($written);
    }

    public function testTwoCachesAtTheSamePathAreRefusedBeforeAnythingIsWritten(): void
    {
        $this->project->file('config.yml', $this->routerSection('var/zephyrus/config.yml.php'));

        $exitCode = $this->console()->run(['cache:warm']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %1\$s/config.yml: Two caches would be written to %1\$s/var/zephyrus/config.yml.php: "
                . "give router.cache another path.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame('', $this->printed());
        self::assertSame(['config', 'config/tenant.yml', 'config.yml'], $this->project->entries());
    }

    public function testACacheWrittenUnderAnotherCacheIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->project->file('config.yml', $this->routerSection('var/zephyrus/config.yml.php/routes.php'));

        $exitCode = $this->console()->run(['cache:warm']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %1\$s/config.yml: The cache %1\$s/var/zephyrus/config.yml.php/routes.php would be "
                . "written under the cache %1\$s/var/zephyrus/config.yml.php: give router.cache another path.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame('', $this->printed());
        self::assertSame(['config', 'config/tenant.yml', 'config.yml'], $this->project->entries());
    }

    public function testACacheAboveAnotherCacheIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->project->file('p/config.yml', $this->routerSection('real/var/zephyrus/config.yml.php/x.php'));
        $this->project->file('p/real/config.yml', "application: {}\n");

        $exitCode = $this->console()->run([
            'cache:warm',
            '--config=' . $this->project->path . '/p/config.yml',
            '--config=' . $this->project->path . '/p/real/config.yml',
        ]);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %1\$s/p/real/config.yml: The cache %1\$s/p/real/var/zephyrus/config.yml.php/x.php "
                . "would be written under the cache %1\$s/p/real/var/zephyrus/config.yml.php: give router.cache another "
                . "path.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame('', $this->printed());
        self::assertSame(
            ['config', 'config/tenant.yml', 'config.yml', 'p', 'p/config.yml', 'p/real', 'p/real/config.yml'],
            $this->project->entries(),
        );
    }

    public function testACacheAtTheGitIgnoreOfTheCacheDirectoryIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->project->file('config.yml', $this->routerSection('var/zephyrus/.gitignore/r.php'));

        $exitCode = $this->console()->run(['cache:warm']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %1\$s/config.yml: The cache %1\$s/var/zephyrus/.gitignore/r.php would be written "
                . "under the cache %1\$s/var/zephyrus/.gitignore: give router.cache another path.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame('', $this->printed());
        self::assertSame(['config', 'config/tenant.yml', 'config.yml'], $this->project->entries());
    }

    public function testTwoConfigsInTheSameDirectoryShareTheGitIgnore(): void
    {
        $this->project->file('other.yml', "application: {}\n");

        $exitCode = $this->console()->run([
            'cache:warm',
            '--config=' . $this->project->path . '/config.yml',
            '--config=' . $this->project->path . '/other.yml',
        ]);

        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertFileExists($this->project->path . '/var/zephyrus/other.yml.php');
    }

    public function testTheSameConfigGivenTwiceIsWarmedOnce(): void
    {
        $exitCode = $this->console($this->project->path)->run([
            'cache:warm',
            '--config=' . $this->project->path . '/config.yml',
            '--config=config.yml',
        ]);

        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertSame(
            sprintf(
                "Wrote %1\$s/var/zephyrus/config.yml.php\nNo router: section in %1\$s/config.yml, so no routes cache was "
                . "written\n",
                $this->project->path,
            ),
            $this->printed(),
        );
        self::assertFileExists($this->project->path . '/var/zephyrus/config.yml.php');
    }

    public function testTheSameConfigGivenTwiceIsClearedOnce(): void
    {
        $this->console()->run(['cache:warm']);
        $before = strlen($this->printed());

        $exitCode = $this->console()->run([
            'cache:clear',
            '--config=' . $this->project->path . '/config.yml',
            '--config=../' . basename($this->project->path) . '/config.yml',
        ]);

        self::assertSame(Console::SUCCESS, $exitCode);
        self::assertSame(
            sprintf("Removed %s/var/zephyrus/config.yml.php\n", $this->project->path),
            substr($this->printed(), $before),
        );
        self::assertFileDoesNotExist($this->project->path . '/var/zephyrus/config.yml.php');
    }

    public function testASecondConfigRefusedByADirectoryGuardLeavesTheFirstCacheUnwritten(): void
    {
        $this->project->directory('config/var/zephyrus', 0o777);

        $exitCode = $this->console()->run([
            'cache:warm',
            '--config=' . $this->project->path . '/config.yml',
            '--config=' . $this->project->path . '/config/tenant.yml',
        ]);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %1\$s/config/tenant.yml: Cannot write the boot cache "
                . "%1\$s/config/var/zephyrus/tenant.yml.php: %1\$s/config/var/zephyrus is writable by its group or by "
                . "others: run chmod g-w,o-w %1\$s/config/var/zephyrus.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame('', $this->printed());
        self::assertFileDoesNotExist($this->project->path . '/var/zephyrus/config.yml.php');
    }

    public function testClearRefusingTheSecondFileKeepsTheFirstCache(): void
    {
        $arguments = ['--config=' . $this->project->path . '/config.yml', '--config=' . $this->project->path . '/config/tenant.yml'];
        $this->console()->run(['cache:warm', ...$arguments]);
        $foreign = $this->project->file('config/var/zephyrus/tenant.yml.php', "<?php\nreturn [];\n");

        $exitCode = $this->console()->run(['cache:clear', ...$arguments]);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:clear failed for %1\$s/config/tenant.yml: Cannot remove the boot cache %2\$s: the file there was "
                . "not written by vendor/bin/zephyrus cache:warm, so it is left in place.\n",
                $this->project->path,
                $foreign,
            ),
            $this->printedErrors(),
        );
        self::assertFileExists($this->project->path . '/var/zephyrus/config.yml.php');
        self::assertFileExists($foreign);
    }

    public function testAConfigYmlThatIsASymbolicLinkIsCachedWhereTheApplicationReadsIt(): void
    {
        $this->elsewhere->file('config.yml', "application:\n  environment: production\n" . $this->routerSection());
        $root = new CacheDirectory();
        $log = $root->path . '/php.log';
        $previousLog = ini_set('error_log', $log);
        symlink($this->elsewhere->path . '/config.yml', $root->path . '/config.yml');

        try {
            $exitCode = $this->console()->run(['cache:warm', '--config=' . $root->path . '/config.yml']);
            $router = Router::fromConfiguration(Configuration::fromFile($root->path . '/config.yml'), $root->path);

            self::assertSame(Console::SUCCESS, $exitCode);
            self::assertSame(['config.yml'], $this->elsewhere->entries());
            self::assertGreaterThan(0, $router->count());
            self::assertFileDoesNotExist($log);
        } finally {
            ini_set('error_log', (string) $previousLog);
            $root->remove();
        }
    }

    public function testARoutesCacheFromAConfigOutsideTheRootNamesTheRuleAndTheRootItResolvedAgainst(): void
    {
        $this->project->file('config/config.yml', "router:\n  controllers:\n    App\\Controllers: app/Controllers\n");

        $exitCode = $this->console()->run(['cache:warm', '--config=' . $this->project->path . '/config/config.yml']);

        self::assertSame(Console::REFUSED, $exitCode);
        self::assertSame(
            sprintf(
                "cache:warm failed for %1\$s/config/config.yml: Configuration section 'router' field 'controllers' has "
                . 'invalid value "app/Controllers": %1$s/config/app/Controllers, the directory of App\Controllers, is not '
                . 'a directory. Note that router: is cached only from config.yml at the application root, and its paths '
                . "were resolved against %1\$s/config.\n",
                $this->project->path,
            ),
            $this->printedErrors(),
        );
        self::assertSame('', $this->printed());
        self::assertFileDoesNotExist($this->project->path . '/config/var');
    }

    private function routerSection(?string $cache = null): string
    {
        return sprintf(
            "router:\n  controllers:\n    Zephyrus\\Tests\\Fixtures\\ConfiguredControllers: %s\n%s",
            realpath(__DIR__ . '/../../Fixtures/ConfiguredControllers'),
            $cache === null ? '' : "  cache: $cache\n",
        );
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
            'an option that only starts like config' => [['cache:warm', '--configuration=config.yml'], 'Unknown option "--configuration=config.yml".'],
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
