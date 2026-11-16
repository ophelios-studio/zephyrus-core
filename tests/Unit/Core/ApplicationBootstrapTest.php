<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Application;
use Zephyrus\Core\Bootstrap\ApplicationBootstrap;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Http\Request;

final class ApplicationBootstrapTest extends TestCase
{
    public function testFromConfigFilesWithoutPathsBuildsDefaultApplication(): void
    {
        $app = ApplicationBootstrap::fromConfigFiles();

        self::assertInstanceOf(Application::class, $app);
        self::assertSame('missing.key', $app->trans('missing.key'));
    }

    public function testFromConfigFilesBuildsLocalizedApplicationFromRequiredFile(): void
    {
        $required = sys_get_temp_dir() . '/zephyrus-bootstrap-required-' . bin2hex(random_bytes(8)) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        file_put_contents($required, "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBootstrap::fromConfigFiles([$required]);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($required);
        }
    }

    public function testFromConfigFilesAppliesOptionalOverridesWhenPresent(): void
    {
        $required = sys_get_temp_dir() . '/zephyrus-bootstrap-required-' . bin2hex(random_bytes(8)) . '.php';
        $optional = sys_get_temp_dir() . '/zephyrus-bootstrap-optional-' . bin2hex(random_bytes(8)) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        file_put_contents($required, "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        file_put_contents($optional, "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBootstrap::fromConfigFiles([$required], [$optional]);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($required);
            @unlink($optional);
        }
    }

    public function testFromConfigFilesIgnoresMissingOptionalFiles(): void
    {
        $required = sys_get_temp_dir() . '/zephyrus-bootstrap-required-' . bin2hex(random_bytes(8)) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        file_put_contents($required, "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        $missingOptional = sys_get_temp_dir() . '/zephyrus-bootstrap-missing-' . bin2hex(random_bytes(8)) . '.php';

        try {
            $app = ApplicationBootstrap::fromConfigFiles([$required], [$missingOptional]);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($required);
        }
    }

    public static function leadingBlanks(): array
    {
        return [
            'no blank' => [''],
            'leading space' => [' '],
            'leading tab' => ["\t"],
        ];
    }

    #[DataProvider('leadingBlanks')]
    public function testFromConfigFilesRefusesAnOptionalStreamWrapperUrlWithoutTouchingIt(string $prefix): void
    {
        $spy = new class {
            public static int $calls = 0;
            /** @var resource|null */
            public $context;

            public function url_stat(string $path, int $flags): array|false
            {
                self::$calls++;

                return false;
            }
        };
        $scheme = 'zspy' . bin2hex(random_bytes(4));
        stream_wrapper_register($scheme, $spy::class);

        try {
            ApplicationBootstrap::fromConfigFiles([], [$prefix . $scheme . '://host/c.yml']);
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame('Configuration file must be a local path, not a stream wrapper URL (' . $scheme . '://)', $e->getMessage());
            self::assertSame(0, $spy::$calls);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testFromConfigFilesSkipsAnOptionalPathHoldingANulByte(): void
    {
        $app = ApplicationBootstrap::fromConfigFiles([], ["/tmp/a\0b.php"]);

        self::assertInstanceOf(Application::class, $app);
    }

    public function testFromConfigurationArrayBuildsApplication(): void
    {
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        $app = ApplicationBootstrap::fromConfigurationArray([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'json_locale_paths' => [$fixturePath],
            ],
        ]);

        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
        ));
    }

