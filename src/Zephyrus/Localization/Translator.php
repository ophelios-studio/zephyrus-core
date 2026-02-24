<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $catalogCache = [];

    public function __construct(
        private readonly LocaleLoaderInterface $loader,
        private readonly string $defaultLocale = 'en'
    ) {
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        $requestedLocale = $locale ?? $this->defaultLocale;

        $catalog = $this->catalog($requestedLocale);
        if (array_key_exists($key, $catalog)) {
            return $this->interpolate($catalog[$key], $parameters);
        }

        if ($requestedLocale !== $this->defaultLocale) {
            $fallbackCatalog = $this->catalog($this->defaultLocale);
            if (array_key_exists($key, $fallbackCatalog)) {
                return $this->interpolate($fallbackCatalog[$key], $parameters);
            }
        }

        return $this->interpolate($key, $parameters);
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        if (!array_key_exists($locale, $this->catalogCache)) {
            $this->catalogCache[$locale] = $this->loader->load($locale);
        }

        return $this->catalogCache[$locale];
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function interpolate(string $value, array $parameters): string
    {
        if ($parameters === []) {
            return $value;
        }

        $replacements = [];
        foreach ($parameters as $name => $parameterValue) {
            $replacements['{' . $name . '}'] = (string) $parameterValue;
        }

        return strtr($value, $replacements);
    }
}
