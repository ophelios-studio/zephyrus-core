<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\TestCase;
use Zephyrus\Validation\Rules;

final class RulesTest extends TestCase
{
    public function testRulesConstructorIsPrivateUtilityClassContract(): void
    {
        $reflection = new \ReflectionClass(Rules::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());

        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->setAccessible(true);
        $constructor->invoke($instance);

        self::assertInstanceOf(Rules::class, $instance);
    }

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

    public function testIntegerStringPassesAndFails(): void
    {
        self::assertTrue(Rules::integerString()->test('123'));
        self::assertTrue(Rules::integerString()->test('-99'));
        self::assertFalse(Rules::integerString()->test('12.3'));
        self::assertFalse(Rules::integerString()->test(123));
        self::assertFalse(Rules::integerString()->test(null));
    }

    public function testIntegerStringDefaultMessage(): void
    {
        self::assertSame('Must be an integer string.', Rules::integerString()->errorMessage());
    }

    public function testDecimalStringPassesAndFails(): void
    {
        self::assertTrue(Rules::decimalString(2)->test('123'));
        self::assertTrue(Rules::decimalString(2)->test('123.4'));
        self::assertTrue(Rules::decimalString(2)->test('-123.45'));
        self::assertFalse(Rules::decimalString(2)->test('123.456'));
        self::assertFalse(Rules::decimalString(2)->test('abc'));
        self::assertFalse(Rules::decimalString(2)->test(123.45));
    }

    public function testDecimalStringWithZeroScaleAcceptsIntegersOnly(): void
    {
        self::assertTrue(Rules::decimalString(0)->test('123'));
        self::assertTrue(Rules::decimalString(0)->test('-99'));
        self::assertFalse(Rules::decimalString(0)->test('123.4'));
        self::assertFalse(Rules::decimalString(0)->test('-0.1'));
    }

