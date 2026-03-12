<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Localization\LocaleResolver;
use Zephyrus\Localization\Translator;

final readonly class Application
{
    /**
     * @param string[] $supportedLocales Locale allowlist for Accept-Language resolution. Empty = accept any.
     */
    public function __construct(
        public HttpKernel $kernel,
        public Translator $translator,
        private string $defaultLocale = 'en',
        private array $supportedLocales = [],
    ) {
    }

    public function handle(Request $request): Response
    {
        return $this->kernel->handle($request);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function trans(string $key, array $parameters = [], ?string $locale = null): string
    {
        return $this->translator->trans($key, $parameters, $locale);
    }

    /**
     * Translate $key using the locale resolved from the request's Accept-Language header
     * and/or an explicit $requestedLocale override (URL segment, cookie, form field, …).
     *
     * Resolution order (highest to lowest priority):
     *   1. $requestedLocale when provided.
     *   2. Accept-Language header from $request (q-value ordered, regional fallback).
     *   3. Application default locale.
     *
     * When both $request and $requestedLocale are null the translator's own default
     * locale is used, equivalent to calling trans() without an explicit locale.
     *
     * $supportedLocales, when non-empty, restricts resolution to that set and
     * overrides the application-level supported-locale list for this call only.
     *
     * @param array<string, scalar|null> $parameters
     * @param string[]                   $supportedLocales Per-call allowlist override.
     */
    public function transFromRequest(
        string $key,
        array $parameters = [],
        ?Request $request = null,
        ?string $requestedLocale = null,
        array $supportedLocales = [],
    ): string {
        $locale = $this->resolveLocaleFromRequest($request, $requestedLocale, $supportedLocales);

        return $this->translator->trans($key, $parameters, $locale);
    }

    /**
     * Resolve a locale token using the same policy as transFromRequest().
     *
     * @param string[] $supportedLocales Per-call allowlist override.
     */
    public function resolveLocaleFromRequest(
        ?Request $request = null,
        ?string $requestedLocale = null,
        array $supportedLocales = [],
    ): ?string {
        if ($request === null && $requestedLocale === null) {
            return null;
        }

        $acceptLanguage = $request?->headers()->get('accept-language');
        $resolver       = new LocaleResolver();

        return $resolver->resolve(
            requestedLocale:      $requestedLocale,
            acceptLanguageHeader: $acceptLanguage,
            defaultLocale:        $this->defaultLocale,
            supportedLocales:     $supportedLocales !== [] ? $supportedLocales : $this->supportedLocales,
        );
    }
}
