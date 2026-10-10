<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigKeys;
use Zephyrus\Core\Config\ConfigurationException;

final class ConfigKeysTest extends TestCase
{
    private const array SPELLINGS = [
        'forceHttps' => ['forceHttps', 'force_https'],
        'csrfEnabled' => ['csrf.enabled', 'csrf.csrf_enabled', 'csrfEnabled', 'csrf_enabled'],
        'password' => ['password'],
        'host' => ['host'],
    ];

    private const string ACCEPTED = 'the accepted keys are forceHttps, csrf.enabled, password, host.';

    public function testEveryAcceptedSpellingPasses(): void
    {
        ConfigKeys::read('example', [
            'force_https' => true,
            'csrf' => ['csrf_enabled' => true],
            'password' => 'secret',
            'host' => null,
        ], self::SPELLINGS);

        $this->addToAssertionCount(1);
    }

    public function testAnUnlistedPropertyIsAcceptedAndSuggestedButLeftOutOfTheList(): void
    {
        ConfigKeys::read('example', ['host' => 'db.example.com'], self::SPELLINGS, unlisted: ['host']);

        try {
            ConfigKeys::read('example', ['timeout' => 30], self::SPELLINGS, unlisted: ['host']);

            self::fail('An unknown key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'example' field 'timeout' is an unknown key: the accepted keys are "
                    . 'forceHttps, csrf.enabled, password.',
                $exception->getMessage(),
            );
        }

        try {
            ConfigKeys::read('example', ['hots' => 'x'], self::SPELLINGS, unlisted: ['host']);

            self::fail('A misspelled key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'example' field 'hots' is an unknown key: did you mean \"host\"?",
                $exception->getMessage(),
            );
        }
    }

