<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Localization\LocaleResolver;

final class LocaleResolverTest extends TestCase
{
    private LocaleResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new LocaleResolver();
    }

    // --- Null / empty inputs --------------------------------------------------

    public function testReturnsDefaultWhenBothInputsNull(): void
    {
        self::assertSame('en', $this->resolver->resolve(null, null, 'en'));
    }

    public function testReturnsCustomDefaultWhenBothInputsNull(): void
    {
        self::assertSame('fr', $this->resolver->resolve(null, null, 'fr'));
    }

    public function testReturnsDefaultWhenHeaderIsEmptyString(): void
    {
        self::assertSame('en', $this->resolver->resolve(null, '', 'en'));
    }

    public function testReturnsDefaultWhenHeaderIsWhitespace(): void
    {
        self::assertSame('en', $this->resolver->resolve(null, '   ', 'en'));
    }

    // --- Requested locale takes priority -------------------------------------

    public function testRequestedLocaleTakesPriorityOverHeader(): void
    {
        // header says en, but explicit requestedLocale=fr wins
        self::assertSame('fr', $this->resolver->resolve('fr', 'en', 'en'));
    }

    public function testRequestedLocaleIsNormalized(): void
    {
        // FR-ca → fr-CA
        self::assertSame('fr-CA', $this->resolver->resolve('FR-ca', null, 'en'));
    }

    public function testRequestedLocaleUnderscoreNormalized(): void
    {
        // fr_CA → fr-CA
        self::assertSame('fr-CA', $this->resolver->resolve('fr_CA', null, 'en'));
    }

    public function testRequestedLocaleWithNullHeader(): void
    {
        self::assertSame('de', $this->resolver->resolve('de', null, 'en'));
    }

    // --- Q-value ordering ----------------------------------------------------

    public function testQValueOrderingPicksHighestQuality(): void
    {
        // en has higher q so it beats fr even though fr appears first
        self::assertSame('en', $this->resolver->resolve(null, 'fr;q=0.5, en;q=1.0', 'de'));
    }

    public function testImplicitQValueOfOneBeatsExplicitLower(): void
    {
        // fr has implicit q=1.0 so it beats de;q=0.9
        self::assertSame('fr', $this->resolver->resolve(null, 'de;q=0.9, fr', 'en'));
    }

    public function testMultipleCandidatesOrderedCorrectly(): void
    {
        // fr;q=0.9 > de;q=0.8 > en;q=0.7
        self::assertSame('fr', $this->resolver->resolve(null, 'en;q=0.7, fr;q=0.9, de;q=0.8', 'es'));
    }

    // --- Supported-locales filter --------------------------------------------

    public function testFiltersToSupportedLocales(): void
    {
        // de and es are not supported; fr is, so fr should win
        self::assertSame('fr', $this->resolver->resolve(null, 'de, es, fr', 'en', ['en', 'fr']));
    }

    public function testReturnsDefaultWhenNoCandidateIsSupported(): void
    {
        self::assertSame('en', $this->resolver->resolve(null, 'de, es', 'en', ['en', 'fr']));
    }

    public function testNoSupportedListAcceptsAnyCandidate(): void
    {
        // Open mode — no filter; first normalized candidate returned
        self::assertSame('de', $this->resolver->resolve(null, 'de, fr', 'en'));
    }

    // --- Regional fallback (fr-CA → fr) --------------------------------------

    public function testRegionalFallbackFromHeaderToBaseLanguage(): void
    {
        // fr-CA not in supported list but base 'fr' is
        self::assertSame('fr', $this->resolver->resolve(null, 'fr-CA', 'en', ['en', 'fr']));
    }

    public function testRegionalLocaleReturnedDirectlyWhenInSupportedList(): void
    {
        self::assertSame('fr-CA', $this->resolver->resolve(null, 'fr-CA', 'en', ['en', 'fr-CA']));
    }

    public function testRegionalFallbackInOpenMode(): void
    {
        // No supported list → regional locale returned as-is (normalized)
        self::assertSame('fr-CA', $this->resolver->resolve(null, 'fr-CA', 'en'));
    }

    // --- Requested locale + supported list interactions ----------------------

    public function testRequestedLocaleRegionalFallbackWhenBaseIsSupported(): void
    {
        // requestedLocale=fr-CA but only fr (base) is supported → return fr
        self::assertSame('fr', $this->resolver->resolve('fr-CA', null, 'en', ['en', 'fr']));
    }

    public function testRequestedLocaleNotInSupportedFallsToHeader(): void
    {
        // de not supported; header has fr which is supported → fr
        self::assertSame('fr', $this->resolver->resolve('de', 'fr, en', 'en', ['en', 'fr']));
    }

    public function testRequestedLocaleNotSupportedNorBaseLanguageFallsToDefault(): void
    {
        self::assertSame('en', $this->resolver->resolve('es', 'de', 'en', ['en', 'fr']));
    }

    // --- Default fallback chain ----------------------------------------------

    public function testDefaultFallbackWhenNothingMatches(): void
    {
        self::assertSame('en', $this->resolver->resolve(null, 'de, es', 'en', ['en', 'fr']));
    }

    public function testCustomDefaultLocaleUsedAsFallback(): void
    {
        self::assertSame('fr', $this->resolver->resolve(null, 'de', 'fr', ['en', 'fr']));
    }

    // --- Real-world browser header strings -----------------------------------

    public function testRealWorldBrowserHeaderFallsToBaseLanguage(): void
    {
        // en-US not in supported list but en is
        self::assertSame('en', $this->resolver->resolve(
            null,
            'en-US,en;q=0.9,fr;q=0.8',
            'en',
            ['en', 'fr'],
        ));
    }

    public function testRealWorldBrowserHeaderPrefersRegionalWhenSupported(): void
    {
        self::assertSame('en-US', $this->resolver->resolve(
            null,
            'en-US,en;q=0.9,fr;q=0.8',
            'en',
            ['en-US', 'fr'],
        ));
    }

    public function testFrenchCanadianHeaderFallsToFr(): void
    {
        self::assertSame('fr', $this->resolver->resolve(
            null,
            'fr-CA,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            'en',
            ['en', 'fr'],
        ));
    }

    public function testRequestedLocaleOverrideInRealWorldScenario(): void
    {
        // User explicitly chose 'fr' via URL segment despite header preferring 'de'
        self::assertSame('fr', $this->resolver->resolve(
            'fr',
            'de,en;q=0.9',
            'en',
            ['en', 'fr', 'de'],
        ));
    }
}
