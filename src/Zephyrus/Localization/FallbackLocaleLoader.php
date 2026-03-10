<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Chains multiple LocaleLoaderInterface implementations and merges their
 * catalogs using a last-wins override policy.
 *
 * Resolution semantics:
 *   - Each loader is called in the order supplied.
 *   - Keys from later loaders override keys from earlier loaders.
 *   - Loaders that return empty catalogs (e.g. missing locale file) are silently
 *     skipped; they never displace keys contributed by earlier loaders.
 *
 * Typical usage — vendor base + application override:
 *
 *   $loader = new FallbackLocaleLoader([
 *       new JsonLocaleLoader('/vendor/package/locales'),   // base layer
 *       new JsonLocaleLoader('/app/resources/locales'),    // app overrides
 *   ]);
 *
 * Typical usage — per-module translation files merged at startup:
 *
 *   $loader = new FallbackLocaleLoader([
 *       new JsonLocaleLoader('/modules/blog/locales'),
 *       new JsonLocaleLoader('/modules/shop/locales'),
 *       new JsonLocaleLoader('/app/locales'),              // wins on conflict
 *   ]);
 */
final class FallbackLocaleLoader implements LocaleLoaderInterface
{
    /** @param LocaleLoaderInterface[] $loaders */
    public function __construct(private readonly array $loaders)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(string $locale): array
    {
        $catalog = [];

        foreach ($this->loaders as $loader) {
            $catalog = array_replace_recursive($catalog, $loader->load($locale));
        }

        return $catalog;
    }
}
