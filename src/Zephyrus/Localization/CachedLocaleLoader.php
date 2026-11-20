<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Caches locale catalogs in APCu when it is loaded and debug is off, otherwise delegates to the inner loader.
 * Only non-empty catalogs of canonical locale tags are cached; growth stays bounded only if the inner loader
 * returns [] for a locale it lacks (a loader falling back to a default catalog adds one entry per locale asked).
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
     *                       `<prefix>:<locale>` key).
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
     * An empty catalog is not stored, so the next call asks the inner loader again.
     *
     * @return array<string, mixed>
     */
    public function load(string $locale): array
    {
        if (!$this->cacheEnabled || !$this->isCacheable($locale)) {
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

    private function isCacheable(string $locale): bool
    {
        return LocaleTag::isWellFormed($locale) && LocaleTag::canonical($locale) === $locale;
    }

    private function cacheKey(string $locale): string
    {
        return $this->prefix . ':' . $locale;
    }
}
