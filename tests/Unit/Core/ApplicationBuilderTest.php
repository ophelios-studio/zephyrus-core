<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Application;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\LocalizationConfig;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Routing\Router;

final class ApplicationBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        App::reset();
    }

    public function testBuildReturnsApplication(): void
    {
        $app = ApplicationBuilder::create()->build();

        self::assertInstanceOf(Application::class, $app);
    }

    public function testBuildRegistersTranslatorInAppRegistry(): void
    {
        $app = ApplicationBuilder::create()
            ->withJsonLocales(__DIR__ . '/../../Fixtures/locales', defaultLocale: 'en')
            ->build();

        self::assertNotNull(App::getTranslator());
        self::assertSame('Bonjour', localize('messages.plain', locale: 'fr'));
        self::assertSame('Hello', $app->trans('messages.plain', locale: 'en'));
    }

    public function testBuildRegistersFormatterInAppRegistry(): void
    {
        ApplicationBuilder::create()
            ->withJsonLocales(__DIR__ . '/../../Fixtures/locales', defaultLocale: 'en')
            ->build();

        $formatter = App::getFormatter();
        self::assertNotNull($formatter);
        self::assertSame('en', $formatter->getLocale());
    }

    public function testBuildRegistersFormatterWithConfiguredLocale(): void
    {
        ApplicationBuilder::create()
            ->withConfigurationArray([
                'localization' => [
                    'locale' => 'fr',
                    'locale_path' => __DIR__ . '/../../Fixtures/locales',
                ],
            ])
            ->build();

        $formatter = App::getFormatter();
        self::assertNotNull($formatter);
        self::assertSame('fr', $formatter->getLocale());
    }

    public function testBuildRegistersFormatterWithConfiguredCurrency(): void
    {
        ApplicationBuilder::create()
            ->withConfigurationArray([
                'localization' => [
                    'locale' => 'en',
                    'currency' => 'CAD',
                    'locale_path' => __DIR__ . '/../../Fixtures/locales',
                ],
            ])
            ->build();

        $formatter = App::getFormatter();
        self::assertNotNull($formatter);
        self::assertSame('CAD', $formatter->getDefaultCurrency());
    }

    public function testBuildFormatterHasNullCurrencyWhenNotConfigured(): void
    {
        ApplicationBuilder::create()
            ->withConfigurationArray([
                'localization' => [
                    'locale' => 'en',
                    'locale_path' => __DIR__ . '/../../Fixtures/locales',
                ],
            ])
            ->build();

        $formatter = App::getFormatter();
        self::assertNotNull($formatter);
        self::assertNull($formatter->getDefaultCurrency());
    }

    public function testBuildFormatterUsesConfiguredDateTimeFormats(): void
    {
        ApplicationBuilder::create()
            ->withConfigurationArray([
                'localization' => [
                    'locale' => 'en',
                    'locale_path' => __DIR__ . '/../../Fixtures/locales',
                    'date_format' => 'yyyy-MM-dd',
                    'time_format' => 'HH:mm',
                    'datetime_format' => 'long',
                ],
            ])
            ->build();

        $formatter = App::getFormatter();
        self::assertNotNull($formatter);
        self::assertSame('yyyy-MM-dd', $formatter->getDefaultDatePattern());
        self::assertSame('HH:mm', $formatter->getDefaultTimePattern());
        self::assertSame('long', $formatter->getDefaultDatetimePattern());
    }

    public function testWithConfigurationResolvesRelativeLocalePathWithBasePath(): void
    {
        // The locale fixture directory is at tests/Fixtures/locales.
        // Pass a relative path and a basePath that makes it resolve correctly.
        $fixturesDir = __DIR__ . '/../../Fixtures';

        $app = ApplicationBuilder::create()
            ->withConfiguration(
                Configuration::fromArray([
                    'localization' => [
                        'locale' => 'en',
                        'locale_path' => 'locales',
                    ],
                ]),
                basePath: $fixturesDir,
            )
            ->build();

        self::assertSame('Hello', $app->trans('messages.plain', locale: 'en'));
    }

    public function testWithLocalizationConfigResolvesRelativePathWithBasePath(): void
    {
        $fixturesDir = __DIR__ . '/../../Fixtures';

        ApplicationBuilder::create()
            ->withLocalizationConfig(
                LocalizationConfig::fromArray([
                    'locale' => 'fr',
                    'locale_path' => 'locales',
                ]),
                basePath: $fixturesDir,
            )
            ->build();

        self::assertSame('Bonjour', localize('messages.plain'));
    }

    public function testWithLocalizationConfigAbsolutePathIgnoresBasePath(): void
    {
        $absolutePath = __DIR__ . '/../../Fixtures/locales';

        ApplicationBuilder::create()
            ->withLocalizationConfig(
                LocalizationConfig::fromArray([
                    'locale' => 'en',
                    'locale_path' => $absolutePath,
                ]),
                basePath: '/some/other/directory',
            )
            ->build();

        self::assertSame('Hello', localize('messages.plain'));
    }

    public function testBuildRegistersConfigurationInAppRegistryWhenProvided(): void
    {
        ApplicationBuilder::create()
            ->withConfigurationArray([
                'localization' => [
                    'locale' => 'en',
                    'supported_locales' => ['en', 'fr'],
                    'locale_path' => __DIR__ . '/../../Fixtures/locales',
                ],
            ])
            ->build();

        self::assertNotNull(App::getConfiguration());
        self::assertSame('en', config('localization', 'locale'));
    }

    public function testFromConfigurationFactoryAppliesLocalizationConfig(): void
    {
        $builder = ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'localization' => [
                'locale' => 'en',
                'supportedLocales' => ['en', 'fr'],
                'localePath' => __DIR__ . '/../../Fixtures/locales',
            ],
        ]));

        $app = $builder->build();

        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr-CA,fr;q=0.9,en;q=0.8']),
        ));
    }

    public function testFromConfigurationArrayFactoryAppliesLocalizationConfig(): void
    {
        $app = ApplicationBuilder::fromConfigurationArray([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'locale_path' => __DIR__ . '/../../Fixtures/locales',
            ],
        ])->build();

        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr-CA,fr;q=0.9,en;q=0.8']),
        ));
    }

    public function testBuildFromConfigurationFactoryReturnsReadyApplication(): void
    {
        $app = ApplicationBuilder::buildFromConfiguration(Configuration::fromArray([
            'localization' => [
                'locale' => 'en',
                'supportedLocales' => ['en', 'fr'],
                'localePath' => __DIR__ . '/../../Fixtures/locales',
            ],
        ]));

        self::assertInstanceOf(Application::class, $app);
        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
        ));
    }

    public function testBuildFromConfigurationArrayFactoryReturnsReadyApplication(): void
    {
        $app = ApplicationBuilder::buildFromConfigurationArray([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'locale_path' => __DIR__ . '/../../Fixtures/locales',
            ],
        ]);

        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
        ));
    }

    public function testHandleDelegatesToKernel(): void
    {
        $router = (new Router())->get('/health', ApplicationBuilderFixtureController::class . '@health');

        $app = ApplicationBuilder::create()
            ->withRouter($router)
            ->build();

        $response = $app->handle(Request::fromArray('GET', '/health'));

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testWithJsonLocalesWiresTranslator(): void
    {
        $app = ApplicationBuilder::create()
            ->withJsonLocales(__DIR__ . '/../../Fixtures/locales', defaultLocale: 'en')
            ->build();

        self::assertSame('Bonjour', $app->trans('messages.plain', locale: 'fr'));
        self::assertSame('Welcome Bob', $app->trans('messages.welcome', ['name' => 'Bob'], 'fr'));
    }

    public function testWithFallbackLoadersMergesLoadersLastWins(): void
    {
        $base = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return $locale === 'en' ? ['app.label' => 'Base', 'app.only' => 'Only Base'] : [];
            }
        };
        $override = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return $locale === 'en' ? ['app.label' => 'Override'] : [];
            }
        };

        $app = ApplicationBuilder::create()
            ->withFallbackLoaders([$base, $override], defaultLocale: 'en')
            ->build();

        self::assertSame('Override', $app->trans('app.label'));    // second loader wins
        self::assertSame('Only Base', $app->trans('app.only'));    // only in first loader
    }

    public function testWithJsonLocaleLayersMergesDirectoryCatalogsLastWins(): void
    {
        $root = sys_get_temp_dir() . '/zephyrus-json-layers-' . uniqid('', true);
        $basePath = $root . '/base';
        $overridePath = $root . '/override';
        mkdir($basePath, 0775, true);
        mkdir($overridePath, 0775, true);

        file_put_contents($basePath . '/en.json', json_encode([
            'app' => ['label' => 'Base', 'only' => 'From Base'],
            'errors' => ['required' => 'Required'],
        ], JSON_THROW_ON_ERROR));

        file_put_contents($overridePath . '/en.json', json_encode([
            'app' => ['label' => 'Override'],
        ], JSON_THROW_ON_ERROR));

        try {
            $app = ApplicationBuilder::create()
                ->withJsonLocaleLayers([$basePath, $overridePath], defaultLocale: 'en')
                ->build();

            self::assertSame('Override', $app->trans('app.label'));
            self::assertSame('From Base', $app->trans('app.only'));
            self::assertSame('Required', $app->trans('errors.required'));
        } finally {
            @unlink($basePath . '/en.json');
            @unlink($overridePath . '/en.json');
            @rmdir($basePath);
            @rmdir($overridePath);
            @rmdir($root);
        }
    }

    public function testWithJsonLocaleLayersWithNoPathsKeepsTranslatorOperational(): void
    {
        $app = ApplicationBuilder::create()
            ->withJsonLocaleLayers([], defaultLocale: 'en')
            ->build();

        self::assertSame('missing.key', $app->trans('missing.key'));
    }

    public function testWithLocaleLoaderOverridesDefaultTranslatorSource(): void
    {
        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return ['greet' => $locale === 'es' ? 'Hola {name}' : 'Hello {name}'];
            }
        };

        $app = ApplicationBuilder::create()
            ->withLocaleLoader($loader, defaultLocale: 'en')
            ->build();

        self::assertSame('Hola Alice', $app->trans('greet', ['name' => 'Alice'], 'es'));
    }

    public function testWithLocalizationConfigWiresLocalePathAndSupportedLocales(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocalizationConfig(new LocalizationConfig(
                locale: 'en',
                supportedLocales: ['en', 'fr'],
                localePath: __DIR__ . '/../../Fixtures/locales',
            ))
            ->build();

        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr-CA,fr;q=0.9,en;q=0.8']),
        ));
    }

    public function testWithLocalizationConfigWithoutPathUsesRequestedDefaultLocale(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocalizationConfig(new LocalizationConfig(
                locale: 'fr',
                supportedLocales: [],
            ))
            ->build();

        self::assertSame('missing.key', $app->trans('missing.key'));
        self::assertSame('fr', $app->resolveLocaleFromRequest(requestedLocale: 'fr'));
    }

    public function testWithConfigurationAppliesLocalizationSection(): void
    {
        $configuration = Configuration::fromArray([
            'localization' => [
                'locale' => 'en',
                'supportedLocales' => ['en', 'fr'],
                'localePath' => __DIR__ . '/../../Fixtures/locales',
            ],
        ]);

        $app = ApplicationBuilder::create()
            ->withConfiguration($configuration)
            ->build();

        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr-CA,fr;q=0.9,en;q=0.8']),
        ));
    }

    public function testWithConfigurationArrayParsesAndAppliesLocalization(): void
    {
        $app = ApplicationBuilder::create()
            ->withConfigurationArray([
                'localization' => [
                    'locale' => 'en',
                    'supported_locales' => ['en', 'fr'],
                    'locale_path' => __DIR__ . '/../../Fixtures/locales',
                ],
            ])
            ->build();

        self::assertSame('Bonjour', $app->transFromRequest(
            'messages.plain',
            request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr-CA,fr;q=0.9,en;q=0.8']),
        ));
    }

    public function testWithConfigurationFileParsesAndAppliesLocalization(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-app-config-' . uniqid('', true) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'locale_path' => $fixturePath,
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBuilder::create()
                ->withConfigurationFile($path)
                ->build();

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr-CA,fr;q=0.9,en;q=0.8']),
            ));
        } finally {
            @unlink($path);
        }
    }

    public function testFromConfigurationFileFactoryParsesAndAppliesLocalization(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-app-config-static-' . uniqid('', true) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'locale_path' => $fixturePath,
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBuilder::fromConfigurationFile($path)->build();

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr-CA,fr;q=0.9,en;q=0.8']),
            ));
        } finally {
            @unlink($path);
        }
    }

    public function testBuildFromConfigurationFileFactoryParsesAndBuildsApplication(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-app-config-build-' . uniqid('', true) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        file_put_contents($path, "<?php\n\nreturn " . var_export([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'locale_path' => $fixturePath,
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBuilder::buildFromConfigurationFile($path);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($path);
        }
    }

    public function testWithConfigurationFilesMergesAndAppliesLocalization(): void
    {
        $basePath = sys_get_temp_dir() . '/zephyrus-app-config-base-' . uniqid('', true) . '.php';
        $envPath = sys_get_temp_dir() . '/zephyrus-app-config-env-' . uniqid('', true) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        file_put_contents($basePath, "<?php\n\nreturn " . var_export([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en'],
                'locale_path' => $fixturePath,
            ],
        ], true) . ";\n");

        file_put_contents($envPath, "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBuilder::create()
                ->withConfigurationFiles([$basePath, $envPath])
                ->build();

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($basePath);
            @unlink($envPath);
        }
    }

    public function testBuildFromConfigurationFilesFactoryBuildsMergedConfiguration(): void
    {
        $basePath = sys_get_temp_dir() . '/zephyrus-app-config-base2-' . uniqid('', true) . '.php';
        $envPath = sys_get_temp_dir() . '/zephyrus-app-config-env2-' . uniqid('', true) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';

        file_put_contents($basePath, "<?php\n\nreturn " . var_export([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en'],
                'locale_path' => $fixturePath,
            ],
        ], true) . ";\n");

        file_put_contents($envPath, "<?php\n\nreturn " . var_export([
            'localization' => [
                'supported_locales' => ['en', 'fr'],
            ],
        ], true) . ";\n");

        try {
            $app = ApplicationBuilder::buildFromConfigurationFiles([$basePath, $envPath]);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($basePath);
            @unlink($envPath);
        }
    }

    public function testWithOptionalConfigurationFilesIgnoresMissingOverrides(): void
    {
        $basePath = sys_get_temp_dir() . '/zephyrus-app-config-opt-base-' . uniqid('', true) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        file_put_contents($basePath, "<?php\n\nreturn " . var_export([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'locale_path' => $fixturePath,
            ],
        ], true) . ";\n");

        $missingPath = sys_get_temp_dir() . '/zephyrus-app-config-opt-missing-' . uniqid('', true) . '.php';

        try {
            $app = ApplicationBuilder::create()
                ->withOptionalConfigurationFiles([$basePath, $missingPath])
                ->build();

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($basePath);
        }
    }

    public function testBuildFromOptionalConfigurationFilesIgnoresMissingOverrides(): void
    {
        $basePath = sys_get_temp_dir() . '/zephyrus-app-config-opt2-base-' . uniqid('', true) . '.php';
        $fixturePath = __DIR__ . '/../../Fixtures/locales';
        file_put_contents($basePath, "<?php\n\nreturn " . var_export([
            'localization' => [
                'locale' => 'en',
                'supported_locales' => ['en', 'fr'],
                'locale_path' => $fixturePath,
            ],
        ], true) . ";\n");

        $missingPath = sys_get_temp_dir() . '/zephyrus-app-config-opt2-missing-' . uniqid('', true) . '.php';

        try {
            $app = ApplicationBuilder::buildFromOptionalConfigurationFiles([$basePath, $missingPath]);

            self::assertSame('Bonjour', $app->transFromRequest(
                'messages.plain',
                request: Request::fromArray('GET', '/', headers: ['accept-language' => 'fr']),
            ));
        } finally {
            @unlink($basePath);
        }
    }

    public function testWithConfigurationFileThrowsWhenFileMissing(): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationBuilder::create()->withConfigurationFile('/tmp/does-not-exist-' . uniqid('', true) . '.php');
    }

    public function testWithConfigurationFileThrowsWhenFileDoesNotReturnArray(): void
    {
        $path = sys_get_temp_dir() . '/zephyrus-app-config-invalid-' . uniqid('', true) . '.php';
        file_put_contents($path, "<?php return 'invalid';");

        try {
            $this->expectException(ConfigurationException::class);
            ApplicationBuilder::create()->withConfigurationFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testBuildWiresTimezoneFromConfiguration(): void
    {
        $originalTz = date_default_timezone_get();

        try {
            ApplicationBuilder::create()
                ->withConfigurationArray([
                    'localization' => ['timezone' => 'America/New_York'],
                ])
                ->build();

            self::assertSame('America/New_York', date_default_timezone_get());
        } finally {
            date_default_timezone_set($originalTz);
        }
    }

    public function testWithMiddlewarePassesThroughToKernelBuilder(): void
    {
        $router = (new Router())->get('/health', ApplicationBuilderFixtureController::class . '@health');

        $app = ApplicationBuilder::create()
            ->withRouter($router)
            ->withMiddleware(new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request)->withHeader('X-App', 'yes');
                }
            })
            ->build();

        $response = $app->handle(Request::fromArray('GET', '/health'));

        self::assertSame('yes', $response->headers['x-app']);
    }
}

final class ApplicationBuilderFixtureController
{
    public function health(): Response
    {
        return Response::text('ok');
    }
}
