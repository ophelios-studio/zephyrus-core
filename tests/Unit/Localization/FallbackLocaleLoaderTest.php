<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Localization\FallbackLocaleLoader;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Localization\Translator;

final class FallbackLocaleLoaderTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Factory helpers
    // -----------------------------------------------------------------------

    private function catalogLoader(array $catalogs): LocaleLoaderInterface
    {
        return new class ($catalogs) implements LocaleLoaderInterface {
            public function __construct(private readonly array $catalogs) {}

            public function load(string $locale): array
            {
                return $this->catalogs[$locale] ?? [];
            }
        };
    }

    // -----------------------------------------------------------------------
    // Empty-loader edge cases
    // -----------------------------------------------------------------------

    public function testNoLoadersReturnsEmptyCatalog(): void
    {
        $loader = new FallbackLocaleLoader([]);

        self::assertSame([], $loader->load('en'));
    }

    public function testSingleLoaderReturnsCatalogDirectly(): void
    {
        $loader = new FallbackLocaleLoader([
            $this->catalogLoader(['en' => ['hello' => 'Hello']]),
        ]);

        self::assertSame(['hello' => 'Hello'], $loader->load('en'));
    }

    // -----------------------------------------------------------------------
    // Merge semantics (last-wins)
    // -----------------------------------------------------------------------

    public function testTwoLoadersAreMerged(): void
    {
        $loader = new FallbackLocaleLoader([
            $this->catalogLoader(['en' => ['a' => 'base-a', 'b' => 'base-b']]),
            $this->catalogLoader(['en' => ['b' => 'override-b', 'c' => 'override-c']]),
        ]);

        $catalog = $loader->load('en');

        // 'a' comes from base; 'b' overridden by second loader; 'c' added by second
        self::assertSame('base-a', $catalog['a']);
        self::assertSame('override-b', $catalog['b']);
        self::assertSame('override-c', $catalog['c']);
    }

    public function testThreeLoadersLastWinsOnConflict(): void
    {
        $loader = new FallbackLocaleLoader([
            $this->catalogLoader(['en' => ['key' => 'first']]),
            $this->catalogLoader(['en' => ['key' => 'second']]),
            $this->catalogLoader(['en' => ['key' => 'third']]),
        ]);

        self::assertSame('third', $loader->load('en')['key']);
    }

    public function testEmptySecondaryLoaderDoesNotErasePrimary(): void
    {
        $loader = new FallbackLocaleLoader([
            $this->catalogLoader(['en' => ['hello' => 'Hello']]),
            $this->catalogLoader([]),           // returns [] for any locale
        ]);

        self::assertSame('Hello', $loader->load('en')['hello']);
    }

    // -----------------------------------------------------------------------
    // Per-locale isolation
    // -----------------------------------------------------------------------

    public function testEachLocaleIsResolvedIndependently(): void
    {
        $loader = new FallbackLocaleLoader([
            $this->catalogLoader([
                'en' => ['greeting' => 'Hello'],
                'fr' => ['greeting' => 'Bonjour'],
            ]),
            $this->catalogLoader([
                'en' => ['farewell' => 'Goodbye'],
                'fr' => ['farewell' => 'Au revoir'],
            ]),
        ]);

        self::assertSame('Hello',    $loader->load('en')['greeting']);
        self::assertSame('Goodbye',  $loader->load('en')['farewell']);
        self::assertSame('Bonjour',  $loader->load('fr')['greeting']);
        self::assertSame('Au revoir', $loader->load('fr')['farewell']);
    }

    public function testMissingLocaleInOneLoaderReturnsEmptyForThatLoader(): void
    {
        // 'es' is only in the second loader
        $loader = new FallbackLocaleLoader([
            $this->catalogLoader(['en' => ['greeting' => 'Hello']]),
            $this->catalogLoader(['es' => ['greeting' => 'Hola']]),
        ]);

        self::assertSame('Hola', $loader->load('es')['greeting']);
        self::assertSame(1, count($loader->load('es')));
    }

    // -----------------------------------------------------------------------
    // Translator integration
    // -----------------------------------------------------------------------

    public function testFallbackLoaderWorksWithTranslator(): void
    {
        $loader = new FallbackLocaleLoader([
            $this->catalogLoader([
                'en' => ['app.title' => 'My App', 'app.greeting' => 'Hello'],
                'fr' => ['app.greeting' => 'Bonjour'],
            ]),
            $this->catalogLoader([
                'en' => ['app.title' => 'My App (override)'],  // overrides base
            ]),
        ]);

        $translator = new Translator($loader, defaultLocale: 'en');

        self::assertSame('My App (override)', $translator->trans('app.title'));
        self::assertSame('Hello', $translator->trans('app.greeting'));
        self::assertSame('Bonjour', $translator->trans('app.greeting', locale: 'fr'));
    }

    public function testVendorPlusAppOverridePattern(): void
    {
        // Simulate: vendor provides base, app overrides specific keys
        $vendorLoader = $this->catalogLoader([
            'en' => ['vendor.base' => 'Base', 'vendor.label' => 'Vendor Label'],
        ]);
        $appLoader = $this->catalogLoader([
            'en' => ['vendor.label' => 'App Override', 'app.extra' => 'Extra'],
        ]);

        $loader = new FallbackLocaleLoader([$vendorLoader, $appLoader]);
        $catalog = $loader->load('en');

        self::assertSame('Base', $catalog['vendor.base']);          // from vendor
        self::assertSame('App Override', $catalog['vendor.label']); // app wins
        self::assertSame('Extra', $catalog['app.extra']);           // app-only
    }
}
