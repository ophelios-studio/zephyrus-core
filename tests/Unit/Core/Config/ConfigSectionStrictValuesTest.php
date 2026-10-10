<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\ConfigurationException;

/**
 * A configuration value the framework cannot read must stop the boot, not resolve to the unsafe answer.
 *
 * An unrecognised boolean must throw rather than become false or silently drop the caller's default.
 */
final class ConfigSectionStrictValuesTest extends TestCase
{
    /**
     * @param array<string, mixed> $values
     */
    private function section(array $values): ConfigSection
    {
        return new class($values) extends ConfigSection {
        };
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unreadableBooleans(): array
    {
        return [
            'a word that means on' => ['enabled'],
            'French for yes' => ['oui'],
            'French for true' => ['vrai'],
            'a number that is not 0 or 1' => ['2'],
            'an empty-ish word' => ['nope'],
            'an empty string' => [''],
            'a whitespace-only string' => ["  \t"],
            'a NUL byte' => ["\0"],
            'an array' => [['a', 'b']],
        ];
    }

    #[DataProvider('unreadableBooleans')]
    public function testAnUnreadableBooleanThrowsInsteadOfSilentlyBecomingFalse(mixed $value): void
    {
        $section = $this->section(['requireMfa' => $value]);

        $this->expectException(ConfigurationException::class);

        $section->getBool('requireMfa', true);
    }

    /**
     * An unrecognised value must throw even when the default is true, never resolve to a value.
     */
    public function testTheCallerDefaultIsNeverSilentlyDiscarded(): void
    {
        $section = $this->section(['requireMfa' => 'enabled']);

        try {
            $result = $section->getBool('requireMfa', true);
        } catch (ConfigurationException) {
            $this->addToAssertionCount(1);
            return;
        }

        self::fail(sprintf(
            'getBool() resolved an unreadable value to %s instead of refusing it.',
            var_export($result, true),
        ));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function readableBooleans(): array
    {
        return [
            'true' => [true, true],
            'false' => [false, false],
            'string 1' => ['1', true],
            'string 0' => ['0', false],
            'the word true' => ['true', true],
            'the word false' => ['false', false],
            'on' => ['on', true],
            'off' => ['off', false],
            'yes' => ['yes', true],
            'no' => ['no', false],
        ];
    }

    #[DataProvider('readableBooleans')]
    public function testEveryValueThatWasAlreadyReadableStaysReadable(mixed $value, bool $expected): void
    {
        self::assertSame($expected, $this->section(['flag' => $value])->getBool('flag'));
    }

    public function testAMissingBooleanStillTakesTheCallerDefault(): void
    {
        self::assertTrue($this->section([])->getBool('missing', true));
        self::assertFalse($this->section([])->getBool('missing', false));
    }

    public function testAWordInAnIntegerSlotThrowsInsteadOfBecomingZero(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is not an integer');

        $this->section(['maxAttempts' => 'unlimited'])->getInt('maxAttempts', 5);
    }

    public function testAnArrayInAnIntegerSlotThrows(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->section(['maxAttempts' => [5]])->getInt('maxAttempts', 5);
    }

    public function testIntegersAndNumericStringsAreStillRead(): void
    {
        self::assertSame(8080, $this->section(['port' => '8080'])->getInt('port'));
        self::assertSame(8080, $this->section(['port' => 8080])->getInt('port'));
        self::assertSame(-1, $this->section(['port' => ' -1 '])->getInt('port'));
        self::assertSame(99, $this->section([])->getInt('missing', 99));
    }

    public function testAWordInAFloatSlotThrows(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is not a number');

        $this->section(['rate' => 'fast'])->getFloat('rate', 1.5);
    }

    public function testNumbersAreStillReadAsFloats(): void
    {
        self::assertSame(3.14, $this->section(['rate' => '3.14'])->getFloat('rate'));
        self::assertSame(2.0, $this->section(['rate' => 2])->getFloat('rate'));
        self::assertSame(1.5, $this->section([])->getFloat('missing', 1.5));
    }

    /**
     * An array in a scalar slot is rejected: the literal 'Array' must never reach a caller.
     */
    public function testAnArrayInAStringSlotThrowsInsteadOfBecomingTheWordArray(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is not representable as a string');

        $this->section(['key' => ['a', 'b']])->getString('key', 'x');
    }

    public function testScalarsAreStillCastToStrings(): void
    {
        self::assertSame('Test', $this->section(['name' => 'Test'])->getString('name'));
        self::assertSame('42', $this->section(['count' => 42])->getString('count'));
        self::assertSame('default', $this->section([])->getString('missing', 'default'));
    }

    /**
     * The refusal names the section class, the key and why the value was rejected.
     */
    public function testTheRefusalNamesTheSectionAndTheKey(): void
    {
        try {
            $this->section(['requireMfa' => 'enabled'])->getBool('requireMfa', true);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('requireMfa', $exception->getMessage());
            self::assertStringContainsString('enabled', $exception->getMessage());
        }
    }

    public function testTheRefusalNamesTheSectionByItsClass(): void
    {
        $section = $this->section(['requireMfa' => 'enabled']);

        try {
            $section->getBool('requireMfa', true);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $exception) {
            self::assertSame($section::class, $exception->section());
            self::assertSame('requireMfa', $exception->field());
        }
    }
}
