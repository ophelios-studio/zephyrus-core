<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Merges the catalogs of several loaders, later loaders winning on conflicting keys.
 *
 * Nested catalogs are merged key by key. A loader returning an empty catalog never displaces earlier keys.
 *
 * Usage (vendor base, application override):
 *
 *   $loader = new FallbackLocaleLoader([
 *       new JsonLocaleLoader('/vendor/package/locales'),
 *       new JsonLocaleLoader('/app/resources/locales'),
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
