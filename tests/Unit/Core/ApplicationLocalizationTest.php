<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Localization\LocaleLoaderInterface;

/**
 * Unit + light-integration tests for Application::transFromRequest().
 *
 * All translation catalog lookups use an in-memory loader so no file I/O
 * or real HTTP infrastructure is required.
 */
final class ApplicationLocalizationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeLoader(): LocaleLoaderInterface
    {
        return new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return match ($locale) {
                    'fr'    => ['greeting' => 'Bonjour', 'bye' => 'Au revoir'],
                    'de'    => ['greeting' => 'Guten Tag'],
                    'es'    => ['greeting' => 'Hola'],
                    default => ['greeting' => 'Hello', 'bye' => 'Goodbye'],
                };
            }
        };
    }

    private function requestWithHeader(string $acceptLanguage): Request
    {
        return Request::fromArray('GET', '/', headers: ['accept-language' => $acceptLanguage]);
    }

    // -------------------------------------------------------------------------
    // trans() — existing behaviour unchanged
    // -------------------------------------------------------------------------

    public function testTransWithExplicitLocale(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        self::assertSame('Bonjour', $app->trans('greeting', locale: 'fr'));
    }

    public function testTransFallsToDefaultLocaleWhenMissing(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        // 'bye' exists only in en and fr; requesting 'de' falls through to en
        self::assertSame('Goodbye', $app->trans('bye', locale: 'de'));
    }

    // -------------------------------------------------------------------------
    // transFromRequest() — no signals → translator default locale
    // -------------------------------------------------------------------------

    public function testTransFromRequestNoRequestNoLocaleUsesTranslatorDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'fr')
            ->build();

        self::assertSame('Bonjour', $app->transFromRequest('greeting'));
    }

    public function testTransFromRequestNoRequestNoLocaleWithEnDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        self::assertSame('Hello', $app->transFromRequest('greeting'));
    }

    // -------------------------------------------------------------------------
    // transFromRequest() — Accept-Language header
    // -------------------------------------------------------------------------

    public function testTransFromRequestUsesAcceptLanguageHeader(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest('greeting', request: $this->requestWithHeader('fr, en;q=0.9'));

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestQValueOrderingRespected(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        // fr;q=0.9 beats de;q=0.5 and en;q=0.8
        $response = $app->transFromRequest('greeting', request: $this->requestWithHeader('de;q=0.5, fr;q=0.9, en;q=0.8'));

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestEmptyHeaderUsesDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest('greeting', request: $this->requestWithHeader(''));

        self::assertSame('Hello', $response);
    }

    // -------------------------------------------------------------------------
    // transFromRequest() — explicit requestedLocale
    // -------------------------------------------------------------------------

    public function testTransFromRequestExplicitRequestedLocaleWins(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        // Header says de, but explicit fr wins
        $response = $app->transFromRequest(
            'greeting',
            request:         $this->requestWithHeader('de'),
            requestedLocale: 'fr',
        );

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestRequestedLocaleWithNullRequest(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        // No HTTP request at all; just an explicit locale token
        self::assertSame('Bonjour', $app->transFromRequest('greeting', requestedLocale: 'fr'));
    }

    public function testTransFromRequestRequestedLocaleNormalized(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        // FR-fr should normalize to fr, which the loader handles
        self::assertSame('Bonjour', $app->transFromRequest('greeting', requestedLocale: 'FR'));
    }

    // -------------------------------------------------------------------------
    // transFromRequest() — per-call supportedLocales override
    // -------------------------------------------------------------------------

    public function testTransFromRequestPerCallSupportedLocalesFilter(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->build();

        // de has highest q but is not in per-call supported list; fr is → fr wins
        $response = $app->transFromRequest(
            'greeting',
            request:         $this->requestWithHeader('de;q=0.9, fr;q=0.8, en;q=0.7'),
            supportedLocales: ['en', 'fr'],
        );

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestPerCallSupportedLocalesOverrideAppLevel(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])          // app-level: only en/fr
            ->build();

        // Per-call allows 'de' → de wins even though app level would block it
        $response = $app->transFromRequest(
            'greeting',
            request:         $this->requestWithHeader('de, fr'),
            supportedLocales: ['de', 'fr'],
        );

        self::assertSame('Guten Tag', $response);
    }

    // -------------------------------------------------------------------------
    // transFromRequest() — app-level supportedLocales
    // -------------------------------------------------------------------------

    public function testTransFromRequestAppLevelSupportedLocalesUsedByDefault(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        // de not in app-level supported list → skipped; fr is next → fr
        $response = $app->transFromRequest(
            'greeting',
            request: $this->requestWithHeader('de, fr'),
        );

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestAppLevelFallsToDefaultWhenNoneSupported(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        $response = $app->transFromRequest(
            'greeting',
            request: $this->requestWithHeader('de, es'),
        );

        self::assertSame('Hello', $response);
    }

    // -------------------------------------------------------------------------
    // transFromRequest() — regional fallback
    // -------------------------------------------------------------------------

    public function testTransFromRequestRegionalFallbackViaSupportedList(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        // fr-CA not in supported list but base 'fr' is → fr
        $response = $app->transFromRequest(
            'greeting',
            request: $this->requestWithHeader('fr-CA'),
        );

        self::assertSame('Bonjour', $response);
    }

    public function testTransFromRequestRequestedLocaleRegionalFallback(): void
    {
        $app = ApplicationBuilder::create()
            ->withLocaleLoader($this->makeLoader(), defaultLocale: 'en')
            ->withSupportedLocales(['en', 'fr'])
            ->build();

        // Explicit fr-CA; supported has fr but not fr-CA → return fr
        $response = $app->transFromRequest(
            'greeting',
            requestedLocale: 'fr-CA',
        );

        self::assertSame('Bonjour', $response);
    }

    // -------------------------------------------------------------------------
    // transFromRequest() — parameter interpolation
    // -------------------------------------------------------------------------

    public function testTransFromRequestInterpolatesParameters(): void
    {
        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return $locale === 'fr'
                    ? ['welcome' => 'Bienvenue {name}']
                    : ['welcome' => 'Welcome {name}'];
            }
        };

        $app = ApplicationBuilder::create()
            ->withLocaleLoader($loader, defaultLocale: 'en')
            ->build();

        $response = $app->transFromRequest(
            'welcome',
            ['name' => 'Alice'],
            request: $this->requestWithHeader('fr'),
        );

        self::assertSame('Bienvenue Alice', $response);
    }
}
