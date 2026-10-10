<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Formatting\FormatterException;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Localization\Translator;
use Zephyrus\Rendering\Asset;
use Zephyrus\Routing\Exception\RouteUrlGenerationException;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteUrlGenerator;
use Zephyrus\Session\SessionManager;

final class FunctionsTest extends TestCase
{
    protected function tearDown(): void
    {
        App::reset();
    }

    // ─── env() ────────────────────────────────────────────────────────

    public function testEnvReturnsValueFromEnvSuperglobal(): void
    {
        $_ENV['ZEPHYRUS_TEST_VAR'] = 'hello';

        try {
            self::assertSame('hello', env('ZEPHYRUS_TEST_VAR'));
        } finally {
            unset($_ENV['ZEPHYRUS_TEST_VAR']);
        }
    }

    public function testEnvIgnoresAKeyOnlyInServerAndReturnsTheDefault(): void
    {
        $_SERVER['ZEPHYRUS_SERVER_VAR'] = 'world';

        try {
            self::assertNull(env('ZEPHYRUS_SERVER_VAR'));
            self::assertSame('fallback', env('ZEPHYRUS_SERVER_VAR', 'fallback'));
        } finally {
            unset($_SERVER['ZEPHYRUS_SERVER_VAR']);
        }
    }

    public function testEnvRefusesAPasswordNameBeforeAnySourceIsRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        env('PHP_AUTH_PW');
    }

    public function testEnvReturnsDefaultForMissingVariable(): void
    {
        unset($_ENV['ZEPHYRUS_MISSING'], $_SERVER['ZEPHYRUS_MISSING']);

        self::assertNull(env('ZEPHYRUS_MISSING'));
        self::assertSame('fallback', env('ZEPHYRUS_MISSING', 'fallback'));
    }

    public function testEnvCastsTrueFalseNullEmpty(): void
    {
        $_ENV['ZEPHYRUS_BOOL_TRUE'] = 'true';
        $_ENV['ZEPHYRUS_BOOL_FALSE'] = 'false';
        $_ENV['ZEPHYRUS_NULL'] = 'null';
        $_ENV['ZEPHYRUS_EMPTY'] = 'empty';

        try {
            self::assertTrue(env('ZEPHYRUS_BOOL_TRUE'));
            self::assertFalse(env('ZEPHYRUS_BOOL_FALSE'));
            self::assertNull(env('ZEPHYRUS_NULL'));
            self::assertSame('', env('ZEPHYRUS_EMPTY'));
        } finally {
            unset(
                $_ENV['ZEPHYRUS_BOOL_TRUE'],
                $_ENV['ZEPHYRUS_BOOL_FALSE'],
                $_ENV['ZEPHYRUS_NULL'],
                $_ENV['ZEPHYRUS_EMPTY'],
            );
        }
    }

    public function testEnvReturnsRawValueForNonSpecialStrings(): void
    {
        $_ENV['ZEPHYRUS_NORMAL'] = 'some-value';

        try {
            self::assertSame('some-value', env('ZEPHYRUS_NORMAL'));
        } finally {
            unset($_ENV['ZEPHYRUS_NORMAL']);
        }
    }

    public function testEnvPrefersEnvOverServer(): void
    {
        $_ENV['ZEPHYRUS_PRIORITY'] = 'from-env';
        $_SERVER['ZEPHYRUS_PRIORITY'] = 'from-server';

        try {
            self::assertSame('from-env', env('ZEPHYRUS_PRIORITY'));
        } finally {
            unset($_ENV['ZEPHYRUS_PRIORITY'], $_SERVER['ZEPHYRUS_PRIORITY']);
        }
    }

    // ─── config() ─────────────────────────────────────────────────────

    public function testConfigReturnsDefaultWhenNoConfigurationSet(): void
    {
        self::assertNull(config('application'));
        self::assertSame('fallback', config('application', 'debug', 'fallback'));
    }

    public function testConfigReturnsBuiltInSection(): void
    {
        $config = Configuration::fromArray([
            'application' => ['environment' => 'dev', 'debug' => true],
        ]);
        App::setConfiguration($config);

        $section = config('application');
        self::assertSame($config->application, $section);
    }

    public function testConfigReturnsBuiltInSectionProperty(): void
    {
        $config = Configuration::fromArray([
            'application' => ['environment' => 'staging', 'debug' => false],
        ]);
        App::setConfiguration($config);

        self::assertSame($config->application->environment, config('application', 'environment'));
        self::assertFalse(config('application', 'debug'));
    }

    public function testConfigReturnsDefaultForMissingSection(): void
    {
        $config = Configuration::fromArray([]);
        App::setConfiguration($config);

        self::assertNull(config('nonexistent'));
        self::assertSame('default', config('nonexistent', 'key', 'default'));
    }

    public function testConfigReturnsDefaultForMissingPropertyInExistingSection(): void
    {
        $config = Configuration::fromArray([]);
        App::setConfiguration($config);

        self::assertSame('fallback', config('application', 'nonexistent', 'fallback'));
    }

    public function testConfigReturnsNullableDatabaseSection(): void
    {
        $config = Configuration::fromArray([]);
        App::setConfiguration($config);

        // database is nullable and defaults to null when not configured.
        self::assertNull(config('database'));
    }

    public function testConfigReturnsTheDefaultForAnUnconfiguredDatabaseProperty(): void
    {
        App::setConfiguration(Configuration::fromArray([]));

        self::assertSame('fallback', config('database', 'host', 'fallback'));
    }

    public function testConfigRefusesASpellingOfABuiltInSectionName(): void
    {
        App::setConfiguration(Configuration::fromArray([]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$configuration->database');

        config('database_');
    }

    public function testConfigReturnsSessionSection(): void
    {
        $config = Configuration::fromArray([
            'session' => ['name' => 'MYAPP'],
        ]);
        App::setConfiguration($config);

        self::assertSame('MYAPP', config('session', 'name'));
    }

    public function testConfigReturnsSecuritySection(): void
    {
        $config = Configuration::fromArray([]);
        App::setConfiguration($config);

        $section = config('security');
        self::assertSame($config->security, $section);
    }

    public function testConfigReturnsLocalizationSection(): void
    {
        $config = Configuration::fromArray([
            'localization' => ['locale' => 'fr'],
        ]);
        App::setConfiguration($config);

        self::assertSame('fr', config('localization', 'locale'));
    }

    // ─── session() ────────────────────────────────────────────────────

    public function testSessionReturnsDefaultWhenNoSessionSet(): void
    {
        self::assertNull(session('key'));
        self::assertSame('fallback', session('key', 'fallback'));
    }

    public function testSessionReadsValue(): void
    {
        $manager = new SessionManager(['user' => 'alice']);
        App::setSession($manager);

        self::assertSame('alice', session('user'));
        self::assertSame('default', session('missing', 'default'));
    }

    public function testSessionWritesMultipleValues(): void
    {
        $manager = new SessionManager([]);
        App::setSession($manager);

        $result = session(['key1' => 'val1', 'key2' => 'val2']);
        self::assertNull($result);
        self::assertSame('val1', $manager->get('key1'));
        self::assertSame('val2', $manager->get('key2'));
    }

    public function testSessionWriteReturnsNullWhenNoSessionSet(): void
    {
        self::assertNull(session(['key' => 'value']));
    }

    // ─── localize() / i18n() ──────────────────────────────────────────

    public function testLocalizeReturnsKeyWhenNoTranslatorSet(): void
    {
        self::assertSame('messages.welcome', localize('messages.welcome'));
    }

    public function testLocalizeTranslatesKey(): void
    {
        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return match ($locale) {
                    'en' => ['greeting' => 'Hello {name}'],
                    default => [],
                };
            }
        };
        $translator = new Translator($loader, 'en');
        App::setTranslator($translator);

        self::assertSame('Hello Alice', localize('greeting', ['name' => 'Alice']));
    }

    public function testI18nIsAliasForLocalize(): void
    {
        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return match ($locale) {
                    'en' => ['bye' => 'Goodbye'],
                    default => [],
                };
            }
        };
        $translator = new Translator($loader, 'en');
        App::setTranslator($translator);

        self::assertSame(localize('bye'), i18n('bye'));
    }

    public function testLocalizeWithLocaleOverride(): void
    {
        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return match ($locale) {
                    'en' => ['hello' => 'Hello'],
                    'fr' => ['hello' => 'Bonjour'],
                    default => [],
                };
            }
        };
        $translator = new Translator($loader, 'en');
        App::setTranslator($translator);

        self::assertSame('Hello', localize('hello'));
        self::assertSame('Bonjour', localize('hello', [], 'fr'));
    }

    // ─── format() ─────────────────────────────────────────────────────

    public function testFormatThrowsWhenNoFormatterSetInsteadOfEchoingTheValue(): void
    {
        $this->expectException(FormatterException::class);
        $this->expectExceptionMessage('format(\'mask\') needs a Formatter');

        format('mask', '123-45-6789');
    }

    public function testFormatWithoutFormatterNeverLeaksTheValueInTheMessage(): void
    {
        try {
            format('mask', '123-45-6789');
            self::fail('Expected a FormatterException.');
        } catch (FormatterException $exception) {
            self::assertStringNotContainsString('123-45-6789', $exception->getMessage());
        }
    }

    public function testFormatWithoutFormatterAndNoArgsStillThrows(): void
    {
        $this->expectException(FormatterException::class);

        format('decimal');
    }

    public function testFormatDelegatesDecimal(): void
    {
        App::setFormatter(new Formatter('en_US'));
        $result = format('decimal', 1234.567, 2);
        self::assertSame('1,234.57', $result);
    }

    public function testFormatDelegatesMoney(): void
    {
        App::setFormatter(new Formatter('en_US'));
        $result = format('money', 19.99, 'USD');
        self::assertStringContainsString('19.99', $result);
    }

    public function testFormatDelegatesPercent(): void
    {
        App::setFormatter(new Formatter('en_US'));
        $result = format('percent', 0.85);
        self::assertSame('85%', $result);
    }

    public function testFormatUnknownTypeThrowsFormatterExceptionListingBuiltIns(): void
    {
        App::setFormatter(new Formatter('en_US'));

        $this->expectException(FormatterException::class);
        $this->expectExceptionMessage('money, decimal, percent');
        format('nonexistent');
    }

    // ─── route() ──────────────────────────────────────────────────────

    private function useRoutes(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/users/{id}', 'UserController@show', ['id' => '[0-9]+'])->withName('users.show'));
        $routes->add(Route::define('GET', '/home', 'HomeController@index')->withName('home'));
        App::setUrlGenerator(new RouteUrlGenerator($routes));
    }

    public function testRouteGeneratesPathFromNamedRouteParametersAndQuery(): void
    {
        $this->useRoutes();

        self::assertSame('/users/42', route('users.show', ['id' => 42]));
        self::assertSame('/users/42?tab=posts', route('users.show', ['id' => 42], ['tab' => 'posts']));
        self::assertSame('/home', route('home'));
    }

    public function testRouteEncodesParameterValues(): void
    {
        $routes = new RouteCollection();
        $routes->add(Route::define('GET', '/files/{name}', 'FileController@show')->withName('files.show'));
        App::setUrlGenerator(new RouteUrlGenerator($routes));

        self::assertSame('/files/a%20b%2Fc', route('files.show', ['name' => 'a b/c']));
    }

    public function testRouteRefusesAnUnknownName(): void
    {
        $this->useRoutes();

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Unknown route name: users.delete');

        route('users.delete');
    }

    public function testRouteRefusesAMissingParameter(): void
    {
        $this->useRoutes();

        $this->expectException(RouteUrlGenerationException::class);
        $this->expectExceptionMessage('Missing route parameter "id" for route "users.show"');

        route('users.show');
    }

    public function testRouteAppendsAFragment(): void
    {
        $this->useRoutes();

        self::assertSame('/home#top', route('home', [], [], 'top'));
        self::assertSame('/home#top', route('home', [], [], '#top'));
        self::assertSame('/home', route('home', [], [], ''));
    }

    public function testRouteThrowsWhenNoGeneratorIsSet(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ApplicationBuilder::withRouter(), or call App::setUrlGenerator()');

        route('home');
    }

    // ─── asset() ──────────────────────────────────────────────────────

    public function testAssetReturnsPathWhenNoAssetManagerSet(): void
    {
        self::assertSame('/css/app.css', asset('/css/app.css'));
    }

    public function testAssetReturnsCacheBustedUrl(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus_asset_test_' . bin2hex(random_bytes(8));
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/style.css', 'body { color: red; }');

        try {
            App::setAsset(new Asset($tempDir));
            $url = asset('/style.css');
            self::assertStringStartsWith('/style.css?v=', $url);
        } finally {
            unlink($tempDir . '/style.css');
            rmdir($tempDir);
        }
    }

    // ─── embed() ──────────────────────────────────────────────────────

    public function testEmbedReturnsEmptyStringWhenNoAssetManagerSet(): void
    {
        self::assertSame('', embed('/icon.svg'));
    }

    public function testEmbedReturnsFileContents(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus_embed_test_' . bin2hex(random_bytes(8));
        mkdir($tempDir, 0755, true);
        file_put_contents($tempDir . '/icon.svg', '<svg></svg>');

        try {
            App::setAsset(new Asset($tempDir));
            self::assertSame('<svg></svg>', embed('/icon.svg'));
        } finally {
            unlink($tempDir . '/icon.svg');
            rmdir($tempDir);
        }
    }

    // ─── nonce() ──────────────────────────────────────────────────────

    public function testNonceReturnsBase64String(): void
    {
        $nonce = nonce();
        self::assertNotEmpty($nonce);
        self::assertNotFalse(base64_decode($nonce, true));
    }

    public function testNonceIsConsistentWithinRequest(): void
    {
        $first = nonce();
        $second = nonce();
        self::assertSame($first, $second);
    }
}
