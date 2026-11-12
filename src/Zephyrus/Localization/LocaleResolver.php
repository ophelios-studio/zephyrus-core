<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Resolves the locale from an explicit request, then the Accept-Language header, then the default.
 *
 * Order: $requestedLocale (normalized, regional falls back to base language), Accept-Language
 * candidates by q-value, then $defaultLocale. An empty $supportedLocales accepts any well-formed locale;
 * a malformed one is ignored.
 */
final class LocaleResolver
{
    private AcceptLanguageResolver $headerResolver;

    public function __construct()
    {
        $this->headerResolver = new AcceptLanguageResolver();
    }

    /**
     * Resolves the best locale from the available signals.
     *
     * @param string[] $supportedLocales Allowlist. Empty means "accept any well-formed locale".
     */
    public function resolve(
        ?string $requestedLocale,
        ?string $acceptLanguageHeader,
        string $defaultLocale,
        array $supportedLocales = [],
    ): string {
        return $this->headerResolver->resolve(
            acceptLanguageHeader: $acceptLanguageHeader ?? '',
            supportedLocales:     $supportedLocales,
            defaultLocale:        $defaultLocale,
            requestedLocale:      $requestedLocale ?? '',
        );
    }
}
