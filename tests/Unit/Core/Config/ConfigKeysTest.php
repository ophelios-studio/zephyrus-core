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
        ConfigKeys::assertKnown('example', [
            'force_https' => true,
            'csrf' => ['csrf_enabled' => true],
            'password' => 'secret',
            'host' => null,
        ], self::SPELLINGS);

        $this->addToAssertionCount(1);
    }

    public function testAMisspelledKeyIsRefusedWithTheClosestSpelling(): void
    {
        try {
            ConfigKeys::assertKnown('example', ['forceHtps' => true], self::SPELLINGS);

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
            ConfigKeys::assertKnown('example', [$written => true], self::SPELLINGS);

            self::fail('A loose spelling was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringEndsWith('did you mean "' . $suggested . '"?', $exception->getMessage());
        }
    }

    public function testAKeyNearNoSpellingListsTheAcceptedKeys(): void
    {
        try {
            ConfigKeys::assertKnown('example', ['timeout' => 30], self::SPELLINGS);

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
            ConfigKeys::assertKnown('example', ['csrf' => ['enabeld' => false]], self::SPELLINGS);

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
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'csfr' is an unknown key: did you mean \"csrf\"?");

        ConfigKeys::assertKnown('example', ['csfr' => ['enabled' => false]], self::SPELLINGS);
    }

    public function testADottedTopLevelKeyIsNotReadAsANestedOne(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'csrf.enabled' is an unknown key: did you mean \"csrfEnabled\"?");

        ConfigKeys::assertKnown('example', ['csrf.enabled' => false], self::SPELLINGS);
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
        ConfigKeys::assertKnown('example', ['csrf' => $value], self::SPELLINGS);

        $this->addToAssertionCount(1);
    }

    public function testTheValueOfAnUnknownKeyStaysOutOfTheMessage(): void
    {
        try {
            ConfigKeys::assertKnown('example', ['pasword' => 'hunter2-secret'], self::SPELLINGS);

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
            ConfigKeys::assertKnown('example', $values, self::SPELLINGS);

            self::fail('An unusual key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame("Configuration section 'example' " . $expectedEnd, $exception->getMessage());
        }
    }

    public function testAHugeKeyIsRefusedWithoutASuggestion(): void
    {
        $key = str_repeat('h', 1_000_000);

        try {
            ConfigKeys::assertKnown('example', [$key => 'x'], self::SPELLINGS);

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
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'hp' is an unknown key: " . self::ACCEPTED);

        ConfigKeys::assertKnown('example', ['hp' => 'x'], self::SPELLINGS);
    }
}
