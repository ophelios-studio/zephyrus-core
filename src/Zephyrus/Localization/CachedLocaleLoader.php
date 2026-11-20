<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Caches locale catalogs in APCu when it is loaded and debug is off, otherwise delegates to the inner loader.
 * An empty catalog is never cached, and a locale holding a colon (never a BCP 47 tag) bypasses the cache.
 *
 * Usage:
 *
 *   $loader = new CachedLocaleLoader(
 *       new JsonLocaleLoader('/app/locale'),
 *       debug: false,
 *       prefix: 'myapp',
 *       ttl: 3600,
 *   );
 */
final class CachedLocaleLoader implements LocaleLoaderInterface
{
    private readonly bool $cacheEnabled;

    /**
     * @param bool   $debug  Bypasses the cache entirely when true.
     * @param string $prefix APCu key prefix, reserved for this loader's catalogs (flush() deletes every
     *                       `<prefix>:<locale>` key) and should not contain a colon.
     * @param int    $ttl    Time-to-live in seconds, 0 for no expiry.
     */
    public function __construct(
        private readonly LocaleLoaderInterface $inner,
        private readonly bool $debug = false,
        private readonly string $prefix = 'zephyrus_locale',
        private readonly int $ttl = 0,
    ) {
        $this->cacheEnabled = !$this->debug && extension_loaded('apcu') && apcu_enabled();
    }

    /**
     * Returns the cached catalog for $locale, loading and storing it on a miss.
     *
     * @return array<string, mixed>
     */
    public function load(string $locale): array
    {
        if (!$this->cacheEnabled || str_contains($locale, ':')) {
            return $this->inner->load($locale);
        }

        $key = $this->cacheKey($locale);
        $success = false;

        /** @var mixed $cached */
        $cached = apcu_fetch($key, $success);

        if ($success && is_array($cached)) {
            return $cached;
        }

        $catalog = $this->inner->load($locale);
        if ($catalog !== []) {
            apcu_store($key, $catalog, $this->ttl);
        }

        return $catalog;
    }

    /**
     * Deletes the cached catalogs of this prefix. Call after deploying new translation files; no-op without APCu.
     */
    public function flush(): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        /** @var \APCUIterator $iterator */
        $iterator = new \APCUIterator('#^' . preg_quote($this->prefix . ':', '#') . '[^:]*\z#');
        apcu_delete($iterator);
    }

    private function cacheKey(string $locale): string
    {
        return $this->prefix . ':' . $locale;
    }
}
