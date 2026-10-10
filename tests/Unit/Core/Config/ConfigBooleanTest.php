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
}
