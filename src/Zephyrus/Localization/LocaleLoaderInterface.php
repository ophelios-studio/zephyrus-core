<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

interface LocaleLoaderInterface
{
    /**
     * @return array<string, string>
     */
    public function load(string $locale): array;
}
