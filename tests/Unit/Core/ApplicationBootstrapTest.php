<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Application;
use Zephyrus\Core\Bootstrap\ApplicationBootstrap;
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
}
