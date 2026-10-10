<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Formatting\FormatterException;
use Zephyrus\Localization\JsonLocaleLoader;
use Zephyrus\Localization\LocalizationException;
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

    public function testNormalizesUnderscoreAndRegionCaseInRequestedLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('Bonjour', $translator->trans('messages.plain', locale: 'FR_ca'));
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

    public function testUnknownPipeNameWithoutFormatterThrows(): void
    {
        App::reset();

        $translator = $this->buildTranslator();

        $this->expectException(LocalizationException::class);
        $this->expectExceptionMessage('App::setFormatter()');
        $translator->trans('messages.pipe_unknown', ['name' => 'alice']);
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

    public function testPluralPipeUsesSingularOnlyForOneInUnknownLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('a', $translator->trans('{n|plural:a:b}', ['n' => 1], 'xx'));
        self::assertSame('b', $translator->trans('{n|plural:a:b}', ['n' => 0], 'xx'));
        self::assertSame('b', $translator->trans('{n|plural:a:b}', ['n' => 2], 'xx'));
    }

    public function testPluralPipeFallsBackToExactOneRuleForLocaleIcuRejects(): void
    {
        $translator = $this->buildTranslator();
        $locale = str_repeat('x', 300);

        self::assertSame('a', $translator->trans('{n|plural:a:b}', ['n' => 1], $locale));
        self::assertSame('b', $translator->trans('{n|plural:a:b}', ['n' => 2], $locale));
    }

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

    public function testPluralPipeUsesFrenchSingularForZeroAndOne(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('0 champ', $translator->trans('messages.pipe_plural', ['count' => 0], 'fr'));
        self::assertSame('1 champ', $translator->trans('messages.pipe_plural', ['count' => 1], 'fr'));
        self::assertSame('1.5 champ', $translator->trans('messages.pipe_plural', ['count' => 1.5], 'fr'));
    }

    public function testPluralPipeUsesFrenchPluralFromTwo(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('2 champs', $translator->trans('messages.pipe_plural', ['count' => 2], 'fr'));
    }

    public function testPluralPipeFollowsLanguageOfRegionalLocale(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('0 champ', $translator->trans('messages.pipe_plural', ['count' => 0], 'fr_CA'));
    }

    public function testPluralPipeFollowsEnglishRuleForKeyResolvedFromFallbackCatalog(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('0 ducks', $translator->trans('messages.pipe_plural_auto', ['count' => 0], 'fr'));
        self::assertSame('1 duck', $translator->trans('messages.pipe_plural_auto', ['count' => 1], 'fr'));
    }

    public function testPluralPipeUsesEnglishRuleForZero(): void
    {
        $translator = $this->buildTranslator();

        self::assertSame('0 items', $translator->trans('messages.pipe_plural', ['count' => 0], 'en'));
        self::assertSame('2 items', $translator->trans('messages.pipe_plural', ['count' => 2], 'en'));
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

    public function testLocaleChainNormalizesRegionalDefaultLocale(): void
    {
        $loader = new class implements \Zephyrus\Localization\LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return match ($locale) {
                    'fr-CA' => ['greeting' => 'Bonjour (CA)'],
                    default => [],
                };
            }
        };

        $translator = new Translator($loader, 'FR_ca');

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

    // -----------------------------------------------------------------
    // applyPipes — Formatter bridge (custom formatters)
    // -----------------------------------------------------------------

    public function testUnknownPipeDelegatesToFormatterBuiltInMethod(): void
    {
        $formatter = new Formatter('en_US', 'USD');
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();
        $result = $translator->trans('{amount|money}', ['amount' => '19.99']);

        self::assertStringContainsString('$', $result);
        self::assertStringContainsString('19.99', $result);

        App::reset();
    }

    public function testUnknownPipeDelegatesToCustomRegisteredFormatter(): void
    {
        $formatter = new Formatter('en_US');
        $formatter->register('wallet', function (string $wallet): string {
            return substr($wallet, 0, 6) . '...' . substr($wallet, -4);
        });
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();
        $result = $translator->trans('{address|wallet}', ['address' => '0xABCDEF1234567890']);

        self::assertSame('0xABCD...7890', $result);

        App::reset();
    }

    public function testFormatterPipeWithoutFormatterThrowsInsteadOfRenderingRawValue(): void
    {
        App::reset();

        $translator = $this->buildTranslator();

        try {
            $translator->trans('SSN: {ssn|mask}', ['ssn' => '123456789']);
            self::fail('A formatter pipe without a Formatter must not render the raw value.');
        } catch (LocalizationException $e) {
            self::assertStringContainsString('"mask"', $e->getMessage());
            self::assertStringContainsString('App::setFormatter()', $e->getMessage());
            self::assertStringNotContainsString('123456789', $e->getMessage());
        }
    }

    public function testFormatterMethodFailureIsNotSwallowed(): void
    {
        $formatter = new Formatter('en_US');
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        try {
            $translator->trans('{v|ordinal}', ['v' => 'not-a-number']);
            self::fail('A built-in formatter failure must not render silently.');
        } catch (LocalizationException $e) {
            self::assertInstanceOf(\TypeError::class, $e->getPrevious());
            self::assertStringContainsString('"ordinal"', $e->getMessage());
            self::assertStringContainsString('{v|ordinal}', $e->getMessage());
            self::assertStringNotContainsString('not-a-number', $e->getMessage());
        } finally {
            App::reset();
        }
    }

    public function testBuiltInFormatterTypeErrorNamesPipeAndKeyWithoutValue(): void
    {
        App::setFormatter(new Formatter('en_US', 'USD'));

        $translator = $this->buildTranslator();

        try {
            $translator->trans('Price: {p|money}', ['p' => 'abc']);
            self::fail('A non-numeric value must not reach money() as text.');
        } catch (LocalizationException $e) {
            self::assertSame('Unable to apply pipe "money" in translation key "Price: {p|money}".', $e->getMessage());
            self::assertInstanceOf(\TypeError::class, $e->getPrevious());
        } finally {
            App::reset();
        }
    }

    public function testBuiltInFormatterExceptionIsWrappedWithPipeAndKey(): void
    {
        App::setFormatter(new Formatter('en_US'));

        $translator = $this->buildTranslator();

        try {
            $translator->trans('Sent {d|date}', ['d' => 'not-a-date']);
            self::fail('An unparseable date must not render silently.');
        } catch (LocalizationException $e) {
            self::assertStringContainsString('"date"', $e->getMessage());
            self::assertInstanceOf(FormatterException::class, $e->getPrevious());
            self::assertStringNotContainsString('not-a-date', $e->getMessage());
        } finally {
            App::reset();
        }
    }

    public function testCustomFormatterTypeErrorPropagatesUnwrapped(): void
    {
        $formatter = new Formatter('en_US');
        $formatter->register('tagged', static function (string $value): string {
            throw new \TypeError('custom failure');
        });
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        $this->expectException(\TypeError::class);
        try {
            $translator->trans('{v|tagged}', ['v' => 'x']);
        } finally {
            App::reset();
        }
    }

    public function testMisspelledFormatterPipeThrowsLocalizationException(): void
    {
        App::setFormatter(new Formatter('en_US'));

        $translator = $this->buildTranslator();

        try {
            $translator->trans('Rank: {n|ordnial}', ['n' => '4111-secret']);
            self::fail('An unknown pipe must not render silently.');
        } catch (LocalizationException $e) {
            self::assertStringContainsString('"ordnial"', $e->getMessage());
            self::assertStringContainsString('Rank: {n|ordnial}', $e->getMessage());
            self::assertStringNotContainsString('4111-secret', $e->getMessage());
        } finally {
            App::reset();
        }
    }

    public function testUnknownPipeMessageListsValidPipeNamesAndRegistrationHint(): void
    {
        $formatter = new Formatter('en_US');
        $formatter->register('wallet', static fn (string $value): string => $value);
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        try {
            $translator->trans('{v|walet}', ['v' => 'x']);
            self::fail('An unknown pipe must not render silently.');
        } catch (LocalizationException $e) {
            self::assertStringContainsString('lower, upper', $e->getMessage());
            self::assertStringContainsString('money', $e->getMessage());
            self::assertStringContainsString('wallet', $e->getMessage());
            self::assertStringContainsString('Formatter::register()', $e->getMessage());
        } finally {
            App::reset();
        }
    }

    public function testCustomFormatterExceptionPropagatesInsteadOfRawValue(): void
    {
        $formatter = new Formatter('en_US');
        $formatter->register('mask', static function (string $card): string {
            throw new \RuntimeException('masking failed');
        });
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        try {
            $translator->trans('{card|mask}', ['card' => '4111111111111111']);
            self::fail('A failing formatter must not print the unmasked value.');
        } catch (\RuntimeException $e) {
            self::assertSame('masking failed', $e->getMessage());
        } finally {
            App::reset();
        }
    }

    public function testCustomFormatterReceivesNumericStringUnchanged(): void
    {
        $formatter = new Formatter('en_US');
        $formatter->register('tagged', function (string $value): string {
            return 'n=' . $value;
        });
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        self::assertSame('n=42', $translator->trans('{n|tagged}', ['n' => '42']));

        App::reset();
    }

    public function testFormatterPipeWorksWithDecimalType(): void
    {
        $formatter = new Formatter('en_US');
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();
        $result = $translator->trans('Total: {amount|decimal}', ['amount' => '1234.5']);

        self::assertSame('Total: 1,234.50', $result);

        App::reset();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonBuiltInFormatterMethodProvider(): iterable
    {
        yield 'constructor' => ['__construct'];
        yield 'getter' => ['getLocale'];
        yield 'default date pattern' => ['getDefaultDatePattern'];
        yield 'default currency' => ['getDefaultCurrency'];
    }

    #[DataProvider('nonBuiltInFormatterMethodProvider')]
    public function testPipeCannotReachNonFormatterMethods(string $pipe): void
    {
        $formatter = new Formatter('en_US', defaultCurrency: 'USD');
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        try {
            $translator->trans('{v|' . $pipe . '}', ['v' => 'de_DE']);
            self::fail('A pipe that is not a formatter must not be called.');
        } catch (LocalizationException) {
            self::assertSame('en_US', $formatter->getLocale());
        } finally {
            App::reset();
        }
    }

    public function testPipeMatchesBuiltInFormatterNameCaseInsensitively(): void
    {
        App::setFormatter(new Formatter('en_US'));

        $translator = $this->buildTranslator();

        self::assertSame('forty-two', $translator->trans('{n|spellout}', ['n' => '42']));

        App::reset();
    }

    public function testPipeRunsCustomBuiltInOverrideForAnyCaseOfItsName(): void
    {
        $formatter = new Formatter('en_US');
        $formatter->register('date', static fn (string $value): string => 'custom date ' . $value);
        $formatter->register('money', static fn (mixed $value): string => 'custom money ' . $value);
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        self::assertSame('custom date 2026-10-09', $translator->trans('{d|Date}', ['d' => '2026-10-09']));
        self::assertSame('custom money 5', $translator->trans('{n|MONEY}', ['n' => '5']));

        App::reset();
    }

    public function testPipeKeepsNumericStringForStringTypedCustomOverrideOfBuiltIn(): void
    {
        $formatter = new Formatter('en_US');
        $formatter->register('money', static fn (string $value): string => "C[$value]");
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        self::assertSame('C[5]', $translator->trans('{n|MONEY}', ['n' => '5']));

        App::reset();
    }

    public function testPipeFormatsIntegerNumericStringWithOrdinal(): void
    {
        App::setFormatter(new Formatter('en_US'));

        $translator = $this->buildTranslator();

        self::assertSame('3rd', $translator->trans('{n|ordinal}', ['n' => '3']));

        App::reset();
    }

    public function testPipeFormatsIntegerNumericStringWithFilesize(): void
    {
        App::setFormatter(new Formatter('en_US'));

        $translator = $this->buildTranslator();

        self::assertSame('1.0 MB', $translator->trans('{n|filesize}', ['n' => '1048576']));

        App::reset();
    }

    public function testEmptyValueSkipsBuiltInFormatterSoDefaultApplies(): void
    {
        App::setFormatter(new Formatter('en_US', 'USD'));

        $translator = $this->buildTranslator();

        self::assertSame('N/D', $translator->trans('{v|money|default:N/D}', ['v' => null]));
        self::assertSame('N/D', $translator->trans('{v|money|default:N/D}', ['v' => '']));

        App::reset();
    }

    public function testEmptyValueIsNeverPassedToCustomFormatter(): void
    {
        $formatter = new Formatter('en_US');
        $formatter->register('strict', static function (string $value): string {
            throw new \RuntimeException('called with ' . $value);
        });
        App::setFormatter($formatter);

        $translator = $this->buildTranslator();

        self::assertSame('none', $translator->trans('{v|strict|default:none}', ['v' => '']));

        App::reset();
    }

    public function testEmptyValueStillRejectsUnknownPipeName(): void
    {
        App::setFormatter(new Formatter('en_US'));

        $translator = $this->buildTranslator();

        $this->expectException(LocalizationException::class);
        try {
            $translator->trans('{v|mony|default:N/D}', ['v' => null]);
        } finally {
            App::reset();
        }
    }

    private function buildTranslator(): Translator
    {
        $loader = new JsonLocaleLoader(__DIR__ . '/../../Fixtures/locales');

        return new Translator($loader, 'en');
    }
}
