<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Bootstrap\ApplicationBootstrap;
use Zephyrus\Http\Request;

final class ApplicationBootstrapTest extends TestCase
{
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
}
