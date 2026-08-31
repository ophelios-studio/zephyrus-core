<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\ConfigurationException;

/**
 * A configuration value the framework cannot read must stop the boot, not
 * resolve to the unsafe answer.
 *
 * getBool() called filter_var() WITHOUT FILTER_NULL_ON_FAILURE, so every value
 * the filter did not recognise became false AND the caller's default was
 * thrown away on the way past. The section that most needed this was the one
 * most likely to be typed by hand:
 *
 *   requireMfa: enabled  ->  getBool('requireMfa', default: true) === false
 *   requireMfa: oui      ->  false
 *   requireMfa: vrai     ->  false
 *   maxAttempts: unlimited -> getInt('maxAttempts', 5) === 0
 *
 * A protection an operator explicitly wrote down turned itself off, silently,
 * while the config file still read as if it were on. The neighbouring parsers
 * (SessionConfig, SecurityConfig) use a plain (bool) cast and fail CLOSED, so
 * the subsystem carried two boolean readers with opposite failure directions,
 * and the one advertised to consumers was the one that failed open.
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
     * The exact reproduction: the caller asked for true, the file said
     * something unrecognised, and the answer used to be false.
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
     * An array in a string slot used to become the literal 'Array' plus a PHP
     * warning. 'Array' is a value no configuration ever meant, and it is one a
     * caller can go on to use as a hostname or a path.
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
     * The refusal has to be findable: it names the section class, the key and
     * why the value was rejected.
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
}