    public function testDecimalStringDefaultMessage(): void
    {
        self::assertSame(
            'Must be a decimal string with up to 2 decimal places.',
            Rules::decimalString(2)->errorMessage(),
        );
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

    public function testGreaterThanPassesAndFails(): void
    {
        self::assertTrue(Rules::greaterThan(10)->test(11));
        self::assertFalse(Rules::greaterThan(10)->test(10));
        self::assertFalse(Rules::greaterThan(10)->test('abc'));
    }

    public function testGreaterThanDefaultMessage(): void
    {
        self::assertSame('Must be greater than 10.', Rules::greaterThan(10)->errorMessage());
    }

    public function testLessThanPassesAndFails(): void
    {
        self::assertTrue(Rules::lessThan(10)->test(9));
        self::assertFalse(Rules::lessThan(10)->test(10));
        self::assertFalse(Rules::lessThan(10)->test('abc'));
    }

    public function testLessThanDefaultMessage(): void
    {
        self::assertSame('Must be less than 10.', Rules::lessThan(10)->errorMessage());
    }

    public function testBetweenExclusivePassesAndFails(): void
    {
        self::assertTrue(Rules::betweenExclusive(1, 10)->test(5));
        self::assertFalse(Rules::betweenExclusive(1, 10)->test(1));
        self::assertFalse(Rules::betweenExclusive(1, 10)->test(10));
        self::assertFalse(Rules::betweenExclusive(1, 10)->test(0));
    }

    public function testBetweenExclusiveDefaultMessage(): void
    {
        self::assertSame(
            'Must be strictly between 1 and 10.',
            Rules::betweenExclusive(1, 10)->errorMessage(),
        );
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

    // ---- ascii / alphaNumeric / startsWith / endsWith / contains ----

    public function testAsciiPasses(): void
    {
        self::assertTrue(Rules::ascii()->test('hello123'));
        self::assertTrue(Rules::ascii()->test('symbols !@#'));
    }

    public function testAsciiFails(): void
    {
        self::assertFalse(Rules::ascii()->test('café'));
        self::assertFalse(Rules::ascii()->test('こんにちは'));
        self::assertFalse(Rules::ascii()->test(null));
    }

    public function testAsciiDefaultMessage(): void
    {
        self::assertSame('Must contain only ASCII characters.', Rules::ascii()->errorMessage());
    }

    public function testAlphaNumericPasses(): void
    {
        self::assertTrue(Rules::alphaNumeric()->test('abc123'));
        self::assertTrue(Rules::alphaNumeric()->test('A1B2C3'));
    }

    public function testAlphaNumericFails(): void
    {
        self::assertFalse(Rules::alphaNumeric()->test('abc-123'));
        self::assertFalse(Rules::alphaNumeric()->test('abc 123'));
        self::assertFalse(Rules::alphaNumeric()->test(''));
        self::assertFalse(Rules::alphaNumeric()->test(null));
    }

    public function testAlphaNumericDefaultMessage(): void
    {
        self::assertSame('Must contain only letters and numbers.', Rules::alphaNumeric()->errorMessage());
    }

    public function testStartsWithPassesAndFails(): void
    {
        self::assertTrue(Rules::startsWith('pre')->test('prefix'));
        self::assertFalse(Rules::startsWith('pre')->test('suffix'));
        self::assertFalse(Rules::startsWith('pre')->test(null));
    }

    public function testStartsWithDefaultMessage(): void
    {
        self::assertSame("Must start with 'api_'.", Rules::startsWith('api_')->errorMessage());
    }

    public function testEndsWithPassesAndFails(): void
    {
        self::assertTrue(Rules::endsWith('.com')->test('example.com'));
        self::assertFalse(Rules::endsWith('.com')->test('example.org'));
        self::assertFalse(Rules::endsWith('.com')->test(null));
    }

    public function testEndsWithDefaultMessage(): void
    {
        self::assertSame("Must end with '.json'.", Rules::endsWith('.json')->errorMessage());
    }

    public function testContainsPassesAndFails(): void
    {
        self::assertTrue(Rules::contains('needle')->test('haystack needle here'));
        self::assertFalse(Rules::contains('needle')->test('haystack only'));
        self::assertFalse(Rules::contains('needle')->test(null));
    }

    public function testContainsDefaultMessage(): void
    {
        self::assertSame("Must contain 'token'.", Rules::contains('token')->errorMessage());
    }

    // ---- lowercase / uppercase / noWhitespace ----

    public function testLowercasePassesAndFails(): void
    {
        self::assertTrue(Rules::lowercase()->test('hello'));
        self::assertTrue(Rules::lowercase()->test('hello123'));
        self::assertFalse(Rules::lowercase()->test('Hello'));
        self::assertFalse(Rules::lowercase()->test(null));
    }

    public function testLowercaseDefaultMessage(): void
    {
        self::assertSame('Must be lowercase.', Rules::lowercase()->errorMessage());
    }

    public function testUppercasePassesAndFails(): void
    {
        self::assertTrue(Rules::uppercase()->test('HELLO'));
        self::assertTrue(Rules::uppercase()->test('ABC123'));
        self::assertFalse(Rules::uppercase()->test('Hello'));
        self::assertFalse(Rules::uppercase()->test(null));
    }

    public function testUppercaseDefaultMessage(): void
    {
        self::assertSame('Must be uppercase.', Rules::uppercase()->errorMessage());
    }

    public function testNoWhitespacePassesAndFails(): void
    {
        self::assertTrue(Rules::noWhitespace()->test('token123'));
        self::assertFalse(Rules::noWhitespace()->test('token 123'));
        self::assertFalse(Rules::noWhitespace()->test("token\n123"));
        self::assertFalse(Rules::noWhitespace()->test(''));
        self::assertFalse(Rules::noWhitespace()->test(null));
    }

    public function testNoWhitespaceDefaultMessage(): void
    {
        self::assertSame('Must not contain whitespace.', Rules::noWhitespace()->errorMessage());
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

    // ---- httpUrl ----

    public function testHttpUrlPasses(): void
    {
        self::assertTrue(Rules::httpUrl()->test('https://example.com'));
        self::assertTrue(Rules::httpUrl()->test('http://localhost:8080/path?q=1'));
    }

    public function testHttpUrlFails(): void
    {
        self::assertFalse(Rules::httpUrl()->test('ftp://example.com/file.txt'));
        self::assertFalse(Rules::httpUrl()->test('mailto:test@example.com'));
        self::assertFalse(Rules::httpUrl()->test('not-a-url'));
        self::assertFalse(Rules::httpUrl()->test(null));
    }

    public function testHttpUrlDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP/HTTPS URL.', Rules::httpUrl()->errorMessage());
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

    // ---- boolean ----

    public function testBooleanPassesNativeBool(): void
    {
        self::assertTrue(Rules::boolean()->test(true));
        self::assertTrue(Rules::boolean()->test(false));
    }

    public function testBooleanPassesIntegerZeroAndOne(): void
    {
        self::assertTrue(Rules::boolean()->test(1));
        self::assertTrue(Rules::boolean()->test(0));
    }

    public function testBooleanPassesStringVariants(): void
    {
        self::assertTrue(Rules::boolean()->test('1'));
        self::assertTrue(Rules::boolean()->test('0'));
        self::assertTrue(Rules::boolean()->test('true'));
        self::assertTrue(Rules::boolean()->test('false'));
        self::assertTrue(Rules::boolean()->test('TRUE'));
        self::assertTrue(Rules::boolean()->test('FALSE'));
    }

    public function testBooleanFails(): void
    {
        self::assertFalse(Rules::boolean()->test('yes'));
        self::assertFalse(Rules::boolean()->test('no'));
        self::assertFalse(Rules::boolean()->test(2));
        self::assertFalse(Rules::boolean()->test(null));
        self::assertFalse(Rules::boolean()->test([]));
    }

    public function testBooleanDefaultMessage(): void
    {
        self::assertSame('Must be a boolean value.', Rules::boolean()->errorMessage());
    }

    // ---- uuid ----

    public function testUuidPasses(): void
    {
        self::assertTrue(Rules::uuid()->test('550e8400-e29b-41d4-a716-446655440000'));
        self::assertTrue(Rules::uuid()->test('550E8400-E29B-41D4-A716-446655440000')); // uppercase
        self::assertTrue(Rules::uuid()->test('00000000-0000-0000-0000-000000000000'));
    }

    public function testUuidFails(): void
    {
        self::assertFalse(Rules::uuid()->test('not-a-uuid'));
        self::assertFalse(Rules::uuid()->test('550e8400-e29b-41d4-a716-44665544000'));  // too short
        self::assertFalse(Rules::uuid()->test('550e8400-e29b-41d4-a716-4466554400000')); // too long
        self::assertFalse(Rules::uuid()->test(null));
        self::assertFalse(Rules::uuid()->test(42));
    }

    public function testUuidDefaultMessage(): void
    {
        self::assertSame('Must be a valid UUID.', Rules::uuid()->errorMessage());
    }

    // ---- date ----

    public function testDatePassesDefaultFormat(): void
    {
        self::assertTrue(Rules::date()->test('2024-02-29')); // leap year
        self::assertTrue(Rules::date()->test('2026-01-01'));
    }

    public function testDateFailsInvalidDate(): void
    {
        self::assertFalse(Rules::date()->test('2023-02-29')); // not a leap year
        self::assertFalse(Rules::date()->test('2026-13-01')); // month 13
        self::assertFalse(Rules::date()->test('not-a-date'));
        self::assertFalse(Rules::date()->test(null));
        self::assertFalse(Rules::date()->test(20240101));
    }

    public function testDatePassesCustomFormat(): void
    {
        self::assertTrue(Rules::date('d/m/Y')->test('29/02/2024'));
        self::assertTrue(Rules::date('Y')->test('2026'));
    }

    public function testDateFailsWrongFormat(): void
    {
        self::assertFalse(Rules::date('Y-m-d')->test('01/01/2026'));
    }

    public function testDateDefaultMessage(): void
    {
        self::assertSame('Must be a valid date in Y-m-d format.', Rules::date()->errorMessage());
    }

    public function testDateCustomFormatMessage(): void
    {
        self::assertSame('Must be a valid date in d/m/Y format.', Rules::date('d/m/Y')->errorMessage());
    }

    // ---- dateTime ----

    public function testDateTimePassesDefaultFormat(): void
    {
        self::assertTrue(Rules::dateTime()->test('2026-02-23 17:45:00'));
    }

    public function testDateTimeFailsInvalidValues(): void
    {
        self::assertFalse(Rules::dateTime()->test('2026-02-23'));
        self::assertFalse(Rules::dateTime()->test('2026-13-23 10:00:00'));
        self::assertFalse(Rules::dateTime()->test(null));
    }

    public function testDateTimeSupportsCustomFormat(): void
    {
        self::assertTrue(Rules::dateTime('d/m/Y H:i')->test('23/02/2026 17:45'));
        self::assertFalse(Rules::dateTime('d/m/Y H:i')->test('2026-02-23 17:45'));
    }

    public function testDateTimeDefaultMessage(): void
    {
        self::assertSame(
            'Must be a valid datetime in Y-m-d H:i:s format.',
            Rules::dateTime()->errorMessage(),
        );
    }

    // ---- rfc3339DateTime ----

    public function testRfc3339DateTimePassesValidValues(): void
    {
        self::assertTrue(Rules::rfc3339DateTime()->test('2026-03-08T00:39:00+00:00'));
        self::assertTrue(Rules::rfc3339DateTime()->test('2026-03-07T19:39:00-05:00'));
    }

    public function testRfc3339DateTimeFailsInvalidValues(): void
    {
        self::assertFalse(Rules::rfc3339DateTime()->test('2026-03-08 00:39:00'));
        self::assertFalse(Rules::rfc3339DateTime()->test('2026-13-08T00:39:00+00:00'));
        self::assertFalse(Rules::rfc3339DateTime()->test(null));
    }

    public function testRfc3339DateTimeDefaultMessage(): void
    {
        self::assertSame('Must be a valid RFC 3339 datetime.', Rules::rfc3339DateTime()->errorMessage());
    }

    // ---- timezone ----

    public function testTimezonePassesKnownIdentifiers(): void
    {
        self::assertTrue(Rules::timezone()->test('America/Toronto'));
        self::assertTrue(Rules::timezone()->test('UTC'));
    }

    public function testTimezoneFailsInvalidValues(): void
    {
        self::assertFalse(Rules::timezone()->test('Mars/OlympusMons'));
        self::assertFalse(Rules::timezone()->test(''));
        self::assertFalse(Rules::timezone()->test(null));
    }

    public function testTimezoneDefaultMessage(): void
    {
        self::assertSame('Must be a valid timezone identifier.', Rules::timezone()->errorMessage());
    }

    // ---- time24 / phoneE164 / hexColor / macAddress / cronExpression / postalCode ----

    public function testTime24PassesAndFails(): void
    {
        self::assertTrue(Rules::time24()->test('00:00'));
        self::assertTrue(Rules::time24()->test('23:59'));
        self::assertFalse(Rules::time24()->test('24:00'));
        self::assertFalse(Rules::time24()->test('9:30'));
        self::assertFalse(Rules::time24()->test(null));
    }

    public function testTime24DefaultMessage(): void
    {
        self::assertSame('Must be a valid 24-hour time (HH:MM).', Rules::time24()->errorMessage());
    }

    public function testPhoneE164PassesAndFails(): void
    {
        self::assertTrue(Rules::phoneE164()->test('+14165551234'));
        self::assertTrue(Rules::phoneE164()->test('+442071838750'));
        self::assertFalse(Rules::phoneE164()->test('14165551234'));
        self::assertFalse(Rules::phoneE164()->test('+0123456789'));
        self::assertFalse(Rules::phoneE164()->test('+1(416)555-1234'));
        self::assertFalse(Rules::phoneE164()->test(null));
    }

    public function testPhoneE164DefaultMessage(): void
    {
        self::assertSame('Must be a valid E.164 phone number.', Rules::phoneE164()->errorMessage());
    }

    public function testHexColorPassesAndFails(): void
    {
        self::assertTrue(Rules::hexColor()->test('#fff'));
        self::assertTrue(Rules::hexColor()->test('#FFAA00'));
        self::assertFalse(Rules::hexColor()->test('FFAA00'));
        self::assertFalse(Rules::hexColor()->test('#FFFF'));
        self::assertFalse(Rules::hexColor()->test(null));
    }

    public function testHexColorDefaultMessageFromShapeRulesBlock(): void
    {
        self::assertSame('Must be a valid hex color.', Rules::hexColor()->errorMessage());
    }

    public function testMacAddressPassesAndFails(): void
    {
        self::assertTrue(Rules::macAddress()->test('00:1A:2B:3C:4D:5E'));
        self::assertTrue(Rules::macAddress()->test('00-1A-2B-3C-4D-5E'));
        self::assertFalse(Rules::macAddress()->test('001A2B3C4D5E'));
        self::assertFalse(Rules::macAddress()->test('00:1A:2B:3C:4D'));
        self::assertFalse(Rules::macAddress()->test(null));
    }

    public function testMacAddressDefaultMessageFromShapeRulesBlock(): void
    {
        self::assertSame('Must be a valid MAC address.', Rules::macAddress()->errorMessage());
    }

    public function testCronExpressionPassesAndFails(): void
    {
        self::assertTrue(Rules::cronExpression()->test('* * * * *'));
        self::assertTrue(Rules::cronExpression()->test('*/5 0 * * 1-5'));
        self::assertFalse(Rules::cronExpression()->test('* * * *'));
        self::assertFalse(Rules::cronExpression()->test('* * * * * *'));
        self::assertFalse(Rules::cronExpression()->test(null));
    }

    public function testCronExpressionDefaultMessage(): void
    {
        self::assertSame('Must be a valid cron expression.', Rules::cronExpression()->errorMessage());
    }

    public function testPostalCodePassesAndFails(): void
    {
        self::assertTrue(Rules::postalCode()->test('H2B 1X9'));
        self::assertTrue(Rules::postalCode()->test('90210'));
        self::assertTrue(Rules::postalCode()->test('SW1A-1AA'));
        self::assertFalse(Rules::postalCode()->test('A'));
        self::assertFalse(Rules::postalCode()->test(' 90210'));
        self::assertFalse(Rules::postalCode()->test('zip_code!'));
        self::assertFalse(Rules::postalCode()->test(null));
    }

    public function testPostalCodeDefaultMessage(): void
    {
        self::assertSame('Must be a valid postal code.', Rules::postalCode()->errorMessage());
    }

    // ---- countMin / countMax ----

    public function testCountMinPasses(): void
    {
        self::assertTrue(Rules::countMin(1)->test(['a']));
        self::assertTrue(Rules::countMin(3)->test([1, 2, 3]));
        self::assertTrue(Rules::countMin(0)->test([]));
    }

    public function testCountMinFails(): void
    {
        self::assertFalse(Rules::countMin(2)->test(['a']));
        self::assertFalse(Rules::countMin(1)->test([]));
        self::assertFalse(Rules::countMin(1)->test('not-array'));
        self::assertFalse(Rules::countMin(1)->test(null));
    }

    public function testCountMinDefaultMessage(): void
    {
        self::assertSame('Must have at least 2 item(s).', Rules::countMin(2)->errorMessage());
    }

    public function testCountMaxPasses(): void
    {
        self::assertTrue(Rules::countMax(3)->test([1, 2, 3]));
        self::assertTrue(Rules::countMax(3)->test([1, 2]));
        self::assertTrue(Rules::countMax(0)->test([]));
    }

    public function testCountMaxFails(): void
    {
        self::assertFalse(Rules::countMax(2)->test([1, 2, 3]));
        self::assertFalse(Rules::countMax(0)->test(['a']));
        self::assertFalse(Rules::countMax(5)->test('not-array'));
        self::assertFalse(Rules::countMax(5)->test(null));
    }

    public function testCountMaxDefaultMessage(): void
    {
        self::assertSame('Must have at most 5 item(s).', Rules::countMax(5)->errorMessage());
    }

    // ---- ip ----

    public function testIpPassesIPv4(): void
    {
        self::assertTrue(Rules::ip()->test('192.168.1.1'));
        self::assertTrue(Rules::ip()->test('127.0.0.1'));
        self::assertTrue(Rules::ip()->test('0.0.0.0'));
        self::assertTrue(Rules::ip()->test('255.255.255.255'));
    }

    public function testIpPassesIPv6(): void
    {
        self::assertTrue(Rules::ip()->test('::1'));
        self::assertTrue(Rules::ip()->test('2001:db8::1'));
        self::assertTrue(Rules::ip()->test('2001:0db8:0000:0000:0000:0000:0000:0001'));
    }

    public function testIpFails(): void
    {
        self::assertFalse(Rules::ip()->test('999.999.999.999'));
        self::assertFalse(Rules::ip()->test('not-an-ip'));
        self::assertFalse(Rules::ip()->test('192.168.1'));
        self::assertFalse(Rules::ip()->test(null));
        self::assertFalse(Rules::ip()->test(127));
    }

    public function testIpDefaultMessage(): void
    {
        self::assertSame('Must be a valid IP address.', Rules::ip()->errorMessage());
    }

    // ---- ipv4 ----

    public function testIpv4Passes(): void
    {
        self::assertTrue(Rules::ipv4()->test('192.168.1.1'));
        self::assertTrue(Rules::ipv4()->test('127.0.0.1'));
    }

    public function testIpv4Fails(): void
    {
        self::assertFalse(Rules::ipv4()->test('::1'));
        self::assertFalse(Rules::ipv4()->test('2001:db8::1'));
        self::assertFalse(Rules::ipv4()->test('999.999.999.999'));
        self::assertFalse(Rules::ipv4()->test(null));
    }

    public function testIpv4DefaultMessage(): void
    {
        self::assertSame('Must be a valid IPv4 address.', Rules::ipv4()->errorMessage());
    }

    // ---- ipv6 ----

    public function testIpv6Passes(): void
    {
        self::assertTrue(Rules::ipv6()->test('::1'));
        self::assertTrue(Rules::ipv6()->test('2001:db8::1'));
    }

    public function testIpv6Fails(): void
    {
        self::assertFalse(Rules::ipv6()->test('192.168.1.1'));
        self::assertFalse(Rules::ipv6()->test('not-an-ip'));
        self::assertFalse(Rules::ipv6()->test(null));
    }

    public function testIpv6DefaultMessage(): void
    {
        self::assertSame('Must be a valid IPv6 address.', Rules::ipv6()->errorMessage());
    }

    // ---- hostname ----

    public function testHostnamePassesValidHostnames(): void
    {
        self::assertTrue(Rules::hostname()->test('example.com'));
        self::assertTrue(Rules::hostname()->test('api.internal-service.local'));
        self::assertTrue(Rules::hostname()->test('xn--bcher-kva.example'));
    }

    public function testHostnameFailsInvalidHostnames(): void
    {
        self::assertFalse(Rules::hostname()->test(''));
        self::assertFalse(Rules::hostname()->test('.example.com'));
        self::assertFalse(Rules::hostname()->test('example.com.'));
        self::assertFalse(Rules::hostname()->test('-bad.example'));
        self::assertFalse(Rules::hostname()->test('bad-.example'));
        self::assertFalse(Rules::hostname()->test('exa_mple.com'));
        self::assertFalse(Rules::hostname()->test('exa mple.com'));
        self::assertFalse(Rules::hostname()->test(null));
    }

    public function testHostnameDefaultMessage(): void
    {
        self::assertSame('Must be a valid hostname.', Rules::hostname()->errorMessage());
    }

    // ---- cidr ----

    public function testCidrPassesIpv4AndIpv6Ranges(): void
    {
        self::assertTrue(Rules::cidr()->test('10.0.0.0/8'));
        self::assertTrue(Rules::cidr()->test('192.168.1.0/24'));
        self::assertTrue(Rules::cidr()->test('2001:db8::/32'));
        self::assertTrue(Rules::cidr()->test('::1/128'));
    }

    public function testCidrFailsInvalidRanges(): void
    {
        self::assertFalse(Rules::cidr()->test('10.0.0.0'));
        self::assertFalse(Rules::cidr()->test('10.0.0.0/-1'));
        self::assertFalse(Rules::cidr()->test('10.0.0.0/33'));
        self::assertFalse(Rules::cidr()->test('2001:db8::/129'));
        self::assertFalse(Rules::cidr()->test('not-an-ip/24'));
        self::assertFalse(Rules::cidr()->test(null));
    }

    public function testCidrDefaultMessage(): void
    {
        self::assertSame('Must be a valid CIDR block.', Rules::cidr()->errorMessage());
    }

    // ---- macAddress ----

    public function testMacAddressPasses(): void
    {
        self::assertTrue(Rules::macAddress()->test('00:1A:2B:3C:4D:5E'));
        self::assertTrue(Rules::macAddress()->test('00-1A-2B-3C-4D-5E'));
    }

    public function testMacAddressFails(): void
    {
        self::assertFalse(Rules::macAddress()->test('00:1A:2B:3C:4D'));
        self::assertFalse(Rules::macAddress()->test('ZZ:ZZ:ZZ:ZZ:ZZ:ZZ'));
        self::assertFalse(Rules::macAddress()->test('not-a-mac'));
        self::assertFalse(Rules::macAddress()->test(null));
    }

    public function testMacAddressDefaultMessage(): void
    {
        self::assertSame('Must be a valid MAC address.', Rules::macAddress()->errorMessage());
    }

    // ---- port ----

    public function testPortPasses(): void
    {
        self::assertTrue(Rules::port()->test(80));
        self::assertTrue(Rules::port()->test('443'));
        self::assertTrue(Rules::port()->test(65535));
    }

    public function testPortFails(): void
    {
        self::assertFalse(Rules::port()->test(0));
        self::assertFalse(Rules::port()->test(65536));
        self::assertFalse(Rules::port()->test('-1'));
        self::assertFalse(Rules::port()->test('abc'));
        self::assertFalse(Rules::port()->test(null));
    }

    public function testPortDefaultMessage(): void
    {
        self::assertSame('Must be a valid port number.', Rules::port()->errorMessage());
    }

    // ---- host ----

    public function testHostPassesHostnameAndIp(): void
    {
        self::assertTrue(Rules::host()->test('example.com'));
        self::assertTrue(Rules::host()->test('192.168.1.1'));
        self::assertTrue(Rules::host()->test('2001:db8::1'));
    }

    public function testHostFailsInvalidValues(): void
    {
        self::assertFalse(Rules::host()->test(''));
        self::assertFalse(Rules::host()->test('not a host'));
        self::assertFalse(Rules::host()->test('bad..host'));
        self::assertFalse(Rules::host()->test(null));
    }

    public function testHostDefaultMessage(): void
    {
        self::assertSame('Must be a valid host.', Rules::host()->errorMessage());
    }

    // ---- privateIp / publicIp / subnetMask ----

    public function testPrivateIpPassesAndFails(): void
    {
        self::assertTrue(Rules::privateIp()->test('10.0.0.1'));
        self::assertTrue(Rules::privateIp()->test('192.168.1.5'));
        self::assertFalse(Rules::privateIp()->test('8.8.8.8'));
        self::assertFalse(Rules::privateIp()->test('256.1.1.1'));
        self::assertFalse(Rules::privateIp()->test(null));
    }

    public function testPrivateIpDefaultMessage(): void
    {
        self::assertSame('Must be a valid private IP address.', Rules::privateIp()->errorMessage());
    }

    public function testPublicIpPassesAndFails(): void
    {
        self::assertTrue(Rules::publicIp()->test('8.8.8.8'));
        self::assertFalse(Rules::publicIp()->test('10.0.0.1'));
        self::assertFalse(Rules::publicIp()->test('127.0.0.1'));
        self::assertFalse(Rules::publicIp()->test('not-an-ip'));
        self::assertFalse(Rules::publicIp()->test(null));
    }

    public function testPublicIpDefaultMessage(): void
    {
        self::assertSame('Must be a valid public IP address.', Rules::publicIp()->errorMessage());
    }

    public function testSubnetMaskPassesAndFails(): void
    {
        self::assertTrue(Rules::subnetMask()->test('255.255.255.0'));
        self::assertTrue(Rules::subnetMask()->test('255.255.0.0'));
        self::assertTrue(Rules::subnetMask()->test('255.255.255.255'));
        self::assertTrue(Rules::subnetMask()->test('0.0.0.0'));
        self::assertFalse(Rules::subnetMask()->test('255.0.255.0'));
        self::assertFalse(Rules::subnetMask()->test('255.255.255.1'));
        self::assertFalse(Rules::subnetMask()->test('::1'));
        self::assertFalse(Rules::subnetMask()->test(null));
    }

    public function testSubnetMaskDefaultMessage(): void
    {
        self::assertSame('Must be a valid subnet mask.', Rules::subnetMask()->errorMessage());
    }

    // ---- portRange ----

    public function testPortRangePasses(): void
    {
        self::assertTrue(Rules::portRange()->test('1-65535'));
        self::assertTrue(Rules::portRange()->test('80-443'));
        self::assertTrue(Rules::portRange()->test('8080-8080'));
    }

    public function testPortRangeFails(): void
    {
        self::assertFalse(Rules::portRange()->test(''));
        self::assertFalse(Rules::portRange()->test('80'));
        self::assertFalse(Rules::portRange()->test('0-80'));
        self::assertFalse(Rules::portRange()->test('80-70000'));
        self::assertFalse(Rules::portRange()->test('443-80'));
        self::assertFalse(Rules::portRange()->test('abc-def'));
        self::assertFalse(Rules::portRange()->test(null));
    }

    public function testPortRangeDefaultMessage(): void
    {
        self::assertSame('Must be a valid port range.', Rules::portRange()->errorMessage());
    }

    // ---- json ----

    public function testJsonPasses(): void
    {
        self::assertTrue(Rules::json()->test('{"name":"zephyrus","ok":true}'));
        self::assertTrue(Rules::json()->test('[1,2,3]'));
        self::assertTrue(Rules::json()->test('"string"'));
        self::assertTrue(Rules::json()->test('null'));
    }

    public function testJsonFails(): void
    {
        self::assertFalse(Rules::json()->test(''));
        self::assertFalse(Rules::json()->test('{invalid}'));
        self::assertFalse(Rules::json()->test('{"missing":}'));
        self::assertFalse(Rules::json()->test(null));
        self::assertFalse(Rules::json()->test(123));
    }

    public function testJsonDefaultMessage(): void
    {
        self::assertSame('Must be valid JSON.', Rules::json()->errorMessage());
    }

    public function testJsonObjectPasses(): void
    {
        self::assertTrue(Rules::jsonObject()->test('{"name":"zephyrus"}'));
        self::assertTrue(Rules::jsonObject()->test('{"meta":{"ok":true},"items":[1,2]}'));
    }

    public function testJsonObjectFails(): void
    {
        self::assertFalse(Rules::jsonObject()->test(''));
        self::assertFalse(Rules::jsonObject()->test('{invalid}'));
        self::assertFalse(Rules::jsonObject()->test('[1,2,3]'));
        self::assertFalse(Rules::jsonObject()->test('"string"'));
        self::assertFalse(Rules::jsonObject()->test('null'));
        self::assertFalse(Rules::jsonObject()->test(null));
        self::assertFalse(Rules::jsonObject()->test(123));
    }

    public function testJsonObjectDefaultMessage(): void
    {
        self::assertSame('Must be a valid JSON object.', Rules::jsonObject()->errorMessage());
    }

    public function testJsonArrayPasses(): void
    {
        self::assertTrue(Rules::jsonArray()->test('[]'));
        self::assertTrue(Rules::jsonArray()->test('[1,2,3]'));
        self::assertTrue(Rules::jsonArray()->test('[{"name":"zephyrus"}]'));
    }

    public function testJsonArrayFails(): void
    {
        self::assertFalse(Rules::jsonArray()->test(''));
        self::assertFalse(Rules::jsonArray()->test('{invalid}'));
        self::assertFalse(Rules::jsonArray()->test('{"name":"zephyrus"}'));
        self::assertFalse(Rules::jsonArray()->test('"string"'));
        self::assertFalse(Rules::jsonArray()->test('null'));
        self::assertFalse(Rules::jsonArray()->test(null));
        self::assertFalse(Rules::jsonArray()->test(123));
    }

    public function testJsonArrayDefaultMessage(): void
    {
        self::assertSame('Must be a valid JSON array.', Rules::jsonArray()->errorMessage());
    }

    // ---- slug ----

    public function testSlugPasses(): void
    {
        self::assertTrue(Rules::slug()->test('zephyrus2'));
        self::assertTrue(Rules::slug()->test('hello-world'));
        self::assertTrue(Rules::slug()->test('api-v1-endpoint'));
    }

    public function testSlugFails(): void
    {
        self::assertFalse(Rules::slug()->test(''));
        self::assertFalse(Rules::slug()->test('Hello-World'));
        self::assertFalse(Rules::slug()->test('-leading'));
        self::assertFalse(Rules::slug()->test('trailing-'));
        self::assertFalse(Rules::slug()->test('double--dash'));
        self::assertFalse(Rules::slug()->test('with_underscore'));
        self::assertFalse(Rules::slug()->test(null));
    }

    public function testSlugDefaultMessage(): void
    {
        self::assertSame('Must be a valid slug.', Rules::slug()->errorMessage());
    }

    // ---- hexColor ----

    public function testHexColorPasses(): void
    {
        self::assertTrue(Rules::hexColor()->test('#fff'));
        self::assertTrue(Rules::hexColor()->test('#1A2b3C'));
    }

    public function testHexColorFails(): void
    {
        self::assertFalse(Rules::hexColor()->test('fff'));
        self::assertFalse(Rules::hexColor()->test('#ff'));
        self::assertFalse(Rules::hexColor()->test('#ffff'));
        self::assertFalse(Rules::hexColor()->test('#gggggg'));
        self::assertFalse(Rules::hexColor()->test(null));
    }

    public function testHexColorDefaultMessage(): void
    {
        self::assertSame('Must be a valid hex color.', Rules::hexColor()->errorMessage());
    }

    // ---- base64 ----

    public function testBase64Passes(): void
    {
        self::assertTrue(Rules::base64()->test('aGVsbG8='));
        self::assertTrue(Rules::base64()->test('eyJrZXkiOiJ2YWx1ZSJ9'));
    }

    public function testBase64Fails(): void
    {
        self::assertFalse(Rules::base64()->test(''));
        self::assertFalse(Rules::base64()->test('not-base64'));
        self::assertFalse(Rules::base64()->test('aGVsbG8'));
        self::assertFalse(Rules::base64()->test(null));
    }

    public function testBase64DefaultMessage(): void
    {
        self::assertSame('Must be valid Base64.', Rules::base64()->errorMessage());
    }

    // ---- semver ----

    public function testSemverPasses(): void
    {
        self::assertTrue(Rules::semver()->test('1.0.0'));
        self::assertTrue(Rules::semver()->test('2.3.4-beta.1'));
        self::assertTrue(Rules::semver()->test('10.20.30+build.7'));
        self::assertTrue(Rules::semver()->test('1.2.3-rc.1+sha.abcdef'));
    }

    public function testSemverFails(): void
    {
        self::assertFalse(Rules::semver()->test('1.0'));
        self::assertFalse(Rules::semver()->test('01.2.3'));
        self::assertFalse(Rules::semver()->test('1.2.3-'));
        self::assertFalse(Rules::semver()->test('v1.2.3'));
        self::assertFalse(Rules::semver()->test(null));
    }

    public function testSemverDefaultMessage(): void
    {
        self::assertSame('Must be a valid semantic version.', Rules::semver()->errorMessage());
    }

    // ---- ulid ----

    public function testUlidPasses(): void
    {
        self::assertTrue(Rules::ulid()->test('01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        self::assertTrue(Rules::ulid()->test('7ZZZZZZZZZZZZZZZZZZZZZZZZZ'));
    }

    public function testUlidFails(): void
    {
        self::assertFalse(Rules::ulid()->test(''));
        self::assertFalse(Rules::ulid()->test('01ARZ3NDEKTSV4RRFFQ69G5FA')); // too short
        self::assertFalse(Rules::ulid()->test('01ARZ3NDEKTSV4RRFFQ69G5FAVX')); // too long
        self::assertFalse(Rules::ulid()->test('01ARZ3NDEKTSV4RRFFQ69G5FAI')); // I not allowed
        self::assertFalse(Rules::ulid()->test(null));
    }

    public function testUlidDefaultMessage(): void
    {
        self::assertSame('Must be a valid ULID.', Rules::ulid()->errorMessage());
    }

    // ---- sha256 ----

    public function testSha256Passes(): void
    {
        self::assertTrue(Rules::sha256()->test('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'));
    }

    public function testSha256Fails(): void
    {
        self::assertFalse(Rules::sha256()->test(''));
        self::assertFalse(Rules::sha256()->test('E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855'));
        self::assertFalse(Rules::sha256()->test('abc'));
        self::assertFalse(Rules::sha256()->test(null));
    }

    public function testSha256DefaultMessage(): void
    {
        self::assertSame('Must be a valid SHA-256 hash.', Rules::sha256()->errorMessage());
    }

    // ---- httpPath ----

    public function testHttpPathPasses(): void
    {
        self::assertTrue(Rules::httpPath()->test('/'));
        self::assertTrue(Rules::httpPath()->test('/api/v1/users'));
        self::assertTrue(Rules::httpPath()->test('/health?deep=1'));
    }

    public function testHttpPathFails(): void
    {
        self::assertFalse(Rules::httpPath()->test('api/v1/users'));
        self::assertFalse(Rules::httpPath()->test(''));
        self::assertFalse(Rules::httpPath()->test('/bad path'));
        self::assertFalse(Rules::httpPath()->test(null));
    }

    public function testHttpPathDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP path.', Rules::httpPath()->errorMessage());
    }

    // ---- pathSegment / safeFilename / fileExtension ----

    public function testPathSegmentPassesAndFails(): void
    {
        self::assertTrue(Rules::pathSegment()->test('file-name_01.txt'));
        self::assertFalse(Rules::pathSegment()->test('dir/file'));
        self::assertFalse(Rules::pathSegment()->test('..'));
        self::assertFalse(Rules::pathSegment()->test(''));
        self::assertFalse(Rules::pathSegment()->test(null));
    }

    public function testPathSegmentDefaultMessage(): void
    {
        self::assertSame('Must be a valid path segment.', Rules::pathSegment()->errorMessage());
    }

    public function testSafeFilenamePassesAndFails(): void
    {
        self::assertTrue(Rules::safeFilename()->test('report-2026_02.csv'));
        self::assertFalse(Rules::safeFilename()->test('../secrets.txt'));
        self::assertFalse(Rules::safeFilename()->test('bad/name.txt'));
        self::assertFalse(Rules::safeFilename()->test('')); 
        self::assertFalse(Rules::safeFilename()->test(null));
    }

    public function testSafeFilenameDefaultMessage(): void
    {
        self::assertSame('Must be a safe filename.', Rules::safeFilename()->errorMessage());
    }

    public function testFileExtensionPassesAndFails(): void
    {
        self::assertTrue(Rules::fileExtension()->test('json'));
        self::assertTrue(Rules::fileExtension()->test('JPEG'));
        self::assertFalse(Rules::fileExtension()->test('.json'));
        self::assertFalse(Rules::fileExtension()->test('tar.gz'));
        self::assertFalse(Rules::fileExtension()->test(null));
    }

    public function testFileExtensionDefaultMessage(): void
    {
        self::assertSame('Must be a valid file extension.', Rules::fileExtension()->errorMessage());
    }

    // ---- queryString ----

    public function testQueryStringPasses(): void
    {
        self::assertTrue(Rules::queryString()->test('a=1&b=2'));
        self::assertTrue(Rules::queryString()->test('flag=true'));
        self::assertTrue(Rules::queryString()->test('')); // empty query is valid
    }

    public function testQueryStringFails(): void
    {
        self::assertFalse(Rules::queryString()->test('?a=1'));
        self::assertFalse(Rules::queryString()->test('a=1#frag'));
        self::assertFalse(Rules::queryString()->test('a = 1'));
        self::assertFalse(Rules::queryString()->test(null));
    }

    public function testQueryStringDefaultMessage(): void
    {
        self::assertSame('Must be a valid query string.', Rules::queryString()->errorMessage());
    }

    // ---- percentEncoded ----

    public function testPercentEncodedPasses(): void
    {
        self::assertTrue(Rules::percentEncoded()->test('hello%20world'));
        self::assertTrue(Rules::percentEncoded()->test('abc-_.~'));
        self::assertTrue(Rules::percentEncoded()->test('%2Fapi%2Fv1'));
    }

    public function testPercentEncodedFails(): void
    {
        self::assertFalse(Rules::percentEncoded()->test('%ZZ'));
        self::assertFalse(Rules::percentEncoded()->test('bad space'));
        self::assertFalse(Rules::percentEncoded()->test('')); 
        self::assertFalse(Rules::percentEncoded()->test(null));
    }

    public function testPercentEncodedDefaultMessage(): void
    {
        self::assertSame('Must be a valid percent-encoded string.', Rules::percentEncoded()->errorMessage());
    }

    // ---- httpStatusCode ----

    public function testHttpStatusCodePasses(): void
    {
        self::assertTrue(Rules::httpStatusCode()->test(200));
        self::assertTrue(Rules::httpStatusCode()->test('404'));
        self::assertTrue(Rules::httpStatusCode()->test(599));
    }

    public function testHttpStatusCodeFails(): void
    {
        self::assertFalse(Rules::httpStatusCode()->test(99));
        self::assertFalse(Rules::httpStatusCode()->test(600));
        self::assertFalse(Rules::httpStatusCode()->test('20a'));
        self::assertFalse(Rules::httpStatusCode()->test(null));
    }

    public function testHttpStatusCodeDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP status code.', Rules::httpStatusCode()->errorMessage());
    }

    // ---- httpMethod ----

    public function testHttpMethodPassesCommonMethods(): void
    {
        self::assertTrue(Rules::httpMethod()->test('GET'));
        self::assertTrue(Rules::httpMethod()->test('post'));
        self::assertTrue(Rules::httpMethod()->test('PATCH'));
    }

    public function testHttpMethodFailsInvalidValues(): void
    {
        self::assertFalse(Rules::httpMethod()->test('FETCH'));
        self::assertFalse(Rules::httpMethod()->test(''));
        self::assertFalse(Rules::httpMethod()->test(null));
    }

    public function testHttpMethodDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP method.', Rules::httpMethod()->errorMessage());
    }

    // ---- httpVersion ----

    public function testHttpVersionPassesCommonVersions(): void
    {
        self::assertTrue(Rules::httpVersion()->test('HTTP/1.0'));
        self::assertTrue(Rules::httpVersion()->test('http/1.1'));
        self::assertTrue(Rules::httpVersion()->test('HTTP/2'));
        self::assertTrue(Rules::httpVersion()->test('HTTP/2.0'));
        self::assertTrue(Rules::httpVersion()->test('HTTP/3'));
        self::assertTrue(Rules::httpVersion()->test('HTTP/3.0'));
    }

    public function testHttpVersionFailsInvalidValues(): void
    {
        self::assertFalse(Rules::httpVersion()->test('HTTP/1'));
        self::assertFalse(Rules::httpVersion()->test('HTTP/1.2'));
        self::assertFalse(Rules::httpVersion()->test('HTTP/4'));
        self::assertFalse(Rules::httpVersion()->test('1.1'));
        self::assertFalse(Rules::httpVersion()->test(''));
        self::assertFalse(Rules::httpVersion()->test(null));
    }

    public function testHttpVersionDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP version.', Rules::httpVersion()->errorMessage());
    }

    // ---- mimeType ----

    public function testMimeTypePasses(): void
    {
        self::assertTrue(Rules::mimeType()->test('application/json'));
        self::assertTrue(Rules::mimeType()->test('text/plain'));
        self::assertTrue(Rules::mimeType()->test('application/vnd.api+json'));
    }

    public function testMimeTypeFails(): void
    {
        self::assertFalse(Rules::mimeType()->test('application'));
        self::assertFalse(Rules::mimeType()->test('application/'));
        self::assertFalse(Rules::mimeType()->test('/json'));
        self::assertFalse(Rules::mimeType()->test(null));
    }

    public function testMimeTypeDefaultMessage(): void
    {
        self::assertSame('Must be a valid MIME type.', Rules::mimeType()->errorMessage());
    }

    // ---- bearerToken ----

    public function testBearerTokenPasses(): void
    {
        self::assertTrue(Rules::bearerToken()->test('abc.DEF-123_~+/=='));
        self::assertTrue(Rules::bearerToken()->test('token123'));
    }

    public function testBearerTokenFails(): void
    {
        self::assertFalse(Rules::bearerToken()->test('token with spaces'));
        self::assertFalse(Rules::bearerToken()->test('token*bad'));
        self::assertFalse(Rules::bearerToken()->test(null));
    }

    public function testBearerTokenDefaultMessage(): void
    {
        self::assertSame('Must be a valid bearer token.', Rules::bearerToken()->errorMessage());
    }

    // ---- languageTag ----

    public function testLanguageTagPasses(): void
    {
        self::assertTrue(Rules::languageTag()->test('en'));
        self::assertTrue(Rules::languageTag()->test('en-CA'));
        self::assertTrue(Rules::languageTag()->test('zh-Hant-TW'));
    }

    public function testLanguageTagFails(): void
    {
        self::assertFalse(Rules::languageTag()->test('english'));
        self::assertFalse(Rules::languageTag()->test('en_CA'));
        self::assertFalse(Rules::languageTag()->test('')); 
        self::assertFalse(Rules::languageTag()->test(null));
    }

    public function testLanguageTagDefaultMessage(): void
    {
        self::assertSame('Must be a valid language tag.', Rules::languageTag()->errorMessage());
    }

    // ---- httpHeaderName ----

    public function testHttpHeaderNamePasses(): void
    {
        self::assertTrue(Rules::httpHeaderName()->test('Content-Type'));
        self::assertTrue(Rules::httpHeaderName()->test('X-Trace_Id'));
        self::assertTrue(Rules::httpHeaderName()->test('ETag'));
    }

    public function testHttpHeaderNameFails(): void
    {
        self::assertFalse(Rules::httpHeaderName()->test('Content Type'));
        self::assertFalse(Rules::httpHeaderName()->test(':authority'));
        self::assertFalse(Rules::httpHeaderName()->test(''));
        self::assertFalse(Rules::httpHeaderName()->test(null));
    }

    public function testHttpHeaderNameDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP header name.', Rules::httpHeaderName()->errorMessage());
    }

