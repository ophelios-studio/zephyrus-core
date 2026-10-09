<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Application;
use Zephyrus\Core\Bootstrap\ApplicationBootstrap;
use Zephyrus\Http\Request;

/**
 * The bootstrap reads its APP_* inputs through EnvironmentVariable, which
 * consults $_ENV before the process environment.
 */
final class ApplicationBootstrapEnvironmentSourceTest extends TestCase
{
    private const NAMES = ['APP_CONFIG_DIR', 'APP_CONFIG_BASE', 'APP_ENV', 'APP_CONFIG_EXTRA'];

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $dirs = [];

    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = __DIR__ . '/../../Fixtures/locales';
        $this->clearEnvironment();
    }

    protected function tearDown(): void
    {
        $this->clearEnvironment();

        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach ($this->dirs as $dir) {
            @rmdir($dir);
        }

        parent::tearDown();
    }

    public function testAppEnvFromEnvSuperglobalSelectsAnEnvironmentFile(): void
    {
        $dir = $this->newDir();
        $_ENV['APP_ENV'] = 'testing';

        $paths = ApplicationBootstrap::configPathsForDirectory($dir);

        self::assertContains($dir . '/app.testing.php', $paths['optional']);
    }

    public function testAppEnvFromTheProcessEnvironmentSelectsAnEnvironmentFile(): void
    {
        $dir = $this->newDir();
        putenv('APP_ENV=testing');

        $paths = ApplicationBootstrap::configPathsForDirectory($dir);

        self::assertContains($dir . '/app.testing.php', $paths['optional']);
    }

    public function testAppConfigDirFromEnvSuperglobalIsUsed(): void
    {
        $dir = $this->newDir();
        $this->writeConfig($dir, 'app', ['en', 'fr']);
        $_ENV['APP_CONFIG_DIR'] = $dir;

        self::assertTrue($this->frenchIsServed(ApplicationBootstrap::fromEnvironment()));
    }

    public function testAppConfigBaseFromEnvSuperglobalIsUsed(): void
    {
        $dir = $this->newDir();
        $this->writeConfig($dir, 'service', ['en', 'fr']);
        $_ENV['APP_CONFIG_DIR'] = $dir;
        $_ENV['APP_CONFIG_BASE'] = 'service';

        self::assertTrue($this->frenchIsServed(ApplicationBootstrap::fromEnvironment()));
    }

    public function testAppConfigExtraFromEnvSuperglobalIsUsed(): void
    {
        $dir = $this->newDir();
        $this->writeConfig($dir, 'app', ['en']);
        $this->writeConfig($dir, 'app.secrets', ['en', 'fr']);
        $_ENV['APP_CONFIG_DIR'] = $dir;
        $_ENV['APP_CONFIG_EXTRA'] = 'secrets';

        self::assertTrue($this->frenchIsServed(ApplicationBootstrap::fromEnvironment()));
    }

    /**
     * $_SERVER is not a bootstrap source: under mod_php it carries request data,
     * so a value there must not select an environment file.
     */
    public function testAppEnvSetOnlyInServerDoesNotSelectAnEnvironmentFile(): void
    {
        $dir = $this->newDir();
        $_SERVER['APP_ENV'] = 'testing';

        $paths = ApplicationBootstrap::configPathsForDirectory($dir);

        self::assertNotContains($dir . '/app.testing.php', $paths['optional']);
    }

    private function frenchIsServed(Application $app): bool
    {
        return $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
        ) === 'Bonjour';
    }

    private function newDir(): string
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-source-' . uniqid('', true);
        mkdir($dir, 0775, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    /**
     * @param list<string> $supportedLocales
     */
    private function writeConfig(string $dir, string $name, array $supportedLocales): void
    {
        $file = $dir . '/' . $name . '.php';
        file_put_contents($file, "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => $supportedLocales,
                'json_locale_paths' => [$this->fixtures],
            ],
        ], true) . ";\n");
        $this->files[] = $file;
    }

    private function clearEnvironment(): void
    {
        foreach (self::NAMES as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }
}