    public function testAMisspelledKeyIsRefusedWithTheClosestSpelling(): void
    {
        try {
            ConfigKeys::read('example', ['forceHtps' => true], self::SPELLINGS);

            self::fail('A misspelled key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'example' field 'forceHtps' is an unknown key: did you mean \"forceHttps\"?",
                $exception->getMessage(),
            );
            self::assertSame('example', $exception->section());
            self::assertSame('forceHtps', $exception->field());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function looseSpellings(): iterable
    {
        yield 'PascalCase' => ['ForceHttps', 'forceHttps'];
        yield 'upper snake_case' => ['FORCE_HTTPS', 'force_https'];
        yield 'kebab-case' => ['force-https', 'forceHttps'];
        yield 'lower case glued' => ['forcehttps', 'forceHttps'];
        yield 'snake_case typo' => ['force_htps', 'force_https'];
        yield 'snake_case typo of a nested setting' => ['csrf_enabld', 'csrf_enabled'];
    }

    #[DataProvider('looseSpellings')]
    public function testTheSuggestionMatchesAcrossCaseUnderscoresAndHyphens(string $written, string $suggested): void
    {
        try {
            ConfigKeys::read('example', [$written => true], self::SPELLINGS);

            self::fail('A loose spelling was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringEndsWith('did you mean "' . $suggested . '"?', $exception->getMessage());
        }
    }

    public function testAKeyNearNoSpellingListsTheAcceptedKeys(): void
    {
        try {
            ConfigKeys::read('example', ['timeout' => 30], self::SPELLINGS);

            self::fail('An unknown key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'example' field 'timeout' is an unknown key: " . self::ACCEPTED,
                $exception->getMessage(),
            );
        }
    }

    public function testAKeyInsideANestedMappingIsCheckedAgainstThatMapping(): void
    {
        try {
            ConfigKeys::read('example', ['csrf' => ['enabeld' => false]], self::SPELLINGS);

            self::fail('A misspelled nested key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'example' field 'csrf.enabeld' is an unknown key: did you mean \"csrf.enabled\"?",
                $exception->getMessage(),
            );
            self::assertSame('csrf.enabeld', $exception->field());
        }
    }

    public function testAMisspelledMappingNameIsSuggested(): void
    {
        self::assertSame(
            "Configuration section 'example' field 'csfr' is an unknown key: did you mean \"csrf\"?",
            self::refusalOf(['csfr' => ['enabled' => false]]),
        );
    }

    public function testADottedTopLevelKeyIsNotReadAsANestedOne(): void
    {
        self::assertSame(
            "Configuration section 'example' field 'csrf.enabled' is an unknown key: did you mean \"csrfEnabled\"?",
            self::refusalOf(['csrf.enabled' => false]),
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonMappingValues(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'string' => ['enabled'];
    }

    #[DataProvider('nonMappingValues')]
    public function testAMappingNameHoldingNoMappingIsLeftToTheCaller(mixed $value): void
    {
        ConfigKeys::read('example', ['csrf' => $value], self::SPELLINGS);

        $this->addToAssertionCount(1);
    }

    public function testTheValueOfAnUnknownKeyStaysOutOfTheMessage(): void
    {
        try {
            ConfigKeys::read('example', ['pasword' => 'hunter2-secret'], self::SPELLINGS);

            self::fail('A misspelled key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'example' field 'pasword' is an unknown key: did you mean \"password\"?",
                $exception->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function unusualKeys(): iterable
    {
        yield 'integer key from a YAML list' => [[0 => 'host'], "field '0' is an unknown key: " . self::ACCEPTED];
        yield 'empty key' => [['' => true], 'field "" is an unknown key: ' . self::ACCEPTED];
        yield 'NUL byte' => [["host\0" => 'x'], 'field "host\\u0000" is an unknown key: did you mean "host"?'];
        yield 'line feed' => [["ho\nst" => 'x'], 'field "ho\\nst" is an unknown key: did you mean "host"?'];
        yield 'right-to-left override' => [["host\u{202E}" => 'x'], 'field "host\\u202e" is an unknown key: ' . self::ACCEPTED];
        yield 'unicode' => [['hôst' => 'x'], 'field "hôst" is an unknown key: did you mean "host"?'];
        yield 'nested NUL byte' => [['csrf' => ["\0" => 1]], 'field "csrf.\\u0000" is an unknown key: ' . self::ACCEPTED];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    #[DataProvider('unusualKeys')]
    public function testAnUnusualKeyIsRefusedAndShownEscaped(array $values, string $expectedEnd): void
    {
        try {
            ConfigKeys::read('example', $values, self::SPELLINGS);

            self::fail('An unusual key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame("Configuration section 'example' " . $expectedEnd, $exception->getMessage());
        }
    }

    public function testAHugeKeyIsRefusedWithoutASuggestion(): void
    {
        $key = str_repeat('h', 1_000_000);

        try {
            ConfigKeys::read('example', [$key => 'x'], self::SPELLINGS);

            self::fail('A huge key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'example' field \"" . str_repeat('h', 64) . "...\" (1000000 bytes) is an "
                . 'unknown key: ' . self::ACCEPTED,
                $exception->getMessage(),
            );
        }
    }

    public function testAShortKeyIsNotMatchedToAnUnrelatedShortSpelling(): void
    {
        self::assertSame(
            "Configuration section 'example' field 'hp' is an unknown key: " . self::ACCEPTED,
            self::refusalOf(['hp' => 'x']),
        );
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function twoSpellingsOfOneProperty(): iterable
    {
        yield 'camelCase and snake_case' => [['forceHttps' => false, 'force_https' => true], "'forceHttps' and 'force_https'"];
        yield 'declared null and a value' => [['forceHttps' => null, 'force_https' => true], "'forceHttps' and 'force_https'"];
        yield 'nested and flat' => [['csrf' => ['enabled' => false], 'csrfEnabled' => true], "'csrf.enabled' and 'csrfEnabled'"];
        yield 'two nested' => [['csrf' => ['enabled' => false, 'csrf_enabled' => true]], "'csrf.enabled' and 'csrf.csrf_enabled'"];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    #[DataProvider('twoSpellingsOfOneProperty')]
    public function testTwoSpellingsOfOnePropertyAreRefused(array $values, string $keys): void
    {
        try {
            ConfigKeys::read('example', $values, self::SPELLINGS);

            self::fail('Two spellings of one property were accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame("Configuration section 'example' sets both " . $keys . ': keep one.', $exception->getMessage());
            self::assertSame('example', $exception->section());
        }
    }

    public function testReadReturnsTheKeyAndValueOfEachWrittenProperty(): void
    {
        $keys = ConfigKeys::read('example', ['force_https' => true, 'csrf' => ['enabled' => '0'], 'host' => null], self::SPELLINGS);

        self::assertSame(['forceHttps', 'csrfEnabled', 'host'], $keys->properties());
        self::assertSame('force_https', $keys->key('forceHttps'));
        self::assertTrue($keys->value('forceHttps'));
        self::assertSame('csrf.enabled', $keys->key('csrfEnabled'));
        self::assertSame('0', $keys->value('csrfEnabled'));
        self::assertTrue($keys->has('host'));
        self::assertNull($keys->value('host'));
    }

    public function testAnAbsentPropertyReadsAsNullUnderItsPreferredSpelling(): void
    {
        $keys = ConfigKeys::read('example', ['csrf' => 'not a mapping'], self::SPELLINGS);

        self::assertFalse($keys->has('csrfEnabled'));
        self::assertNull($keys->value('csrfEnabled'));
        self::assertSame('csrf.enabled', $keys->key('csrfEnabled'));
        self::assertSame([], $keys->properties());
    }

    public function testBooleanReadsTheWrittenValueOrTheDefault(): void
    {
        $keys = ConfigKeys::read('example', ['force_https' => '0', 'csrf' => ['enabled' => 'yes']], self::SPELLINGS);

        self::assertFalse($keys->boolean('forceHttps', true));
        self::assertTrue($keys->boolean('csrfEnabled', false));
        self::assertTrue(ConfigKeys::read('example', [], self::SPELLINGS)->boolean('forceHttps', true));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string, string}>
     */
    public static function refusedBooleans(): iterable
    {
        yield 'declared null' => [['force_https' => null], 'forceHttps', "field 'force_https' has invalid value null"];
        yield 'empty string' => [['forceHttps' => ''], 'forceHttps', "field 'forceHttps' has invalid value \"\""];
        yield 'nested word' => [['csrf' => ['enabled' => 'maybe']], 'csrfEnabled', "field 'csrf.enabled' has invalid value \"maybe\""];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    #[DataProvider('refusedBooleans')]
    public function testBooleanRefusesAValueThatIsNotABooleanUnderTheKeyAsWritten(
        array $values,
        string $property,
        string $refusal,
    ): void {
        try {
            ConfigKeys::read('example', $values, self::SPELLINGS)->boolean($property, true);

            self::fail('A value that is not a boolean was read as one.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'example' " . $refusal . ': is not a boolean; use true/false, 1/0, on/off or yes/no.',
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private static function refusalOf(array $values): string
    {
        try {
            ConfigKeys::read('example', $values, self::SPELLINGS);
        } catch (ConfigurationException $exception) {
            return $exception->getMessage();
        }

        self::fail('The values were accepted.');
    }
}
