<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Zephyrus\Validation\Rules;

final class RulesTest extends TestCase
{
    // ---- required ----

    public function testRequiredPassesNonEmpty(): void
    {
        $rule = Rules::required();
        self::assertTrue($rule->test('hello'));
        self::assertTrue($rule->test(0));
        self::assertTrue($rule->test(['a']));
    }

    public function testRequiredFailsOnNull(): void
    {
        self::assertFalse(Rules::required()->test(null));
    }

    public function testRequiredFailsOnEmptyString(): void
    {
        self::assertFalse(Rules::required()->test(''));
    }

    public function testRequiredFailsOnEmptyArray(): void
    {
        self::assertFalse(Rules::required()->test([]));
    }

    public function testRequiredCustomMessage(): void
    {
        self::assertSame('Cannot be empty.', Rules::required('Cannot be empty.')->errorMessage());
    }

    // ---- minLength / maxLength ----

    public function testMinLengthPasses(): void
    {
        self::assertTrue(Rules::minLength(3)->test('abc'));
        self::assertTrue(Rules::minLength(3)->test('abcd'));
    }

    public function testMinLengthFails(): void
    {
        self::assertFalse(Rules::minLength(3)->test('ab'));
        self::assertFalse(Rules::minLength(3)->test(''));
    }

    public function testMinLengthFailsOnNonString(): void
    {
        self::assertFalse(Rules::minLength(1)->test(42));
    }

    public function testMaxLengthPasses(): void
    {
        self::assertTrue(Rules::maxLength(5)->test('hello'));
        self::assertTrue(Rules::maxLength(5)->test('hi'));
    }

    public function testMaxLengthFails(): void
    {
        self::assertFalse(Rules::maxLength(5)->test('toolong'));
    }

    public function testMinLengthDefaultMessage(): void
    {
        self::assertSame('Must be at least 5 characters.', Rules::minLength(5)->errorMessage());
    }

    public function testMaxLengthDefaultMessage(): void
    {
        self::assertSame('Must be at most 10 characters.', Rules::maxLength(10)->errorMessage());
    }

    // ---- email ----

    public function testEmailPasses(): void
    {
        self::assertTrue(Rules::email()->test('user@example.com'));
        self::assertTrue(Rules::email()->test('a+b@sub.domain.org'));
    }

    public function testEmailFails(): void
    {
        self::assertFalse(Rules::email()->test('not-an-email'));
        self::assertFalse(Rules::email()->test('@nodomain'));
        self::assertFalse(Rules::email()->test(null));
    }

    // ---- integer ----

    public function testIntegerPasses(): void
    {
        self::assertTrue(Rules::integer()->test('42'));
        self::assertTrue(Rules::integer()->test(0));
        self::assertTrue(Rules::integer()->test(-5));
    }

    public function testIntegerFails(): void
    {
        self::assertFalse(Rules::integer()->test('3.14'));
        self::assertFalse(Rules::integer()->test('abc'));
    }

    // ---- numeric ----

    public function testNumericPasses(): void
    {
        self::assertTrue(Rules::numeric()->test('3.14'));
        self::assertTrue(Rules::numeric()->test('100'));
        self::assertTrue(Rules::numeric()->test(0));
    }

    public function testNumericFails(): void
    {
        self::assertFalse(Rules::numeric()->test('abc'));
        self::assertFalse(Rules::numeric()->test(null));
    }

    // ---- min / max / between ----

    public function testMinPasses(): void
    {
        self::assertTrue(Rules::min(10)->test(10));
        self::assertTrue(Rules::min(10)->test(100));
    }

    public function testMinFails(): void
    {
        self::assertFalse(Rules::min(10)->test(9));
        self::assertFalse(Rules::min(10)->test('abc'));
    }

    public function testMaxPasses(): void
    {
        self::assertTrue(Rules::max(100)->test(50));
        self::assertTrue(Rules::max(100)->test(100));
    }

    public function testMaxFails(): void
    {
        self::assertFalse(Rules::max(100)->test(101));
    }

    public function testBetweenPasses(): void
    {
        self::assertTrue(Rules::between(1, 10)->test(5));
        self::assertTrue(Rules::between(1, 10)->test(1));
        self::assertTrue(Rules::between(1, 10)->test(10));
    }

    public function testBetweenFails(): void
    {
        self::assertFalse(Rules::between(1, 10)->test(0));
        self::assertFalse(Rules::between(1, 10)->test(11));
    }

    public function testBetweenDefaultMessage(): void
    {
        self::assertSame('Must be between 1 and 10.', Rules::between(1, 10)->errorMessage());
    }

    // ---- regex ----

    public function testRegexPasses(): void
    {
        self::assertTrue(Rules::regex('/^\d{3}$/', 'Must be 3 digits.')->test('123'));
    }

    public function testRegexFails(): void
    {
        self::assertFalse(Rules::regex('/^\d{3}$/', 'Must be 3 digits.')->test('12'));
        self::assertFalse(Rules::regex('/^\d{3}$/', 'Must be 3 digits.')->test(null));
    }

    // ---- in ----

    public function testInPasses(): void
    {
        self::assertTrue(Rules::in(['a', 'b', 'c'])->test('b'));
    }

    public function testInFails(): void
    {
        self::assertFalse(Rules::in(['a', 'b', 'c'])->test('d'));
        self::assertFalse(Rules::in([1, 2, 3])->test('1')); // strict type check
    }

    public function testInDefaultMessage(): void
    {
        self::assertSame('Must be one of: a, b, c.', Rules::in(['a', 'b', 'c'])->errorMessage());
    }

    // ---- url ----

    public function testUrlPasses(): void
    {
        self::assertTrue(Rules::url()->test('https://example.com'));
        self::assertTrue(Rules::url()->test('http://localhost:8080/path?q=1'));
    }

    public function testUrlFails(): void
    {
        self::assertFalse(Rules::url()->test('not-a-url'));
        self::assertFalse(Rules::url()->test(null));
    }

    // ---- notBlank ----

    public function testNotBlankPasses(): void
    {
        self::assertTrue(Rules::notBlank()->test('hello'));
        self::assertTrue(Rules::notBlank()->test(' x '));
    }

    public function testNotBlankFails(): void
    {
        self::assertFalse(Rules::notBlank()->test(''));
        self::assertFalse(Rules::notBlank()->test('   '));
        self::assertFalse(Rules::notBlank()->test(null));
        self::assertFalse(Rules::notBlank()->test(42));
    }
}
