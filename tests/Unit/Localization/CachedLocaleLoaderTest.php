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
            $this->markTestSkipped('APCu is available: this test requires APCu to be absent.');
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
        $loader = new CachedLocaleLoader($inner, debug: false, prefix: 'test_apcu_' . bin2hex(random_bytes(8)));

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
        $prefix = 'test_apcu_flush_' . bin2hex(random_bytes(8));
        $loader = new CachedLocaleLoader($inner, debug: false, prefix: $prefix);

        $loader->load('en'); // populates cache
        self::assertSame(1, $inner->callCount);

        $loader->flush(); // clears cache

        $loader->load('en'); // should call inner again
        self::assertSame(2, $inner->callCount);
    }

    public function testApcuCachesByDefaultWhenDebugIsNotGiven(): void
    {
        $this->requireApcu();

        $inner = $this->countingLoader(['en' => ['hello' => 'Hello']]);
        $loader = new CachedLocaleLoader($inner, prefix: $this->uniquePrefix());

        $loader->load('en');
        $loader->load('en');

        self::assertSame(1, $inner->callCount);

        $loader->flush();
    }

    public function testApcuEntriesDoNotExpireByDefault(): void
    {
        $this->requireApcu();

        $prefix = $this->uniquePrefix();
        $loader = new CachedLocaleLoader($this->countingLoader(['en' => ['hello' => 'Hello']]), prefix: $prefix);

        $loader->load('en');

        self::assertSame(0, $this->ttlOf($prefix . ':en'));

        $loader->flush();
    }

    public function testApcuEntriesCarryTheGivenTtl(): void
    {
        $this->requireApcu();

        $prefix = $this->uniquePrefix();
        $loader = new CachedLocaleLoader($this->countingLoader(['en' => ['hello' => 'Hello']]), prefix: $prefix, ttl: 3600);

        $loader->load('en');

        self::assertSame(3600, $this->ttlOf($prefix . ':en'));

        $loader->flush();
    }

    public function testApcuCachedCatalogComesBackWhole(): void
    {
        $this->requireApcu();

        $catalog = ['a' => 'A', 'b' => ['c' => 'C'], 'd' => 'D'];
        $loader = new CachedLocaleLoader($this->countingLoader(['en' => $catalog]), prefix: $this->uniquePrefix());

        self::assertSame($catalog, $loader->load('en'));
        self::assertSame($catalog, $loader->load('en'));

        $loader->flush();
    }

    public function testApcuKeepsEachLocaleUnderItsOwnKey(): void
    {
        $this->requireApcu();

        $prefix = $this->uniquePrefix();
        $loader = new CachedLocaleLoader(
            $this->countingLoader(['en' => ['hello' => 'Hello'], 'fr' => ['hello' => 'Bonjour']]),
            prefix: $prefix,
        );

        $loader->load('en');

        self::assertTrue(apcu_exists($prefix . ':en'));
        self::assertSame(['hello' => 'Bonjour'], $loader->load('fr'));
        self::assertSame(['hello' => 'Hello'], $loader->load('en'));

        $loader->flush();
    }

    public function testApcuIgnoresAStoredValueThatIsNotACatalog(): void
    {
        $this->requireApcu();

        $prefix = $this->uniquePrefix();
        apcu_store($prefix . ':en', 'not a catalog');
        $loader = new CachedLocaleLoader($this->countingLoader(['en' => ['hello' => 'Hello']]), prefix: $prefix);

        self::assertSame(['hello' => 'Hello'], $loader->load('en'));

        $loader->flush();
    }

    public function testApcuFlushLeavesTheKeysOfAnotherPrefixAlone(): void
    {
        $this->requireApcu();

        $suffix = bin2hex(random_bytes(8));
        $loader = new CachedLocaleLoader($this->countingLoader([]), prefix: 'test_a_' . $suffix);
        apcu_store('test_a_' . $suffix . ':en', ['x' => 'X']);
        apcu_store('test_b_' . $suffix . ':en', ['y' => 'Y']);

        $loader->flush();

        self::assertFalse(apcu_exists('test_a_' . $suffix . ':en'));
        self::assertTrue(apcu_exists('test_b_' . $suffix . ':en'));

        apcu_delete('test_b_' . $suffix . ':en');
    }

    public function testApcuFlushTreatsThePrefixAsLiteralText(): void
    {
        $this->requireApcu();

        $suffix = bin2hex(random_bytes(8));
        $loader = new CachedLocaleLoader($this->countingLoader([]), prefix: 'test.' . $suffix);
        apcu_store('test.' . $suffix . ':en', ['x' => 'X']);
        apcu_store('testX' . $suffix . ':en', ['y' => 'Y']);

        $loader->flush();

        self::assertFalse(apcu_exists('test.' . $suffix . ':en'));
        self::assertTrue(apcu_exists('testX' . $suffix . ':en'));

        apcu_delete('testX' . $suffix . ':en');
    }

    public function testApcuFlushInDebugModeKeepsTheCachedKeys(): void
    {
        $this->requireApcu();

        $prefix = $this->uniquePrefix();
        apcu_store($prefix . ':en', ['x' => 'X']);
        $loader = new CachedLocaleLoader($this->countingLoader([]), debug: true, prefix: $prefix);

        $loader->flush();

        self::assertTrue(apcu_exists($prefix . ':en'));

        apcu_delete($prefix . ':en');
    }

    private function requireApcu(): void
    {
        if (!extension_loaded('apcu') || !apcu_enabled()) {
            $this->markTestSkipped('APCu is not available.');
        }
    }

    private function uniquePrefix(): string
    {
        return 'test_apcu_' . bin2hex(random_bytes(8));
    }

    private function ttlOf(string $key): ?int
    {
        /** @var array{cache_list: list<array{info: string, ttl: int}>} $info */
        $info = apcu_cache_info(false);
        foreach ($info['cache_list'] as $entry) {
            if ($entry['info'] === $key) {
                return $entry['ttl'];
            }
        }

        return null;
    }
}
