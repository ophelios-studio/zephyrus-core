<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

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
        $required = sys_get_temp_dir() . '/zephyrus-bootstrap-required-' . uniqid('', true) . '.php';
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
        $required = sys_get_temp_dir() . '/zephyrus-bootstrap-required-' . uniqid('', true) . '.php';
        $optional = sys_get_temp_dir() . '/zephyrus-bootstrap-optional-' . uniqid('', true) . '.php';
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
        $required = sys_get_temp_dir() . '/zephyrus-bootstrap-required-' . uniqid('', true) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        file_put_contents($required, "<?php\n\nreturn " . var_export([
            'localization' => [
                'default_locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'json_locale_paths' => [$fixturePath],
            ],
        ], true) . ";\n");

        $missingOptional = sys_get_temp_dir() . '/zephyrus-bootstrap-missing-' . uniqid('', true) . '.php';

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
        $path = sys_get_temp_dir() . '/zephyrus-bootstrap-file-' . uniqid('', true) . '.php';
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
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-dir-' . uniqid('', true);
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
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-envdir-' . uniqid('', true);
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
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-explicit-env-' . uniqid('', true);
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
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-disable-env-' . uniqid('', true);
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
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-envvars-' . uniqid('', true);
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
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-envextra-' . uniqid('', true);
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
        $required = sys_get_temp_dir() . '/zephyrus-bootstrap-resolved-required-' . uniqid('', true) . '.php';
        $optional = sys_get_temp_dir() . '/zephyrus-bootstrap-resolved-optional-' . uniqid('', true) . '.php';
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

    public function testFromResolvedPathsRejectsMissingRequiredEntry(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromResolvedPaths([
            'optional' => ['/tmp/app.local.php'],
        ]);
    }

    public function testFromResolvedPathsRejectsNonArrayOptionalEntry(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromResolvedPaths([
            'required' => '/tmp/app.php',
            'optional' => 'invalid',
        ]);
    }

    public function testFromResolvedPathsRejectsNonStringOptionalEntries(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromResolvedPaths([
            'required' => '/tmp/app.php',
            'optional' => ['/tmp/app.local.php', 123],
        ]);
    }

    public function testFromResolvedPathsRejectsEmptyOptionalEntries(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromResolvedPaths([
            'required' => '/tmp/app.php',
            'optional' => ['   '],
        ]);
    }

    public function testFromEnvironmentRejectsInvalidExtraOptionalNames(): void
    {
        $dir = sys_get_temp_dir() . '/zephyrus-bootstrap-envinvalid-' . uniqid('', true);
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/app.php', "<?php return [];\n");

        $originalDir = getenv('APP_CONFIG_DIR');
        $originalExtra = getenv('APP_CONFIG_EXTRA');

        putenv('APP_CONFIG_DIR=' . $dir);
        putenv('APP_CONFIG_EXTRA=../secret');

        try {
            $this->expectException(ConfigurationException::class);
            ApplicationBootstrap::fromEnvironment();
        } finally {
            putenv($originalDir === false ? 'APP_CONFIG_DIR' : 'APP_CONFIG_DIR=' . $originalDir);
            putenv($originalExtra === false ? 'APP_CONFIG_EXTRA' : 'APP_CONFIG_EXTRA=' . $originalExtra);
            @unlink($dir . '/app.php');
            @rmdir($dir);
        }
    }

    public function testFromConfigDirectoryRejectsOptionalNamesWithPathSeparators(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromConfigDirectory('/tmp', extraOptionalNames: ['../secret']);
    }

    public function testFromConfigDirectoryRejectsEmptyOptionalNames(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromConfigDirectory('/tmp', extraOptionalNames: ['']);
    }

    public function testFromConfigDirectoryRejectsEmptyDirectory(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromConfigDirectory('   ');
    }

    public function testFromConfigDirectoryRejectsEmptyBaseName(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromConfigDirectory('/tmp', baseName: '');
    }

    public function testFromConfigDirectoryRejectsBaseNameWithPathSeparator(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBootstrap::fromConfigDirectory('/tmp', baseName: '../app');
    }
}
