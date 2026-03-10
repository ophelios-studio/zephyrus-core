<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

interface LocaleLoaderInterface
{
    /**
     * Load a locale catalog as a nested associative array.
     *
     * @return array<string, mixed>
     */
    public function load(string $locale): array;
}
