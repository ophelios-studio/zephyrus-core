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
     * Translates $key in the locale resolved from, in order: $requestedLocale
     * (URL segment, cookie, form field), the Accept-Language header of $request,
     * then the application default. With neither $request nor $requestedLocale,
     * the translator's own default locale applies.
     *
     * A non-empty $supportedLocales replaces the application allowlist for this call only.
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
     * Resolves the locale with the same policy as transFromRequest(). Returns
     * null when neither $request nor $requestedLocale is given.
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
