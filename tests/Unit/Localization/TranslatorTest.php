<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;
use Zephyrus\Localization\JsonLocaleLoader;
use Zephyrus\Localization\Translator;

final class TranslatorTest extends TestCase
{
    public function testTranslatesFromRequestedLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Bonjour', $translator->trans('messages.plain', locale: 'fr'));
    }

    public function testFallsBackToDefaultLocaleWhenKeyMissingInRequestedLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Welcome Alice', $translator->trans('messages.welcome', ['name' => 'Alice'], 'fr'));
    }

    public function testReturnsKeyWhenMissingInAllCatalogs(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('unknown.key', $translator->trans('unknown.key', locale: 'fr'));
    }

    public function testInterpolatesParameters(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('The email field is required', $translator->trans('errors.required', ['field' => 'email']));
    }

    public function testFallsBackFromRegionalLocaleToLanguageLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Bonjour', $translator->trans('messages.plain', locale: 'fr-CA'));
    }

    public function testInterpolationSupportsTextPipes(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame(
            'Hello ALICE / alice / Alice',
            $translator->trans('messages.pipe_text', ['name' => 'alice']),
        );
    }

    public function testInterpolationSupportsNumberPipeWithPrecision(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame(
            'Invoice total: 12.35',
            $translator->trans('messages.pipe_number', ['total' => 12.3456]),
        );
    }

    public function testInterpolationLeavesUnknownParameterPlaceholderUntouched(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Welcome {name}', $translator->trans('messages.welcome'));
    }

    public function testInterpolationIgnoresUnknownPipeName(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Value: alice', $translator->trans('messages.pipe_unknown', ['name' => 'alice']));
    }

    // -----------------------------------------------------------------
    // number pipe — grouping separators
    // -----------------------------------------------------------------

    public function testNumberPipeWithThousandsSeparator(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame(
            'Amount: 1,234.57',
            $translator->trans('messages.pipe_number_grouped', ['total' => 1234.5678]),
        );
    }

    public function testNumberPipeBackwardCompatible(): void
    {
        $translator = $this->buildTranslator();

        // Existing behaviour: number:2 uses "." decimal, no thousands sep.
        self::assertSame(
            'Invoice total: 12.35',
            $translator->trans('messages.pipe_number', ['total' => 12.3456]),
        );
    }

    public function testNumberPipeNonNumericValuePassedThrough(): void
    {
        $translator = $this->buildTranslator();

        // Inline value: use direct parameter without a catalog key.
        self::assertSame('n/a', $translator->trans('{v|number:2}', ['v' => 'n/a']));
    }

    // -----------------------------------------------------------------
    // truncate pipe
    // -----------------------------------------------------------------

    public function testTruncatePipeShorterThanLimit(): void
    {
        $translator = $this->buildTranslator();

        // "Hello" (5 chars) ≤ 8 → untouched
        self::assertSame('Title: Hello', $translator->trans('messages.pipe_truncate', ['title' => 'Hello']));
    }

    public function testTruncatePipeExactlyAtLimit(): void
    {
        $translator = $this->buildTranslator();

        // "12345678" (8 chars) == limit → untouched
        self::assertSame('Tag: Hello', $translator->trans('messages.pipe_truncate_exact', ['tag' => 'Hello']));
    }

    public function testTruncatePipeLongerThanLimit(): void
    {
        $translator = $this->buildTranslator();

        // "Long title here" > 8 chars → "Long tit…"
        self::assertSame('Title: Long tit…', $translator->trans('messages.pipe_truncate', ['title' => 'Long title here']));
    }

    public function testTruncatePipeCustomSuffix(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Title: Long tit...', $translator->trans('messages.pipe_truncate_suffix', ['title' => 'Long title here']));
    }

    public function testTruncatePipeChainedWithUpper(): void
    {
        $translator = $this->buildTranslator();

        // "Hello World" (11 chars) > 5 → "Hello" + "…" = "Hello…", then upper → "HELLO…"
        self::assertSame('HELLO…', $translator->trans('messages.pipe_chained_truncate_upper', ['title' => 'Hello World']));
    }

    // -----------------------------------------------------------------
    // plural pipe
    // -----------------------------------------------------------------

    public function testPluralPipeSingular(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('1 item', $translator->trans('messages.pipe_plural', ['count' => 1]));
    }

    public function testPluralPipePlural(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('5 items', $translator->trans('messages.pipe_plural', ['count' => 5]));
    }

    public function testPluralPipeZero(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('0 items', $translator->trans('messages.pipe_plural', ['count' => 0]));
    }

    public function testPluralPipeAutoSuffix(): void
    {
        $translator = $this->buildTranslator();

        // plural:duck → "duck" for 1, "ducks" for 2
        self::assertSame('1 duck', $translator->trans('messages.pipe_plural_auto', ['count' => 1]));
        self::assertSame('3 ducks', $translator->trans('messages.pipe_plural_auto', ['count' => 3]));
    }

    // -----------------------------------------------------------------
    // default pipe
    // -----------------------------------------------------------------

    public function testDefaultPipeEmptyStringUseFallback(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Hello, Guest!', $translator->trans('messages.pipe_default', ['name' => '']));
    }

    public function testDefaultPipeNonEmptyValuePassedThrough(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Hello, Alice!', $translator->trans('messages.pipe_default', ['name' => 'Alice']));
    }

    // -----------------------------------------------------------------
    // ltrim / rtrim pipes
    // -----------------------------------------------------------------

    public function testLtrimPipe(): void
    {
        $translator = $this->buildTranslator();

        // ltrim removes only leading whitespace; trailing spaces are preserved.
        self::assertSame('hello   ', $translator->trans('{v|ltrim}', ['v' => '   hello   ']));
    }

    public function testRtrimPipe(): void
    {
        $translator = $this->buildTranslator();

        // rtrim removes only trailing whitespace; leading spaces are preserved.
        self::assertSame('   hello', $translator->trans('{v|rtrim}', ['v' => '   hello   ']));
    }

    // -----------------------------------------------------------------
    // resolveLocaleChain — regional default locale
    // -----------------------------------------------------------------

    public function testLocaleChainWithRegionalDefaultLocaleFallsThroughToRegionalDefault(): void
    {
        // defaultLocale 'fr-CA': chain for 'de' → ['de', 'fr-CA', 'fr']
        $loader = new class implements \Zephyrus\Localization\LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return match ($locale) {
                    'fr-CA' => ['greeting' => 'Bonjour (CA)'],
                    'fr'    => ['greeting' => 'Bonjour'],
                    default => [],
                };
            }
        };

        $translator = new Translator($loader, 'fr-CA');

        // 'de' has no catalog → falls through to 'fr-CA' (regional default)
        self::assertSame('Bonjour (CA)', $translator->trans('greeting', locale: 'de'));
    }

    public function testLocaleChainWithRegionalDefaultLocaleDeduplicatesBaseLanguage(): void
    {
        // defaultLocale 'fr-CA': chain for 'fr' → ['fr', 'fr-CA'] (base already in chain so 'fr'
        // is not appended again from the defaultBase extraction).
        $loader = new class implements \Zephyrus\Localization\LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return match ($locale) {
                    'fr'    => ['greeting' => 'Bonjour'],
                    'fr-CA' => ['greeting' => 'Bonjour (CA)'],
                    default => [],
                };
            }
        };

        $translator = new Translator($loader, 'fr-CA');

        // Requesting 'fr' directly hits the 'fr' catalog first
        self::assertSame('Bonjour', $translator->trans('greeting', locale: 'fr'));
    }

    // -----------------------------------------------------------------
    // applyTruncate — zero-length guard
    // -----------------------------------------------------------------

    public function testTruncatePipeZeroLengthReturnsUnchanged(): void
    {
        $translator = $this->buildTranslator();

        // truncate:0 → length ≤ 0 → value returned untouched
        self::assertSame('hello', $translator->trans('{v|truncate:0}', ['v' => 'hello']));
    }

    // -----------------------------------------------------------------
    // applyPipes — empty segment guard (trailing pipe)
    // -----------------------------------------------------------------

    public function testEmptyPipeSegmentFromTrailingPipeIsIgnored(): void
    {
        $translator = $this->buildTranslator();

        // trailing '|' splits into ['upper', ''] — the empty segment must be skipped
        self::assertSame('HELLO', $translator->trans('{v|upper|}', ['v' => 'hello']));
    }

    private function buildTranslator(): Translator
    {
        $loader = new JsonLocaleLoader(__DIR__ . '/../../Fixtures/locales');

        return new Translator($loader, 'en');
    }
}
