<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigBoolean;
use Zephyrus\Core\Config\ConfigurationException;

final class ConfigBooleanTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function readableValues(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'int 1' => [1, true],
            'int 0' => [0, false],
            'string 1' => ['1', true],
            'string 0' => ['0', false],
            'lowercase true' => ['true', true],
            'uppercase TRUE' => ['TRUE', true],
            'mixed case On' => ['On', true],
            'off' => ['off', false],
            'yes' => ['yes', true],
            'NO' => ['NO', false],
            'surrounding spaces' => [' yes ', true],
            'surrounding tabs and newlines' => ["\tfalse\n", false],
        ];
    }

    #[DataProvider('readableValues')]
    public function testParsesEveryAcceptedSpelling(mixed $value, bool $expected): void
    {
        self::assertSame($expected, ConfigBoolean::parse('security', 'flag', $value));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function refusedValues(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ["   \t"],
            'NUL byte' => ["\0"],
            'NUL byte between words' => ["on\0off"],
            'null' => [null],
            'unknown word' => ['maybe'],
            'French for yes' => ['oui'],
            'accented French for true' => ['vrai'],
            'int 2' => [2],
            'negative int' => [-1],
            'string 2' => ['2'],
            'float 1.0' => [1.0],
            'array' => [[]],
            'non-empty array' => [['true']],
            'object' => [new \stdClass()],
            'word with a trailing dot' => ['true.'],
            'very long string' => [str_repeat('x', 100_000)],
        ];
    }

    #[DataProvider('refusedValues')]
    public function testRefusesEverythingItCannotReadAsABoolean(mixed $value): void
    {
        $this->expectException(ConfigurationException::class);

        ConfigBoolean::parse('security', 'flag', $value);
    }

    public function testARefusalCutInsideAMultibyteCharacterStillShowsValidUtf8(): void
    {
        foreach (['x' . str_repeat('é', 30), str_repeat('é', 200)] as $value) {
            try {
                ConfigBoolean::parse('security', 'flag', $value);
                self::fail('A value that is not a boolean must be refused.');
            } catch (ConfigurationException $exception) {
                self::assertTrue(mb_check_encoding($exception->getMessage(), 'UTF-8'));
            }
        }
    }

    public function testALongRefusedValueIsShownWithItsByteCount(): void
    {
        try {
            ConfigBoolean::parse('security', 'flag', str_repeat('y', 100));
            self::fail('A value that is not a boolean must be refused.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'security' field 'flag' has invalid value \"" . str_repeat('y', 64)
                . "...\" (100 bytes): is not a boolean; use true/false, 1/0, on/off or yes/no.",
                $exception->getMessage(),
            );
        }
    }

    public function testTheRefusalNamesTheSectionAndTheKey(): void
    {
        try {
            ConfigBoolean::parse('csrf', 'csrf.enabled', '');
            self::fail('An empty value must be refused.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("section 'csrf'", $exception->getMessage());
            self::assertStringContainsString("field 'csrf.enabled'", $exception->getMessage());
        }
    }

    public function testTheFirstSpellingThatIsSetDecidesTheValue(): void
    {
        self::assertFalse(ConfigBoolean::firstSet('csrf', ['enabled' => false, 'csrf_enabled' => true], ['enabled', 'csrf_enabled'], true));

        self::assertTrue(ConfigBoolean::firstSet('csrf', ['csrf_enabled' => true], ['enabled', 'csrf_enabled'], false));
    }

    public function testAStringZeroIsASetValueNotAnAbsentOne(): void
    {
        self::assertFalse(ConfigBoolean::firstSet('session', ['httpOnly' => '0'], ['httpOnly', 'http_only'], true));
    }

    public function testTheDefaultAppliesOnlyWhenNoSpellingIsSet(): void
    {
        self::assertTrue(ConfigBoolean::firstSet('session', [], ['httpOnly', 'http_only'], true));
    }

    public function testADeclaredNullIsRefusedRatherThanReadAsTheDefault(): void
    {
        try {
            ConfigBoolean::firstSet('session', ['http_only' => null], ['httpOnly', 'http_only'], true);

            self::fail('A declared null was read as the default.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'session' field 'http_only' has invalid value null: is not a boolean; "
                . 'use true/false, 1/0, on/off or yes/no.',
                $exception->getMessage(),
            );
        }
    }

    public function testAnEmptySetValueIsRefusedInsteadOfUsingTheDefault(): void
    {
        $this->expectException(ConfigurationException::class);

        ConfigBoolean::firstSet('session', ['httpOnly' => ''], ['httpOnly', 'http_only'], true);
    }

    public function testTheRefusalNamesTheSpellingThatWasSet(): void
    {
        try {
            ConfigBoolean::firstSet('session', ['http_only' => 'maybe'], ['httpOnly', 'http_only'], true);
            self::fail('A value that is not a boolean must be refused.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("section 'session'", $exception->getMessage());
            self::assertStringContainsString("field 'http_only'", $exception->getMessage());
        }
    }
}
