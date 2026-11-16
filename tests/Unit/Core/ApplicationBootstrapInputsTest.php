<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Bootstrap\ApplicationBootstrap;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Http\Request;

final class ApplicationBootstrapInputsTest extends TestCase
{
    private const NAMES = ['APP_CONFIG_DIR', 'APP_CONFIG_BASE', 'APP_ENV', 'APP_CONFIG_EXTRA'];

    /** @var array<string, string|false> */
    private array $processEnvironment = [];

    /** @var array<string, mixed> */
    private array $superglobalEnvironment = [];

    private string|false $workingDirectory;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        App::reset();
        $this->workingDirectory = getcwd();
        foreach (self::NAMES as $name) {
            $this->processEnvironment[$name] = getenv($name);
            if (array_key_exists($name, $_ENV)) {
                $this->superglobalEnvironment[$name] = $_ENV[$name];
            }
            unset($_ENV[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        if ($this->workingDirectory !== false) {
            chdir($this->workingDirectory);
        }
        foreach (self::NAMES as $name) {
            unset($_ENV[$name]);
            if (array_key_exists($name, $this->superglobalEnvironment)) {
                $_ENV[$name] = $this->superglobalEnvironment[$name];
            }
            $original = $this->processEnvironment[$name];
            putenv($original === false ? $name : $name . '=' . $original);
        }
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach (array_reverse($this->dirs) as $dir) {
            @rmdir($dir);
        }
        App::reset();

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedResolvedPaths(): array
    {
        return [
            'a missing required entry' => [
                ['optional' => ['/tmp/app.local.php']],
                'Resolved config paths must include a non-empty "required" entry.',
            ],
            'a blank required entry' => [
                ['required' => "  \t "],
                'Resolved config paths must include a non-empty "required" entry.',
            ],
            'a required entry that is not a string' => [
                ['required' => 123],
                'Resolved config paths must include a non-empty "required" entry.',
            ],
            'an optional entry that is not an array' => [
                ['required' => '/tmp/app.php', 'optional' => 'invalid'],
                'Resolved config "optional" entry must be an array of file paths.',
            ],
            'an optional path that is not a string' => [
                ['required' => '/tmp/app.php', 'optional' => ['/tmp/app.local.php', 123]],
                'Resolved optional path at index 1 must be a string.',
            ],
            'a blank optional path' => [
                ['required' => '/tmp/app.php', 'optional' => ['/tmp/a.php', '   ']],
                'Resolved optional path at index 1 must not be empty.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $paths
     */
    #[DataProvider('refusedResolvedPaths')]
    public function testResolvedPathsAreRefusedWithTheRuleThatWasBroken(array $paths, string $message): void
    {
        try {
            ApplicationBootstrap::fromResolvedPaths($paths);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>, 3: string}>
     */
    public static function refusedDirectoryInputs(): array
    {
        return [
            'a blank directory' => [" \t", 'app', [], 'Config directory must not be empty.'],
            'a blank base name' => ['/tmp/c', "  \t", [], 'Config base name must not be empty.'],
            'a base name with a slash' => ['/tmp/c', 'a/b', [], 'Config base name must not contain path separators.'],
            'a base name with a backslash' => ['/tmp/c', 'a\\b', [], 'Config base name must not contain path separators.'],
            'an optional name that is not a string' => ['/tmp/c', 'app', ['ok', 5], 'Optional config name at index 1 must be a string.'],
            'a blank optional name' => ['/tmp/c', 'app', ['ok', ' '], 'Optional config name at index 1 must not be empty.'],
            'an optional name with a slash' => ['/tmp/c', 'app', ['ok', '../x'], 'Optional config name at index 1 must not contain path separators.'],
            'an optional name with a backslash' => ['/tmp/c', 'app', ['ok', 'a\\b'], 'Optional config name at index 1 must not contain path separators.'],
        ];
    }

    /**
     * @param array<int, mixed> $extra
     */
    #[DataProvider('refusedDirectoryInputs')]
    public function testADirectoryLayoutInputIsRefusedWithTheRuleThatWasBroken(
        string $dir,
        string $base,
        array $extra,
        string $message,
    ): void {
        try {
            ApplicationBootstrap::configPathsForDirectory($dir, $base, '', $extra);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testAnEnvironmentNameWithAPathSeparatorIsRefusedWithTheRuleThatWasBroken(): void
    {
        foreach (['staging/eu', 'staging\\eu'] as $environment) {
            try {
                ApplicationBootstrap::configPathsForDirectory('/tmp/c', 'app', $environment);
                self::fail('expected a refusal');
            } catch (ConfigurationException $e) {
                self::assertSame('Config environment name must not contain path separators.', $e->getMessage());
            }
        }
    }

    public function testTheDirectoryBaseNameAndNamesAreTrimmedAndTheTrailingSeparatorsDropped(): void
    {
        $paths = ApplicationBootstrap::configPathsForDirectory(
            configDir: " /srv/conf/\\/ ",
            baseName: ' svc ',
            environment: ' prod ',
            extraOptionalNames: [' extra '],
        );

        self::assertSame([
            'required' => '/srv/conf/svc.php',
            'optional' => [
                '/srv/conf/svc.local.php',
                '/srv/conf/svc.extra.php',
                '/srv/conf/svc.prod.php',
            ],
        ], $paths);
    }

    public function testTheEnvironmentVariablesAreTrimmedAndBlankOnesFallBackToTheDefaults(): void
    {
        $dir = $this->newDir();
        $this->writeConfig($dir . '/app.php', ['en']);
        $this->writeConfig($dir . '/app.extra.php', ['en', 'fr']);
        putenv('APP_CONFIG_DIR=  ' . $dir . '  ');
        putenv('APP_CONFIG_BASE=   ');
        putenv('APP_CONFIG_EXTRA= extra ');

        $app = ApplicationBootstrap::fromEnvironment();

        self::assertSame('Bonjour', $this->frenchGreeting($app));
    }

    public function testABlankConfigDirectoryVariableFallsBackToTheConfigFolderOfTheWorkingDirectory(): void
    {
        $dir = $this->newDir();
        mkdir($dir . '/config');
        $this->dirs[] = $dir . '/config';
        $this->writeConfig($dir . '/config/app.php', ['en', 'fr']);
        chdir($dir);
        putenv('APP_CONFIG_DIR=   ');

        $app = ApplicationBootstrap::fromEnvironment();

        self::assertSame('Bonjour', $this->frenchGreeting($app));
    }

    public function testBlankItemsInTheExtraNamesVariableAreIgnored(): void
    {
        $dir = $this->newDir();
        $this->writeConfig($dir . '/app.php', ['en']);
        $this->writeConfig($dir . '/app.a.php', ['en']);
        $this->writeConfig($dir . '/app.b.php', ['en', 'fr']);
        putenv('APP_CONFIG_DIR=' . $dir);
        putenv('APP_CONFIG_EXTRA=a,,  , b');

        $app = ApplicationBootstrap::fromEnvironment();

        self::assertSame('Bonjour', $this->frenchGreeting($app));
    }

    public function testAnInvalidExtraNameIsReportedAtItsPositionAmongTheNonBlankNames(): void
    {
        putenv('APP_CONFIG_DIR=/tmp/c');
        putenv('APP_CONFIG_EXTRA=a,,../b');

        try {
            ApplicationBootstrap::fromEnvironment();
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame('Optional config name at index 1 must not contain path separators.', $e->getMessage());
        }
    }

    public function testResolvedPathsAreTrimmedBeforeTheFilesAreLoaded(): void
    {
        $dir = $this->newDir();
        $this->writeConfig($dir . '/app.php', ['en']);
        $this->writeConfig($dir . '/app.local.php', ['en', 'fr']);

        $app = ApplicationBootstrap::fromResolvedPaths([
            'required' => ' ' . $dir . '/app.php ',
            'optional' => [' ' . $dir . '/app.local.php '],
        ]);

        self::assertSame('Bonjour', $this->frenchGreeting($app));
    }

    private function frenchGreeting(\Zephyrus\Core\Application $app): string
    {
        return $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
        );
    }

    private function newDir(): string
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-inputs-' . bin2hex(random_bytes(8));
        mkdir($dir, 0775, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    /**
     * @param list<string> $supportedLocales
     */
    private function writeConfig(string $file, array $supportedLocales): void
    {
        file_put_contents($file, "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => $supportedLocales,
                'json_locale_paths' => [__DIR__ . '/../../Fixtures/locales'],
            ],
        ], true) . ";\n");
        $this->files[] = $file;
    }
}
