<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Localization\CachedLocaleLoader;
use Zephyrus\Localization\LocaleLoaderInterface;

final class CachedLocaleLoaderTest extends TestCase
{
    // -----------------------------------------------------------------
    // Helper: a counting loader to verify delegation behavior
    // -----------------------------------------------------------------

    private function countingLoader(array $catalogs): object
    {
        return new class ($catalogs) implements LocaleLoaderInterface {
            public int $callCount = 0;

            public function __construct(private readonly array $catalogs) {}

            public function load(string $locale): array
            {
                $this->callCount++;
                return $this->catalogs[$locale] ?? [];
            }
        };
    }

    // -----------------------------------------------------------------
    // Pass-through when debug is true
    // -----------------------------------------------------------------

    public function testDebugModeBypassesCacheAndDelegatesToInner(): void
    {
        $inner = $this->countingLoader(['en' => ['hello' => 'Hello']]);
        $loader = new CachedLocaleLoader($inner, debug: true);

        $first = $loader->load('en');
        $second = $loader->load('en');

        self::assertSame(['hello' => 'Hello'], $first);
        self::assertSame(['hello' => 'Hello'], $second);
        // Without cache, inner is called every time
        self::assertSame(2, $inner->callCount);
    }

    // -----------------------------------------------------------------
    // Pass-through when APCu is not loaded
    // -----------------------------------------------------------------

    public function testNoApcuDelegatesToInnerEveryTime(): void
    {
        if (extension_loaded('apcu') && apcu_enabled()) {
            $this->markTestSkipped('APCu is available — this test requires APCu to be absent.');
        }

        $inner = $this->countingLoader(['fr' => ['bonjour' => 'Bonjour']]);
        $loader = new CachedLocaleLoader($inner, debug: false);

        $first = $loader->load('fr');
        $second = $loader->load('fr');

        self::assertSame(['bonjour' => 'Bonjour'], $first);
        self::assertSame(['bonjour' => 'Bonjour'], $second);
        self::assertSame(2, $inner->callCount);
    }

    // -----------------------------------------------------------------
    // Nested catalogs pass through correctly
    // -----------------------------------------------------------------

    public function testNestedCatalogPassesThrough(): void
    {
        $inner = $this->countingLoader([
            'en' => ['messages' => ['welcome' => 'Welcome {name}', 'bye' => 'Goodbye']],
        ]);
        $loader = new CachedLocaleLoader($inner, debug: true);

        $catalog = $loader->load('en');

        self::assertSame('Welcome {name}', $catalog['messages']['welcome']);
        self::assertSame('Goodbye', $catalog['messages']['bye']);
    }

    // -----------------------------------------------------------------
    // Empty catalog
    // -----------------------------------------------------------------

    public function testMissingLocaleReturnsEmptyArray(): void
    {
        $inner = $this->countingLoader([]);
        $loader = new CachedLocaleLoader($inner, debug: true);

        self::assertSame([], $loader->load('es'));
        self::assertSame(1, $inner->callCount);
    }

    // -----------------------------------------------------------------
    // flush() is a no-op when cache is disabled
    // -----------------------------------------------------------------

    public function testFlushIsNoOpWhenCacheDisabled(): void
    {
        $inner = $this->countingLoader(['en' => ['hello' => 'Hello']]);
        $loader = new CachedLocaleLoader($inner, debug: true);

        // Should not throw or error
        $loader->flush();

        // Inner still works fine
        self::assertSame(['hello' => 'Hello'], $loader->load('en'));
    }

    // -----------------------------------------------------------------
    // Custom prefix and TTL accepted without error
    // -----------------------------------------------------------------

    public function testCustomPrefixAndTtlAccepted(): void
    {
        $inner = $this->countingLoader(['en' => ['key' => 'value']]);
        $loader = new CachedLocaleLoader($inner, debug: true, prefix: 'custom_app', ttl: 3600);

        self::assertSame(['key' => 'value'], $loader->load('en'));
    }

    // -----------------------------------------------------------------
    // APCu integration tests (only run when APCu is available)
    // -----------------------------------------------------------------

    public function testApcuCacheHitSkipsInnerLoader(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            $this->markTestSkipped('APCu is not available.');
        }

        $inner = $this->countingLoader(['en' => ['hello' => 'Hello']]);
        $loader = new CachedLocaleLoader($inner, debug: false, prefix: 'test_apcu_' . uniqid('', true));

        $first = $loader->load('en');
        $second = $loader->load('en');

        self::assertSame(['hello' => 'Hello'], $first);
        self::assertSame(['hello' => 'Hello'], $second);
        // With cache, inner should only be called once
        self::assertSame(1, $inner->callCount);

        $loader->flush();
    }

    public function testApcuFlushInvalidatesCache(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            $this->markTestSkipped('APCu is not available.');
        }

        $inner = $this->countingLoader(['en' => ['hello' => 'Hello']]);
        $prefix = 'test_apcu_flush_' . uniqid('', true);
        $loader = new CachedLocaleLoader($inner, debug: false, prefix: $prefix);

        $loader->load('en'); // populates cache
        self::assertSame(1, $inner->callCount);

        $loader->flush(); // clears cache

        $loader->load('en'); // should call inner again
        self::assertSame(2, $inner->callCount);
    }
}
