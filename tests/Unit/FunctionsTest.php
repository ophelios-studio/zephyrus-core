<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Localization\Translator;
use Zephyrus\Rendering\Asset;
use Zephyrus\Rendering\ViteException;
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

    public function testEnvReturnsValueFromServerSuperglobal(): void
    {
        $_SERVER['ZEPHYRUS_SERVER_VAR'] = 'world';

        try {
            self::assertSame('world', env('ZEPHYRUS_SERVER_VAR'));
        } finally {
            unset($_SERVER['ZEPHYRUS_SERVER_VAR']);
        }
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

    public function testFormatReturnsStringCastWhenNoFormatterSet(): void
    {
        self::assertSame('42', format('decimal', 42));
    }

    public function testFormatReturnsEmptyStringWhenNoFormatterAndNoArgs(): void
    {
        self::assertSame('', format('decimal'));
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

    // ─── asset() ──────────────────────────────────────────────────────

    public function testAssetReturnsPathWhenNoAssetManagerSet(): void
    {
        self::assertSame('/css/app.css', asset('/css/app.css'));
    }

    public function testAssetReturnsCacheBustedUrl(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus_asset_test_' . uniqid();
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

    // ─── vite() ───────────────────────────────────────────────────────

    public function testViteDevelopmentReturnsDevServerScripts(): void
    {
        App::setConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'development'],
        ]));

        self::assertSame(
            implode("\n", [
                '<script type="module" src="http://localhost:5173/@vite/client"></script>',
                '<script type="module" src="http://localhost:5173/resources/js/app.js"></script>',
            ]),
            vite('resources/js/app.js', ['dev_server' => 'http://localhost:5173']),
        );
    }

    public function testViteProductionReadsManifest(): void
    {
        App::setConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'production'],
        ]));

        $tempDir = sys_get_temp_dir() . '/zephyrus_vite_test_' . uniqid();
        mkdir($tempDir . '/build', 0755, true);

        file_put_contents($tempDir . '/build/manifest.json', json_encode([
            '_vendor.js' => [
                'file' => 'assets/vendor-123.js',
                'css' => ['assets/vendor-123.css'],
            ],
            'resources/js/app.js' => [
                'file' => 'assets/app-abc123.js',
                'src' => 'resources/js/app.js',
                'imports' => ['_vendor.js'],
                'css' => ['assets/app-def456.css'],
            ],
        ], \JSON_THROW_ON_ERROR));

        try {
            self::assertSame(
                implode("\n", [
                    '<link rel="stylesheet" href="/build/assets/vendor-123.css">',
                    '<link rel="stylesheet" href="/build/assets/app-def456.css">',
                    '<script type="module" src="/build/assets/app-abc123.js"></script>',
                ]),
                vite('resources/js/app.js', ['public_directory' => $tempDir]),
            );
        } finally {
            $this->cleanDir($tempDir);
        }
    }

    public function testViteProductionThrowsWhenEntryIsMissingFromManifest(): void
    {
        App::setConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'production'],
        ]));

        $tempDir = sys_get_temp_dir() . '/zephyrus_vite_missing_test_' . uniqid();
        mkdir($tempDir . '/build', 0755, true);
        file_put_contents($tempDir . '/build/manifest.json', json_encode([], \JSON_THROW_ON_ERROR));

        try {
            $this->expectException(ViteException::class);
            vite('resources/js/app.js', ['public_directory' => $tempDir]);
        } finally {
            $this->cleanDir($tempDir);
        }
    }

    // ─── inertia_app() / inertia_head() ───────────────────────────────

    public function testInertiaAppRendersRootElementWithoutPage(): void
    {
        self::assertSame('<div id="app"></div>', inertia_app());
        self::assertSame('<div id="root"></div>', inertia_app('root'));
    }

    public function testInertiaAppRendersPagePayload(): void
    {
        $html = inertia_app('root', [
            'component' => 'Dashboard',
            'props' => ['title' => 'Dashboard'],
            'url' => '/dashboard',
            'version' => 'build-1',
        ]);

        self::assertStringStartsWith('<div id="root" data-page="', $html);
        self::assertStringContainsString('&quot;component&quot;:&quot;Dashboard&quot;', $html);
        self::assertStringContainsString('&quot;title&quot;:&quot;Dashboard&quot;', $html);
    }

    public function testInertiaHeadRendersTitleWhenAvailable(): void
    {
        self::assertSame('', inertia_head());
        self::assertSame('', inertia_head(['props' => []]));
        self::assertSame(
            '<title>Dashboard &amp; Reports</title>',
            inertia_head(['props' => ['title' => 'Dashboard & Reports']]),
        );
    }

    // ─── embed() ──────────────────────────────────────────────────────

    public function testEmbedReturnsEmptyStringWhenNoAssetManagerSet(): void
    {
        self::assertSame('', embed('/icon.svg'));
    }

    public function testEmbedReturnsFileContents(): void
    {
        $tempDir = sys_get_temp_dir() . '/zephyrus_embed_test_' . uniqid();
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

    private function cleanDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