    public function testFromConfigurationFileBuildsApplication(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-bootstrap-file-' . bin2hex(random_bytes(8)) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBootstrap::fromConfigurationFile($path);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($path);
        }
    }

    public function testFromConfigDirectoryLoadsBaseAndLocalOverride(): void
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-dir-' . bin2hex(random_bytes(8));
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/app.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        file_put_contents($dir . '/app.local.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBootstrap::fromConfigDirectory($dir);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($dir . '/app.php');
            @unlink($dir . '/app.local.php');
            @rmdir($dir);
        }
    }

    public function testFromConfigDirectoryLoadsEnvironmentSpecificOverride(): void
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-envdir-' . bin2hex(random_bytes(8));
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/app.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        file_put_contents($dir . '/app.testing.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        $original = getenv('APP_ENV');
        putenv('APP_ENV=testing');

        try {
            $app = ApplicationBootstrap::fromConfigDirectory($dir);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            putenv($original === false ? 'APP_ENV' : 'APP_ENV=' . $original);
            @unlink($dir . '/app.php');
            @unlink($dir . '/app.testing.php');
            @rmdir($dir);
        }
    }

    public function testFromConfigDirectoryUsesExplicitEnvironmentOverride(): void
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-explicit-env-' . bin2hex(random_bytes(8));
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/app.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        file_put_contents($dir . '/app.qa.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBootstrap::fromConfigDirectory($dir, environment: 'qa');

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($dir . '/app.php');
            @unlink($dir . '/app.qa.php');
            @rmdir($dir);
        }
    }

    public function testFromConfigDirectoryCanDisableEnvironmentOverride(): void
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-disable-env-' . bin2hex(random_bytes(8));
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/app.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        file_put_contents($dir . '/app.testing.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        $original = getenv('APP_ENV');
        putenv('APP_ENV=testing');

        try {
            $app = ApplicationBootstrap::fromConfigDirectory($dir, environment: '');

            self::assertSame('Hello', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            putenv($original === false ? 'APP_ENV' : 'APP_ENV=' . $original);
            @unlink($dir . '/app.php');
            @unlink($dir . '/app.testing.php');
            @rmdir($dir);
        }
    }

    public function testFromEnvironmentBuildsUsingConfiguredEnvironmentVariables(): void
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-envvars-' . bin2hex(random_bytes(8));
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/service.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        file_put_contents($dir . '/service.testing.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        $originalDir = getenv('APP_CONFIG_DIR');
        $originalBase = getenv('APP_CONFIG_BASE');
        $originalEnv = getenv('APP_ENV');
        $originalExtra = getenv('APP_CONFIG_EXTRA');

        putenv('APP_CONFIG_DIR=' . $dir);
        putenv('APP_CONFIG_BASE=service');
        putenv('APP_ENV=testing');
        putenv('APP_CONFIG_EXTRA=');

        try {
            $app = ApplicationBootstrap::fromEnvironment();

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            putenv($originalDir === false ? 'APP_CONFIG_DIR' : 'APP_CONFIG_DIR=' . $originalDir);
            putenv($originalBase === false ? 'APP_CONFIG_BASE' : 'APP_CONFIG_BASE=' . $originalBase);
            putenv($originalEnv === false ? 'APP_ENV' : 'APP_ENV=' . $originalEnv);
            putenv($originalExtra === false ? 'APP_CONFIG_EXTRA' : 'APP_CONFIG_EXTRA=' . $originalExtra);
            @unlink($dir . '/service.php');
            @unlink($dir . '/service.testing.php');
            @rmdir($dir);
        }
    }

    public function testFromEnvironmentAppliesExtraOptionalLayers(): void
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-envextra-' . bin2hex(random_bytes(8));
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/service.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        file_put_contents($dir . '/service.secrets.php', "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        $originalDir = getenv('APP_CONFIG_DIR');
        $originalBase = getenv('APP_CONFIG_BASE');
        $originalEnv = getenv('APP_ENV');
        $originalExtra = getenv('APP_CONFIG_EXTRA');

        putenv('APP_CONFIG_DIR=' . $dir);
        putenv('APP_CONFIG_BASE=service');
        putenv('APP_ENV=');
        putenv('APP_CONFIG_EXTRA=secrets, secrets');

        try {
            $app = ApplicationBootstrap::fromEnvironment();

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            putenv($originalDir === false ? 'APP_CONFIG_DIR' : 'APP_CONFIG_DIR=' . $originalDir);
            putenv($originalBase === false ? 'APP_CONFIG_BASE' : 'APP_CONFIG_BASE=' . $originalBase);
            putenv($originalEnv === false ? 'APP_ENV' : 'APP_ENV=' . $originalEnv);
            putenv($originalExtra === false ? 'APP_CONFIG_EXTRA' : 'APP_CONFIG_EXTRA=' . $originalExtra);
            @unlink($dir . '/service.php');
            @unlink($dir . '/service.secrets.php');
            @rmdir($dir);
        }
    }

    public function testConfigPathsForDirectoryBuildsExpectedDefaultPaths(): void
    {
        $paths = ApplicationBootstrap::configPathsForDirectory('/tmp/config');

        self::assertSame('/tmp/config/app.php', $paths['required']);
        self::assertSame(['/tmp/config/app.local.php'], $paths['optional']);
    }

    public function testConfigPathsForDirectoryIncludesEnvironmentSpecificOverride(): void
    {
        $paths = ApplicationBootstrap::configPathsForDirectory('/tmp/config', environment: 'staging');

        self::assertSame('/tmp/config/app.php', $paths['required']);
        self::assertSame([
            '/tmp/config/app.local.php',
            '/tmp/config/app.staging.php',
        ], $paths['optional']);
    }

    public function testConfigPathsForDirectoryIncludesExtraOptionalNames(): void
    {
        $paths = ApplicationBootstrap::configPathsForDirectory(
            '/tmp/config',
            extraOptionalNames: ['secrets', 'region.eu'],
        );

        self::assertSame([
            '/tmp/config/app.local.php',
            '/tmp/config/app.secrets.php',
            '/tmp/config/app.region.eu.php',
        ], $paths['optional']);
    }

    public function testConfigPathsForDirectoryDeDuplicatesOptionalPathCollisions(): void
    {
        $paths = ApplicationBootstrap::configPathsForDirectory(
            '/tmp/config',
            environment: 'local',
            extraOptionalNames: ['local', 'local', 'region.eu'],
        );

        self::assertSame([
            '/tmp/config/app.local.php',
            '/tmp/config/app.region.eu.php',
        ], $paths['optional']);
    }

    public function testFromResolvedPathsBuildsApplicationFromProvidedGroups(): void
    {
        $required = sys_get_temp_dir() . '/zephyrus-bootstrap-resolved-required-' . bin2hex(random_bytes(8)) . '.php';
        $optional = sys_get_temp_dir() . '/zephyrus-bootstrap-resolved-optional-' . bin2hex(random_bytes(8)) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        file_put_contents($required, "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        file_put_contents($optional, "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBootstrap::fromResolvedPaths([
                'required' => $required,
                'optional' => [$optional],
            ]);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($required);
            @unlink($optional);
        }
    }

    public function testTheConfigDirectoryEntryPointAppliesTheLayoutRules(): void
    {
        try {
            ApplicationBootstrap::fromConfigDirectory('/tmp', baseName: '../app');
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame('Config base name must not contain path separators.', $e->getMessage());
        }
    }
}