    // ---- httpHeaderValue ----

    public function testHttpHeaderValuePasses(): void
    {
        self::assertTrue(Rules::httpHeaderValue()->test('application/json; charset=utf-8'));
        self::assertTrue(Rules::httpHeaderValue()->test('max-age=3600'));
        self::assertTrue(Rules::httpHeaderValue()->test('token\tvalue'));
    }

    public function testHttpHeaderValueFails(): void
    {
        self::assertFalse(Rules::httpHeaderValue()->test("bad\nvalue"));
        self::assertFalse(Rules::httpHeaderValue()->test("bad\rvalue"));
        self::assertFalse(Rules::httpHeaderValue()->test(null));
    }

    public function testHttpHeaderValueDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP header value.', Rules::httpHeaderValue()->errorMessage());
    }

    // ---- jwt ----

    public function testJwtPassesCompactSerializationShape(): void
    {
        self::assertTrue(Rules::jwt()->test('eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.signature'));
        self::assertTrue(Rules::jwt()->test('a.b.'));
    }

    public function testJwtFailsInvalidShapes(): void
    {
        self::assertFalse(Rules::jwt()->test('singlepart'));
        self::assertFalse(Rules::jwt()->test('a.b'));
        self::assertFalse(Rules::jwt()->test('a.b.c.d'));
        self::assertFalse(Rules::jwt()->test('a.b.c+'));
        self::assertFalse(Rules::jwt()->test(null));
    }

    public function testJwtDefaultMessage(): void
    {
        self::assertSame('Must be a valid JWT token format.', Rules::jwt()->errorMessage());
    }

    // ---- latitude / longitude ----

    public function testLatitudePassesAndFails(): void
    {
        self::assertTrue(Rules::latitude()->test(45.5));
        self::assertTrue(Rules::latitude()->test('-90'));
        self::assertTrue(Rules::latitude()->test(90));
        self::assertFalse(Rules::latitude()->test(-90.1));
        self::assertFalse(Rules::latitude()->test(90.1));
        self::assertFalse(Rules::latitude()->test('north'));
    }

    public function testLatitudeDefaultMessage(): void
    {
        self::assertSame('Must be a valid latitude.', Rules::latitude()->errorMessage());
    }

    public function testLongitudePassesAndFails(): void
    {
        self::assertTrue(Rules::longitude()->test(0));
        self::assertTrue(Rules::longitude()->test('180'));
        self::assertTrue(Rules::longitude()->test(-180));
        self::assertFalse(Rules::longitude()->test(180.1));
        self::assertFalse(Rules::longitude()->test(-180.1));
        self::assertFalse(Rules::longitude()->test('west'));
    }

    public function testLongitudeDefaultMessage(): void
    {
        self::assertSame('Must be a valid longitude.', Rules::longitude()->errorMessage());
    }

    // ---- unixTimestamp / epochMilliseconds ----

    public function testUnixTimestampPassesAndFails(): void
    {
        self::assertTrue(Rules::unixTimestamp()->test(0));
        self::assertTrue(Rules::unixTimestamp()->test('1700000000'));
        self::assertFalse(Rules::unixTimestamp()->test(-1));
        self::assertFalse(Rules::unixTimestamp()->test('12.34'));
        self::assertFalse(Rules::unixTimestamp()->test('now'));
    }

    public function testUnixTimestampDefaultMessage(): void
    {
        self::assertSame('Must be a valid Unix timestamp.', Rules::unixTimestamp()->errorMessage());
    }

    public function testEpochMillisecondsPassesAndFails(): void
    {
        self::assertTrue(Rules::epochMilliseconds()->test(1700000000000));
        self::assertTrue(Rules::epochMilliseconds()->test('1700000000000'));
        self::assertFalse(Rules::epochMilliseconds()->test(-1000));
        self::assertFalse(Rules::epochMilliseconds()->test('1700ms'));
        self::assertFalse(Rules::epochMilliseconds()->test(null));
    }

    public function testEpochMillisecondsDefaultMessage(): void
    {
        self::assertSame(
            'Must be a valid epoch-milliseconds value.',
            Rules::epochMilliseconds()->errorMessage(),
        );
    }

    // ---- locale / uuidV1toV5 / uuidV4 / uuidV7 ----

    public function testLocalePassesAndFails(): void
    {
        self::assertTrue(Rules::locale()->test('en_CA'));
        self::assertTrue(Rules::locale()->test('fr_FR'));
        self::assertFalse(Rules::locale()->test('en-CA'));
        self::assertFalse(Rules::locale()->test('english_CA'));
        self::assertFalse(Rules::locale()->test(null));
    }

    public function testLocaleDefaultMessage(): void
    {
        self::assertSame('Must be a valid locale (e.g. en_CA).', Rules::locale()->errorMessage());
    }

    public function testUuidV1toV5PassesAndFails(): void
    {
        self::assertTrue(Rules::uuidV1toV5()->test('550e8400-e29b-41d4-a716-446655440000'));
        self::assertTrue(Rules::uuidV1toV5()->test('6ba7b810-9dad-11d1-80b4-00c04fd430c8'));
        self::assertFalse(Rules::uuidV1toV5()->test('550e8400-e29b-61d4-a716-446655440000'));
        self::assertFalse(Rules::uuidV1toV5()->test('not-a-uuid'));
        self::assertFalse(Rules::uuidV1toV5()->test(null));
    }

    public function testUuidV1toV5DefaultMessage(): void
    {
        self::assertSame('Must be a valid UUID v1-v5.', Rules::uuidV1toV5()->errorMessage());
    }

    public function testUuidV4PassesAndFails(): void
    {
        self::assertTrue(Rules::uuidV4()->test('550e8400-e29b-41d4-a716-446655440000'));
        self::assertFalse(Rules::uuidV4()->test('6ba7b810-9dad-11d1-80b4-00c04fd430c8'));
        self::assertFalse(Rules::uuidV4()->test('550e8400-e29b-61d4-a716-446655440000'));
        self::assertFalse(Rules::uuidV4()->test(null));
    }

    public function testUuidV4DefaultMessage(): void
    {
        self::assertSame('Must be a valid UUID v4.', Rules::uuidV4()->errorMessage());
    }

    public function testUuidV6PassesValidCanonicalForms(): void
    {
        // version nibble = 6, variant nibble = 8 (1000 binary)
        self::assertTrue(Rules::uuidV6()->test('1ef22c49-2f62-6000-8000-000000000000'));
        // variant nibble = 9
        self::assertTrue(Rules::uuidV6()->test('1ef22c49-2f62-6abc-9123-abcdef012345'));
        // variant nibble = a
        self::assertTrue(Rules::uuidV6()->test('1ef22c49-2f62-6fff-afff-ffffffffffff'));
        // variant nibble = b
        self::assertTrue(Rules::uuidV6()->test('1ef22c49-2f62-6001-b001-000000000001'));
        // uppercase is accepted (case-insensitive)
        self::assertTrue(Rules::uuidV6()->test('1EF22C49-2F62-6ABC-AABC-000000000000'));
    }

    public function testUuidV6RejectsWrongVersion(): void
    {
        self::assertFalse(Rules::uuidV6()->test('550e8400-e29b-41d4-a716-446655440000')); // v4
        self::assertFalse(Rules::uuidV6()->test('6ba7b810-9dad-11d1-80b4-00c04fd430c8')); // v1
        self::assertFalse(Rules::uuidV6()->test('018e2990-aa07-7000-8000-000000000000')); // v7
    }

    public function testUuidV6RejectsWrongVariant(): void
    {
        self::assertFalse(Rules::uuidV6()->test('1ef22c49-2f62-6000-c000-000000000000')); // variant c
        self::assertFalse(Rules::uuidV6()->test('1ef22c49-2f62-6000-0000-000000000000')); // variant 0
        self::assertFalse(Rules::uuidV6()->test('1ef22c49-2f62-6000-f000-000000000000')); // variant f
    }

    public function testUuidV6RejectsMalformedStrings(): void
    {
        self::assertFalse(Rules::uuidV6()->test('not-a-uuid'));
        self::assertFalse(Rules::uuidV6()->test('1ef22c49-2f62-6000-8000-00000000000'));  // too short
        self::assertFalse(Rules::uuidV6()->test('1ef22c49-2f62-6000-8000-0000000000000')); // too long
        self::assertFalse(Rules::uuidV6()->test(''));
    }

    public function testUuidV6RejectsNonString(): void
    {
        self::assertFalse(Rules::uuidV6()->test(null));
        self::assertFalse(Rules::uuidV6()->test(42));
        self::assertFalse(Rules::uuidV6()->test([]));
    }

    public function testUuidV6DefaultMessage(): void
    {
        self::assertSame('Must be a valid UUID v6.', Rules::uuidV6()->errorMessage());
    }

    public function testUuidV6CustomMessage(): void
    {
        self::assertSame('Invalid UUID v6 format.', Rules::uuidV6('Invalid UUID v6 format.')->errorMessage());
    }

    public function testUuidV7PassesValidCanonicalForms(): void
    {
        // version nibble = 7, variant nibble = 8 (1000 binary)
        self::assertTrue(Rules::uuidV7()->test('018e2990-aa07-7000-8000-000000000000'));
        // variant nibble = 9
        self::assertTrue(Rules::uuidV7()->test('018e2990-aa07-7abc-9123-abcdef012345'));
        // variant nibble = a
        self::assertTrue(Rules::uuidV7()->test('018e2990-aa07-7fff-afff-ffffffffffff'));
        // variant nibble = b
        self::assertTrue(Rules::uuidV7()->test('018e2990-aa07-7001-b001-000000000001'));
        // uppercase is accepted (case-insensitive)
        self::assertTrue(Rules::uuidV7()->test('018E2990-AA07-7ABC-AABC-000000000000'));
    }

    public function testUuidV7RejectsWrongVersion(): void
    {
        // version nibble = 4 (UUID v4)
        self::assertFalse(Rules::uuidV7()->test('550e8400-e29b-41d4-a716-446655440000'));
        // version nibble = 1 (UUID v1)
        self::assertFalse(Rules::uuidV7()->test('6ba7b810-9dad-11d1-80b4-00c04fd430c8'));
        // version nibble = 8 (not 7)
        self::assertFalse(Rules::uuidV7()->test('018e2990-aa07-8000-8000-000000000000'));
    }

    public function testUuidV7RejectsWrongVariant(): void
    {
        // variant nibble = c (not in [89ab])
        self::assertFalse(Rules::uuidV7()->test('018e2990-aa07-7000-c000-000000000000'));
        // variant nibble = 0
        self::assertFalse(Rules::uuidV7()->test('018e2990-aa07-7000-0000-000000000000'));
        // variant nibble = f
        self::assertFalse(Rules::uuidV7()->test('018e2990-aa07-7000-f000-000000000000'));
    }

    public function testUuidV7RejectsMalformedStrings(): void
    {
        self::assertFalse(Rules::uuidV7()->test('not-a-uuid'));
        self::assertFalse(Rules::uuidV7()->test('018e2990-aa07-7000-8000-00000000000'));  // too short
        self::assertFalse(Rules::uuidV7()->test('018e2990-aa07-7000-8000-0000000000000')); // too long
        self::assertFalse(Rules::uuidV7()->test(''));
    }

    public function testUuidV7RejectsNonString(): void
    {
        self::assertFalse(Rules::uuidV7()->test(null));
        self::assertFalse(Rules::uuidV7()->test(42));
        self::assertFalse(Rules::uuidV7()->test([]));
    }

    public function testUuidV7DefaultMessage(): void
    {
        self::assertSame('Must be a valid UUID v7.', Rules::uuidV7()->errorMessage());
    }

    public function testUuidV7CustomMessage(): void
    {
        self::assertSame('Invalid UUID v7 format.', Rules::uuidV7('Invalid UUID v7 format.')->errorMessage());
    }

    public function testUuidV8PassesValidCanonicalForms(): void
    {
        self::assertTrue(Rules::uuidV8()->test('018e2990-aa07-8000-8000-000000000000'));
        self::assertTrue(Rules::uuidV8()->test('018e2990-aa07-8abc-9123-abcdef012345'));
        self::assertTrue(Rules::uuidV8()->test('018E2990-AA07-8ABC-AABC-000000000000'));
    }

    public function testUuidV8RejectsWrongVersionOrVariant(): void
    {
        self::assertFalse(Rules::uuidV8()->test('018e2990-aa07-7000-8000-000000000000'));
        self::assertFalse(Rules::uuidV8()->test('018e2990-aa07-8000-c000-000000000000'));
    }

    public function testUuidV8RejectsMalformedOrNonStringValues(): void
    {
        self::assertFalse(Rules::uuidV8()->test('not-a-uuid'));
        self::assertFalse(Rules::uuidV8()->test('018e2990-aa07-8000-8000-00000000000'));
        self::assertFalse(Rules::uuidV8()->test(null));
        self::assertFalse(Rules::uuidV8()->test(42));
    }

    public function testUuidV8DefaultAndCustomMessages(): void
    {
        self::assertSame('Must be a valid UUID v8.', Rules::uuidV8()->errorMessage());
        self::assertSame('Invalid UUID v8 format.', Rules::uuidV8('Invalid UUID v8 format.')->errorMessage());
    }

    // ---- countryCode / currencyCode ----

    public function testCountryCodePassesAndFails(): void
    {
        self::assertTrue(Rules::countryCode()->test('CA'));
        self::assertTrue(Rules::countryCode()->test('us'));
        self::assertFalse(Rules::countryCode()->test('CAN'));
        self::assertFalse(Rules::countryCode()->test('C1'));
        self::assertFalse(Rules::countryCode()->test(null));
    }

    public function testCountryCodeDefaultMessage(): void
    {
        self::assertSame('Must be a valid ISO country code.', Rules::countryCode()->errorMessage());
    }

    public function testCurrencyCodePassesAndFails(): void
    {
        self::assertTrue(Rules::currencyCode()->test('CAD'));
        self::assertTrue(Rules::currencyCode()->test('usd'));
        self::assertFalse(Rules::currencyCode()->test('US'));
        self::assertFalse(Rules::currencyCode()->test('US1'));
        self::assertFalse(Rules::currencyCode()->test(null));
    }

    public function testCurrencyCodeDefaultMessage(): void
    {
        self::assertSame('Must be a valid ISO currency code.', Rules::currencyCode()->errorMessage());
    }

    // ---- cardNumber / cardNumberLuhn / cardCvv / cardExpiry* / nonEmptyString / inCaseInsensitive / jsonPointer ----

    public function testCardNumberPassesAndFails(): void
    {
        self::assertTrue(Rules::cardNumber()->test('4111111111111111'));
        self::assertTrue(Rules::cardNumber()->test('4111 1111 1111 1111'));
        self::assertTrue(Rules::cardNumber()->test('4111-1111-1111-1111'));
        self::assertFalse(Rules::cardNumber()->test('4111 1111')); // too short
        self::assertFalse(Rules::cardNumber()->test('abcd-efgh-ijkl-mnop'));
        self::assertFalse(Rules::cardNumber()->test(null));
    }

    public function testCardNumberDefaultMessage(): void
    {
        self::assertSame('Must be a valid card number format.', Rules::cardNumber()->errorMessage());
    }

    public function testCardCvvPassesAndFails(): void
    {
        self::assertTrue(Rules::cardCvv()->test('123'));
        self::assertTrue(Rules::cardCvv()->test('1234'));
        self::assertFalse(Rules::cardCvv()->test('12'));
        self::assertFalse(Rules::cardCvv()->test('12345'));
        self::assertFalse(Rules::cardCvv()->test('12a'));
        self::assertFalse(Rules::cardCvv()->test(null));
    }

    public function testCardCvvDefaultMessage(): void
    {
        self::assertSame('Must be a valid card CVV.', Rules::cardCvv()->errorMessage());
    }

    public function testCardNumberLuhnPassesAndFails(): void
    {
        self::assertTrue(Rules::cardNumberLuhn()->test('4111111111111111'));
        self::assertTrue(Rules::cardNumberLuhn()->test('4012 8888 8888 1881'));
        self::assertFalse(Rules::cardNumberLuhn()->test('4111111111111112'));
        self::assertFalse(Rules::cardNumberLuhn()->test('4111-1111'));
        self::assertFalse(Rules::cardNumberLuhn()->test(null));
    }

    public function testCardNumberLuhnDefaultMessage(): void
    {
        self::assertSame('Must be a valid card number.', Rules::cardNumberLuhn()->errorMessage());
    }

    public function testCardExpiryMmyyPassesAndFails(): void
    {
        self::assertTrue(Rules::cardExpiryMmyy()->test('01/30'));
        self::assertTrue(Rules::cardExpiryMmyy()->test('12/99'));
        self::assertFalse(Rules::cardExpiryMmyy()->test('00/30'));
        self::assertFalse(Rules::cardExpiryMmyy()->test('13/30'));
        self::assertFalse(Rules::cardExpiryMmyy()->test('1/30'));
        self::assertFalse(Rules::cardExpiryMmyy()->test(null));
    }

    public function testCardExpiryMmyyDefaultMessage(): void
    {
        self::assertSame('Must be a valid card expiry (MM/YY).', Rules::cardExpiryMmyy()->errorMessage());
    }

    public function testCardExpiryMmyyyyPassesAndFails(): void
    {
        self::assertTrue(Rules::cardExpiryMmyyyy()->test('01/2030'));
        self::assertTrue(Rules::cardExpiryMmyyyy()->test('12/2099'));
        self::assertFalse(Rules::cardExpiryMmyyyy()->test('00/2030'));
        self::assertFalse(Rules::cardExpiryMmyyyy()->test('13/2030'));
        self::assertFalse(Rules::cardExpiryMmyyyy()->test('01/30'));
        self::assertFalse(Rules::cardExpiryMmyyyy()->test(null));
    }

    public function testCardExpiryMmyyyyDefaultMessage(): void
    {
        self::assertSame('Must be a valid card expiry (MM/YYYY).', Rules::cardExpiryMmyyyy()->errorMessage());
    }

    public function testNonEmptyStringPassesAndFails(): void
    {
        self::assertTrue(Rules::nonEmptyString()->test('hello'));
        self::assertTrue(Rules::nonEmptyString()->test('  hello  '));
        self::assertFalse(Rules::nonEmptyString()->test(''));
        self::assertFalse(Rules::nonEmptyString()->test('   '));
        self::assertFalse(Rules::nonEmptyString()->test(null));
    }

    public function testNonEmptyStringDefaultMessage(): void
    {
        self::assertSame('Must be a non-empty string.', Rules::nonEmptyString()->errorMessage());
    }

    public function testInCaseInsensitivePassesAndFails(): void
    {
        $rule = Rules::inCaseInsensitive(['GET', 'POST', 'PATCH']);

        self::assertTrue($rule->test('get'));
        self::assertTrue($rule->test('POST'));
        self::assertFalse($rule->test('delete'));
        self::assertFalse($rule->test(null));
    }

    public function testInCaseInsensitiveDefaultMessage(): void
    {
        self::assertSame('Must be one of the allowed values.', Rules::inCaseInsensitive(['a'])->errorMessage());
    }

    public function testJsonPointerPassesAndFails(): void
    {
        self::assertTrue(Rules::jsonPointer()->test(''));
        self::assertTrue(Rules::jsonPointer()->test('/a/b'));
        self::assertTrue(Rules::jsonPointer()->test('/a~1b/c~0d'));
        self::assertFalse(Rules::jsonPointer()->test('a/b'));
        self::assertFalse(Rules::jsonPointer()->test('/a/~2'));
        self::assertFalse(Rules::jsonPointer()->test(null));
    }

    public function testJsonPointerDefaultMessage(): void
    {
        self::assertSame('Must be a valid JSON Pointer.', Rules::jsonPointer()->errorMessage());
    }

    // ---- iban / bic ----

    public function testIbanPassesAndFailsBasicStructure(): void
    {
        self::assertTrue(Rules::iban()->test('GB82 WEST 1234 5698 7654 32'));
        self::assertTrue(Rules::iban()->test('DE89370400440532013000'));
        self::assertFalse(Rules::iban()->test('GB82')); // too short
        self::assertFalse(Rules::iban()->test('ZZ!!INVALID123'));
        self::assertFalse(Rules::iban()->test(null));
    }

    public function testIbanDefaultMessage(): void
    {
        self::assertSame('Must be a valid IBAN format.', Rules::iban()->errorMessage());
    }

    public function testBicPassesAndFails(): void
    {
        self::assertTrue(Rules::bic()->test('DEUTDEFF'));
        self::assertTrue(Rules::bic()->test('NEDSZAJJXXX'));
        self::assertFalse(Rules::bic()->test('DEUTDE')); // too short
        self::assertFalse(Rules::bic()->test('DEUTDEFF!'));
        self::assertFalse(Rules::bic()->test(null));
    }

    public function testBicDefaultMessage(): void
    {
        self::assertSame('Must be a valid BIC/SWIFT code.', Rules::bic()->errorMessage());
    }

    // ---- hostPort ----

    public function testHostPortPassesValidEndpoints(): void
    {
        self::assertTrue(Rules::hostPort()->test('example.com:443'));
        self::assertTrue(Rules::hostPort()->test('192.168.1.10:8080'));
        self::assertTrue(Rules::hostPort()->test('[2001:db8::1]:443'));
    }

    public function testHostPortFailsInvalidEndpoints(): void
    {
        self::assertFalse(Rules::hostPort()->test('example.com'));
        self::assertFalse(Rules::hostPort()->test('example.com:0'));
        self::assertFalse(Rules::hostPort()->test('example.com:70000'));
        self::assertFalse(Rules::hostPort()->test('bad..host:443'));
        self::assertFalse(Rules::hostPort()->test('2001:db8::1:443')); // IPv6 must be bracketed
        self::assertFalse(Rules::hostPort()->test('[2001:db8::1]443'));
        self::assertFalse(Rules::hostPort()->test(null));
    }

    public function testHostPortDefaultMessage(): void
    {
        self::assertSame('Must be a valid host:port endpoint.', Rules::hostPort()->errorMessage());
    }

    // ---- etag ----

    public function testEtagPassesStrongEtags(): void
    {
        self::assertTrue(Rules::etag()->test('"abc"'));
        self::assertTrue(Rules::etag()->test('"abc-def_123"'));
        self::assertTrue(Rules::etag()->test('"v1.2.3+build"'));
        self::assertTrue(Rules::etag()->test('""')); // empty opaque tag is valid per RFC 7232
    }

    public function testEtagPassesWeakEtags(): void
    {
        self::assertTrue(Rules::etag()->test('W/"abc"'));
        self::assertTrue(Rules::etag()->test('W/""'));
        self::assertTrue(Rules::etag()->test('W/"v1.2.3"'));
    }

    public function testEtagFailsMalformedValues(): void
    {
        self::assertFalse(Rules::etag()->test('abc'));           // missing quotes
        self::assertFalse(Rules::etag()->test('"abc'));          // unclosed quote
        self::assertFalse(Rules::etag()->test('abc"'));          // no opening quote
        self::assertFalse(Rules::etag()->test('w/"abc"'));       // lowercase w is invalid
        self::assertFalse(Rules::etag()->test('W/abc'));         // weak without quotes
        self::assertFalse(Rules::etag()->test('"ab"c"'));        // quote inside opaque tag
        self::assertFalse(Rules::etag()->test('"abc" '));        // trailing space
        self::assertFalse(Rules::etag()->test(''));
        self::assertFalse(Rules::etag()->test(null));
        self::assertFalse(Rules::etag()->test(42));
    }

    public function testEtagDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP ETag.', Rules::etag()->errorMessage());
    }

    // ---- ifNoneMatch ----

    public function testIfNoneMatchPassesWildcardAndEtagLists(): void
    {
        self::assertTrue(Rules::ifNoneMatch()->test('*'));
        self::assertTrue(Rules::ifNoneMatch()->test('"abc"'));
        self::assertTrue(Rules::ifNoneMatch()->test('W/"abc"'));
        self::assertTrue(Rules::ifNoneMatch()->test('"abc", "def"'));
        self::assertTrue(Rules::ifNoneMatch()->test('W/"abc", "def", W/"ghi"'));
    }

    public function testIfNoneMatchFailsMalformedValues(): void
    {
        self::assertFalse(Rules::ifNoneMatch()->test(''));
        self::assertFalse(Rules::ifNoneMatch()->test('*, "abc"'));
        self::assertFalse(Rules::ifNoneMatch()->test('"abc",'));
        self::assertFalse(Rules::ifNoneMatch()->test(',"abc"'));
        self::assertFalse(Rules::ifNoneMatch()->test('abc'));
        self::assertFalse(Rules::ifNoneMatch()->test(null));
    }

    public function testIfNoneMatchDefaultMessage(): void
    {
        self::assertSame('Must be a valid If-None-Match header.', Rules::ifNoneMatch()->errorMessage());
    }

    // ---- ifMatch ----

    public function testIfMatchPassesWildcardAndEtagLists(): void
    {
        self::assertTrue(Rules::ifMatch()->test('*'));
        self::assertTrue(Rules::ifMatch()->test('"abc"'));
        self::assertTrue(Rules::ifMatch()->test('W/"abc"'));
        self::assertTrue(Rules::ifMatch()->test('"abc", "def"'));
        self::assertTrue(Rules::ifMatch()->test('W/"abc", "def", W/"ghi"'));
    }

    public function testIfMatchFailsMalformedValues(): void
    {
        self::assertFalse(Rules::ifMatch()->test(''));
        self::assertFalse(Rules::ifMatch()->test('*, "abc"'));
        self::assertFalse(Rules::ifMatch()->test('"abc",'));
        self::assertFalse(Rules::ifMatch()->test(',"abc"'));
        self::assertFalse(Rules::ifMatch()->test('abc'));
        self::assertFalse(Rules::ifMatch()->test(null));
    }

    public function testIfMatchDefaultMessage(): void
    {
        self::assertSame('Must be a valid If-Match header.', Rules::ifMatch()->errorMessage());
    }

    // ---- httpDate ----

    public function testHttpDatePassesValidImfFixdate(): void
    {
        self::assertTrue(Rules::httpDate()->test('Mon, 23 Feb 2026 20:31:00 GMT'));
        self::assertTrue(Rules::httpDate()->test('Sun, 01 Mar 2026 00:00:00 GMT'));
    }

    public function testHttpDateFailsMalformedValues(): void
    {
        self::assertFalse(Rules::httpDate()->test(''));
        self::assertFalse(Rules::httpDate()->test('Mon, 23 Feb 2026 20:31:00 UTC'));
        self::assertFalse(Rules::httpDate()->test('2026-02-23T20:31:00Z'));
        self::assertFalse(Rules::httpDate()->test('Mon, 32 Feb 2026 20:31:00 GMT'));
        self::assertFalse(Rules::httpDate()->test(null));
    }

    public function testHttpDateDefaultMessage(): void
    {
        self::assertSame('Must be a valid HTTP date.', Rules::httpDate()->errorMessage());
    }

    // ---- ifModifiedSince ----

    public function testIfModifiedSincePassesValidHttpDate(): void
    {
        self::assertTrue(Rules::ifModifiedSince()->test('Mon, 23 Feb 2026 20:31:00 GMT'));
    }

    public function testIfModifiedSinceFailsMalformedValues(): void
    {
        self::assertFalse(Rules::ifModifiedSince()->test('Mon, 23 Feb 2026 20:31:00 UTC'));
        self::assertFalse(Rules::ifModifiedSince()->test('2026-02-23T20:31:00Z'));
        self::assertFalse(Rules::ifModifiedSince()->test(null));
    }

    public function testIfModifiedSinceDefaultMessage(): void
    {
        self::assertSame(
            'Must be a valid If-Modified-Since header.',
            Rules::ifModifiedSince()->errorMessage(),
        );
    }

    // ---- ifUnmodifiedSince ----

    public function testIfUnmodifiedSincePassesValidHttpDate(): void
    {
        self::assertTrue(Rules::ifUnmodifiedSince()->test('Mon, 23 Feb 2026 20:31:00 GMT'));
    }

    public function testIfUnmodifiedSinceFailsMalformedValues(): void
    {
        self::assertFalse(Rules::ifUnmodifiedSince()->test('Mon, 23 Feb 2026 20:31:00 UTC'));
        self::assertFalse(Rules::ifUnmodifiedSince()->test('2026-02-23T20:31:00Z'));
        self::assertFalse(Rules::ifUnmodifiedSince()->test(null));
    }

    public function testIfUnmodifiedSinceDefaultMessage(): void
    {
        self::assertSame(
            'Must be a valid If-Unmodified-Since header.',
            Rules::ifUnmodifiedSince()->errorMessage(),
        );
    }

    // ---- ifRange ----

    public function testIfRangePassesHttpDateOrSingleEtag(): void
    {
        self::assertTrue(Rules::ifRange()->test('Mon, 23 Feb 2026 20:31:00 GMT'));
        self::assertTrue(Rules::ifRange()->test('"abc"'));
        self::assertTrue(Rules::ifRange()->test('W/"abc"'));
    }

    public function testIfRangeFailsMalformedValues(): void
    {
        self::assertFalse(Rules::ifRange()->test(''));
        self::assertFalse(Rules::ifRange()->test('Mon, 23 Feb 2026 20:31:00 UTC'));
        self::assertFalse(Rules::ifRange()->test('"abc", "def"'));
        self::assertFalse(Rules::ifRange()->test('*'));
        self::assertFalse(Rules::ifRange()->test(null));
    }

    public function testIfRangeDefaultMessage(): void
    {
        self::assertSame('Must be a valid If-Range header.', Rules::ifRange()->errorMessage());
    }

    // ---- byteRange ----

    public function testByteRangePassesValidForms(): void
    {
        self::assertTrue(Rules::byteRange()->test('bytes=0-499'));
        self::assertTrue(Rules::byteRange()->test('bytes=500-'));
        self::assertTrue(Rules::byteRange()->test('bytes=-500'));
        self::assertTrue(Rules::byteRange()->test('bytes=0-0,500-999'));
    }

    public function testByteRangeFailsMalformedValues(): void
    {
        self::assertFalse(Rules::byteRange()->test(''));
        self::assertFalse(Rules::byteRange()->test('items=0-1'));
        self::assertFalse(Rules::byteRange()->test('bytes=500-0'));
        self::assertFalse(Rules::byteRange()->test('bytes=0-1,'));
        self::assertFalse(Rules::byteRange()->test('bytes=abc-def'));
        self::assertFalse(Rules::byteRange()->test(null));
    }

    public function testByteRangeDefaultMessage(): void
    {
        self::assertSame('Must be a valid byte range header.', Rules::byteRange()->errorMessage());
    }

    // ---- contentRange ----

    public function testContentRangePassesValidForms(): void
    {
        self::assertTrue(Rules::contentRange()->test('bytes 0-499/1234'));
        self::assertTrue(Rules::contentRange()->test('bytes 500-999/*'));
        self::assertTrue(Rules::contentRange()->test('bytes */1234'));
    }

    public function testContentRangeFailsMalformedValues(): void
    {
        self::assertFalse(Rules::contentRange()->test(''));
        self::assertFalse(Rules::contentRange()->test('items 0-499/1234'));
        self::assertFalse(Rules::contentRange()->test('bytes 500-0/1234'));
        self::assertFalse(Rules::contentRange()->test('bytes */*'));
        self::assertFalse(Rules::contentRange()->test('bytes 0-499'));
        self::assertFalse(Rules::contentRange()->test(null));
    }

    public function testContentRangeDefaultMessage(): void
    {
        self::assertSame('Must be a valid Content-Range header.', Rules::contentRange()->errorMessage());
    }
}
