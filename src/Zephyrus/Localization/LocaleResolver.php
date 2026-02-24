<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * High-level locale resolver: accepts nullable parameters and treats an
 * explicit requested locale (URL segment, cookie, form field, …) as the
 * highest-priority signal, falling back to Accept-Language header candidates
 * (with q-value ordering and regional fallback), and finally to the
 * application default.
 *
 * Resolution order:
 *   1. $requestedLocale (normalized; regional fallback to base language).
 *   2. Candidates parsed from $acceptLanguageHeader, ordered by q-value.
 *   3. $defaultLocale as the final fallback.
 *
 * When $supportedLocales is empty every normalized locale is considered valid.
 */
final class LocaleResolver
{
    private AcceptLanguageResolver $headerResolver;

    public function __construct()
    {
        $this->headerResolver = new AcceptLanguageResolver();
    }

    /**
     * Resolve the best locale from the available signals.
     *
     * @param string[] $supportedLocales Allowlist. Empty means "accept any".
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
