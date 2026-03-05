<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Localization\AcceptLanguageResolver;

final class AcceptLanguageResolverTest extends TestCase
{
    private AcceptLanguageResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AcceptLanguageResolver();
    }

    // -- Empty / trivial inputs -----------------------------------------------

    public function testReturnsDefaultWhenHeaderIsEmpty(): void
    {
        self::assertSame('en', $this->resolver->resolve(''));
    }

    public function testReturnsDefaultWhenHeaderIsWhitespace(): void
    {
        self::assertSame('fr', $this->resolver->resolve('   ', defaultLocale: 'fr'));
    }

    // -- No supported-locale filter (open accept-any mode) --------------------

    public function testReturnsFirstNormalizedLocaleWhenNoSupportedList(): void
    {
        self::assertSame('fr', $this->resolver->resolve('fr, en'));
    }

    public function testNormalizesLocaleCase(): void
    {
        // FR-ca → fr-CA (lang lowercase, region uppercase)
        self::assertSame('fr-CA', $this->resolver->resolve('FR-ca'));
    }

    public function testNormalizesUnderscoreToHyphen(): void
    {
        self::assertSame('fr-CA', $this->resolver->resolve('fr_CA'));
    }

    // -- Quality value ordering -----------------------------------------------

    public function testQValueOrderingPicksHighestQuality(): void
    {
        // en has higher q, so it should win even though fr appears first
        self::assertSame('en', $this->resolver->resolve('fr;q=0.5, en;q=1.0'));
    }

    public function testImplicitQValueOfOneBeatsExplicitLowerQ(): void
    {
        // fr has implicit q=1.0 so it beats de;q=0.9
        self::assertSame('fr', $this->resolver->resolve('de;q=0.9, fr'));
    }

    public function testMultipleCandidatesOrderedCorrectly(): void
    {
        // fr;q=0.9 > de;q=0.8 > en;q=0.7
        self::assertSame('fr', $this->resolver->resolve('en;q=0.7, fr;q=0.9, de;q=0.8'));
    }

    public function testQZeroCandidateIsTreatedAsNotAcceptable(): void
    {
        self::assertSame('en', $this->resolver->resolve('fr;q=0, en;q=0.9'));
    }

    public function testQValueAboveOneIsClampedToOne(): void
    {
        // fr is clamped to q=1.0 and stays ahead of en;q=0.9.
        self::assertSame('fr', $this->resolver->resolve('fr;q=1.5, en;q=0.9'));
    }

    public function testNegativeQValueIsClampedToZeroAndRejected(): void
    {
        self::assertSame('en', $this->resolver->resolve('fr;q=-0.2, en;q=0.5'));
    }

    public function testEqualQValuesKeepHeaderOrder(): void
    {
        self::assertSame('fr', $this->resolver->resolve('fr;q=0.8, en;q=0.8'));
    }

    public function testAllQZeroCandidatesFallBackToDefault(): void
    {
        self::assertSame('en', $this->resolver->resolve(
            acceptLanguageHeader: 'fr;q=0, de;q=0.0',
            supportedLocales:     ['en', 'fr', 'de'],
            defaultLocale:        'en',
        ));
    }

    public function testWildcardIsNotTreatedAsLiteralLocale(): void
    {
        self::assertSame('en', $this->resolver->resolve(
            acceptLanguageHeader: '*, fr;q=0',
            supportedLocales:     ['en', 'fr'],
            defaultLocale:        'en',
        ));
    }

    public function testWildcardIsIgnoredWhenSpecificSupportedLocaleExists(): void
    {
        self::assertSame('fr', $this->resolver->resolve(
            acceptLanguageHeader: '*, fr;q=0.8',
            supportedLocales:     ['en', 'fr'],
            defaultLocale:        'en',
        ));
    }

    // -- Supported-locales filter ---------------------------------------------

    public function testFiltersToSupportedLocales(): void
    {
        // de and es are not supported; fr is, so fr should win
        self::assertSame('fr', $this->resolver->resolve('de, es, fr', supportedLocales: ['en', 'fr']));
    }

    public function testReturnsDefaultWhenNoHeaderCandidateIsSupported(): void
    {
        self::assertSame('en', $this->resolver->resolve('de, es', supportedLocales: ['en', 'fr'], defaultLocale: 'en'));
    }

    public function testNormalizesSupportedLocalesBeforeMatching(): void
    {
        self::assertSame('fr-CA', $this->resolver->resolve(
            acceptLanguageHeader: 'fr-ca, en;q=0.9',
            supportedLocales:     ['FR_ca', 'EN'],
            defaultLocale:        'EN',
        ));
    }

    public function testNormalizesDefaultLocaleFallback(): void
    {
        self::assertSame('fr-CA', $this->resolver->resolve(
            acceptLanguageHeader: 'de, es',
            supportedLocales:     ['en', 'fr-CA'],
            defaultLocale:        'FR_ca',
        ));
    }

    // -- Regional fallback (fr-CA → fr) ---------------------------------------

    public function testRegionalFallbackFromHeaderToBaseLanguage(): void
    {
        // fr-CA is not in supported list; base language fr is → return fr
        self::assertSame('fr', $this->resolver->resolve('fr-CA', supportedLocales: ['en', 'fr']));
    }

    public function testRegionalLocaleReturnedDirectlyWhenInSupportedList(): void
    {
        self::assertSame('fr-CA', $this->resolver->resolve('fr-CA', supportedLocales: ['en', 'fr-CA']));
    }

    public function testRegionalFallbackNoSupportedListReturnsFull(): void
    {
        // No supported list → regional locale returned as-is (normalized)
        self::assertSame('fr-CA', $this->resolver->resolve('fr-CA'));
    }

    // -- Requested-locale override --------------------------------------------

    public function testRequestedLocaleOverridesHeader(): void
    {
        // Even though header says en, explicit requestedLocale=fr wins
        self::assertSame('fr', $this->resolver->resolve('en', requestedLocale: 'fr'));
    }

    public function testRequestedLocaleNormalized(): void
    {
        self::assertSame('fr-CA', $this->resolver->resolve('en', requestedLocale: 'FR-ca'));
    }

    public function testRequestedLocaleRegionalFallbackInSupportedList(): void
    {
        // requestedLocale=fr-CA, supported has fr but not fr-CA → return fr
        self::assertSame('fr', $this->resolver->resolve(
            acceptLanguageHeader: 'en',
            supportedLocales:     ['en', 'fr'],
            requestedLocale:      'fr-CA',
        ));
    }

    public function testRequestedLocaleNotInSupportedFallsToHeader(): void
    {
        // requestedLocale=de, not supported; header has fr which is supported
        self::assertSame('fr', $this->resolver->resolve(
            acceptLanguageHeader: 'fr, en',
            supportedLocales:     ['en', 'fr'],
            requestedLocale:      'de',
        ));
    }

    public function testRequestedLocaleNotInSupportedNorBaseLanguageFallsToDefault(): void
    {
        self::assertSame('en', $this->resolver->resolve(
            acceptLanguageHeader: 'de',
            supportedLocales:     ['en', 'fr'],
            defaultLocale:        'en',
            requestedLocale:      'es',
        ));
    }

    // -- Default fallback chain -----------------------------------------------

    public function testDefaultFallbackWhenNothingMatches(): void
    {
        self::assertSame('en', $this->resolver->resolve(
            acceptLanguageHeader: 'de, es',
            supportedLocales:     ['en', 'fr'],
            defaultLocale:        'en',
        ));
    }

    public function testCustomDefaultLocaleUsedAsFallback(): void
    {
        self::assertSame('fr', $this->resolver->resolve(
            acceptLanguageHeader: 'de',
            supportedLocales:     ['en', 'fr'],
            defaultLocale:        'fr',
        ));
    }

    // -- Complex real-world header strings ------------------------------------

    public function testRealWorldBrowserHeader(): void
    {
        // Typical browser: en-US first, then en, then wildcard
        self::assertSame('en', $this->resolver->resolve(
            acceptLanguageHeader: 'en-US,en;q=0.9,fr;q=0.8',
            supportedLocales:     ['en', 'fr'],
        ));
    }

    public function testRealWorldBrowserHeaderPrefersRegionalWhenSupported(): void
    {
        self::assertSame('en-US', $this->resolver->resolve(
            acceptLanguageHeader: 'en-US,en;q=0.9,fr;q=0.8',
            supportedLocales:     ['en-US', 'fr'],
        ));
    }

    public function testFrenchCanadianBrowserHeaderFallsToFr(): void
    {
        self::assertSame('fr', $this->resolver->resolve(
            acceptLanguageHeader: 'fr-CA,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            supportedLocales:     ['en', 'fr'],
        ));
    }
}
