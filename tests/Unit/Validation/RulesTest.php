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
}
