<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Decorator that caches locale catalogs in APCu when the extension is
 * available and the application is not running in debug mode.
 *
 * When APCu is not loaded or debug mode is enabled, every call is transparently
 * forwarded to the inner loader with no caching overhead.
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
     * @param LocaleLoaderInterface $inner   The actual loader to delegate to.
     * @param bool                  $debug   When true, caching is bypassed entirely.
     * @param string                $prefix  APCu key prefix (avoids collisions between apps).
     * @param int                   $ttl     Cache time-to-live in seconds (0 = unlimited).
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
     * @return array<string, mixed>
     */
    public function load(string $locale): array
    {
        if (!$this->cacheEnabled) {
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
        apcu_store($key, $catalog, $this->ttl);

        return $catalog;
    }

    /**
     * Flush all cached catalogs matching this loader's prefix.
     *
     * Useful after deploying new translation files. When APCu is not
     * available this is a no-op.
     */
    public function flush(): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        /** @var \APCUIterator $iterator */
        $iterator = new \APCUIterator('#^' . preg_quote($this->prefix, '#') . '#');
        apcu_delete($iterator);
    }

    private function cacheKey(string $locale): string
    {
        return $this->prefix . ':' . $locale;
    }
}
