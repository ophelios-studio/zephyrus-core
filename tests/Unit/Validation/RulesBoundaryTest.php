<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Validation\Rules;

final class RulesBoundaryTest extends TestCase
{
    /**
     * @param callable(): \Zephyrus\Validation\Rule $rule
     */
    private static function assertVerdict(callable $rule, mixed $value, bool $expected): void
    {
        self::assertSame($expected, $rule()->test($value));
    }

    public function testMinAndMaxDefaultMessagesQuoteTheBound(): void
    {
        self::assertSame('Must be at least 5.', Rules::min(5)->errorMessage());
        self::assertSame('Must be at most 9.', Rules::max(9)->errorMessage());
    }

    public function testMinAndMaxKeepACustomMessage(): void
    {
        self::assertSame('Too small.', Rules::min(5, 'Too small.')->errorMessage());
        self::assertSame('Too big.', Rules::max(9, 'Too big.')->errorMessage());
    }

    public function testDecimalStringDefaultsToTwoDecimalPlaces(): void
    {
        self::assertTrue(Rules::decimalString()->test('1.23'));
        self::assertFalse(Rules::decimalString()->test('1.234'));
    }

    public function testHttpUrlRefusesAStringableObject(): void
    {
        $url = new class {
            public function __toString(): string
            {
                return 'http://example.com';
            }
        };

        self::assertFalse(Rules::httpUrl()->test($url));
    }

    public function testHttpUrlRefusesWhatTheUrlFilterRefuses(): void
    {
        self::assertFalse(Rules::httpUrl()->test('http://exa mple.com'));
    }

    public function testHttpUrlAcceptsAnUpperCaseScheme(): void
    {
        self::assertTrue(Rules::httpUrl()->test('HTTPS://example.com'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function uuidShapes(): array
    {
        return [
            'lower case' => ['123e4567-e89b-42d3-a456-426614174000', true],
            'upper case' => ['123E4567-E89B-42D3-A456-426614174000', true],
            'leading junk' => ['x123e4567-e89b-42d3-a456-426614174000', false],
            'trailing junk' => ['123e4567-e89b-42d3-a456-426614174000x', false],
        ];
    }

    #[DataProvider('uuidShapes')]
    public function testUuidIsAnchoredAtBothEnds(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::uuid(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function versionedUuids(): array
    {
        $cases = [];
        foreach ([
            'uuidV1toV5' => '1',
            'uuidV4' => '4',
            'uuidV6' => '6',
            'uuidV7' => '7',
            'uuidV8' => '8',
        ] as $rule => $version) {
            $uuid = "123e4567-e89b-{$version}2d3-a456-426614174000";
            $cases["{$rule} accepts its own version"] = [$rule, $uuid, true];
            $cases["{$rule} accepts upper case"] = [$rule, strtoupper($uuid), true];
            $cases["{$rule} refuses leading junk"] = [$rule, 'x' . $uuid, false];
            $cases["{$rule} refuses trailing junk"] = [$rule, $uuid . 'x', false];
        }
        return $cases;
    }

    #[DataProvider('versionedUuids')]
    public function testVersionedUuidRulesAreAnchoredAndCaseInsensitive(string $rule, string $value, bool $expected): void
    {
        self::assertSame($expected, Rules::$rule()->test($value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function macAddresses(): array
    {
        return [
            'colons' => ['00:1A:2b:3C:4d:5E', true],
            'hyphens' => ['00-1A-2b-3C-4d-5E', true],
            'leading junk' => ['zz00:1A:2b:3C:4d:5E', false],
            'trailing junk' => ['00:1A:2b:3C:4d:5Ez', false],
        ];
    }

    #[DataProvider('macAddresses')]
    public function testMacAddressIsAnchoredAtBothEnds(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::macAddress(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function hostnames(): array
    {
        $label63 = str_repeat('a', 63);
        return [
            'upper case letters' => ['Example.COM', true],
            'whole name of 253 characters' => ["{$label63}.{$label63}.{$label63}." . str_repeat('a', 61), true],
            'whole name of 254 characters' => ["{$label63}.{$label63}.{$label63}." . str_repeat('a', 62), false],
            'label of 63 characters' => [$label63 . '.com', true],
            'label of 64 characters' => [$label63 . 'a.com', false],
            'empty label' => ['a..com', false],
            'leading dot' => ['.example.com', false],
            'trailing dot' => ['example.com.', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('hostnames')]
    public function testHostnameBoundaries(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::hostname(...), $value, $expected);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function ports(): array
    {
        return [
            'int lower bound' => [1, true],
            'int below lower bound' => [0, false],
            'int upper bound' => [65535, true],
            'int above upper bound' => [65536, false],
            'digit string' => ['80', true],
            'string with trailing letters' => ['80a', false],
            'string with leading space' => [' 80', false],
            'string with a sign' => ['+80', false],
            'empty string' => ['', false],
            'float' => [80.0, false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('ports')]
    public function testPortBoundaries(mixed $value, bool $expected): void
    {
        self::assertVerdict(Rules::port(...), $value, $expected);
    }

    public function testSubnetMaskRefusesAStringableObject(): void
    {
        $mask = new class {
            public function __toString(): string
            {
                return '255.255.255.0';
            }
        };

        self::assertFalse(Rules::subnetMask()->test($mask));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function portRanges(): array
    {
        return [
            'ordered' => ['80-90', true],
            'single port range' => ['80-80', true],
            'reversed' => ['90-80', false],
            'three parts' => ['80-90-100', false],
            'out of range end' => ['80-70000', false],
        ];
    }

    #[DataProvider('portRanges')]
    public function testPortRangeBoundaries(mixed $value, bool $expected): void
    {
        self::assertVerdict(Rules::portRange(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function base64UrlValues(): array
    {
        return [
            'length multiple of four' => ['QUJD', true],
            'unpadded remainder' => ['QQ', true],
            'padded form' => ['QQ==', false],
            'standard alphabet' => ['a+bc', false],
        ];
    }

    #[DataProvider('base64UrlValues')]
    public function testBase64UrlBoundaries(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::base64Url(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function pathSegments(): array
    {
        return [
            'plain' => ['file-1_a.txt', true],
            'leading junk' => ['a b', false],
            'question mark' => ['a?b', false],
        ];
    }

    #[DataProvider('pathSegments')]
    public function testPathSegmentIsAnchoredAtTheStart(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::pathSegment(...), $value, $expected);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function httpStatusCodes(): array
    {
        return [
            'lowest' => [100, true],
            'below lowest' => [99, false],
            'highest' => [599, true],
            'above highest' => [600, false],
            'digit string' => ['404', true],
        ];
    }

    #[DataProvider('httpStatusCodes')]
    public function testHttpStatusCodeBoundaries(mixed $value, bool $expected): void
    {
        self::assertVerdict(Rules::httpStatusCode(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function httpVersions(): array
    {
        return [
            'one one' => ['HTTP/1.1', true],
            'two' => ['http/2', true],
            'leading junk' => ['xHTTP/1.1', false],
            'trailing junk' => ['HTTP/1.1x', false],
        ];
    }

    #[DataProvider('httpVersions')]
    public function testHttpVersionIsAnchoredAtBothEnds(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::httpVersion(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function acceptLanguageValues(): array
    {
        return [
            'single tag' => ['en', true],
            'weighted tag' => ['en;q=0.8', true],
            'list with weights and wildcard' => ['fr-CA, en;q=0.5, *;q=0.1', true],
            'weight with spaces' => ['en ; q=1', true],
            'weight above one' => ['en;q=1.5', false],
            'weight with four decimals' => ['en;q=0.1234', false],
            'weight without value' => ['en;q=', false],
            'blank' => ['  ', false],
        ];
    }

    #[DataProvider('acceptLanguageValues')]
    public function testAcceptLanguageBoundaries(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::acceptLanguage(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function hostPorts(): array
    {
        return [
            'host and port' => ['example.com:443', true],
            'bracketed ipv6' => ['[::1]:80', true],
            'bracketed invalid ipv6' => ['[bad]:80', false],
            'bracketed ipv6 with invalid port' => ['[::1]:99999', false],
            'bracketed ipv6 without port' => ['[::1]', false],
        ];
    }

    #[DataProvider('hostPorts')]
    public function testHostPortRequiresBothHalves(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::hostPort(...), $value, $expected);
    }

    public function testIbanAcceptsLowerCaseInput(): void
    {
        self::assertTrue(Rules::iban()->test('gb82 west 1234 5698 7654 32'));
        self::assertFalse(Rules::iban()->test('!GB82WEST12345698765432'));
        self::assertFalse(Rules::iban()->test('GB82WEST12345698765432!'));
    }

    public function testBicAcceptsLowerCaseInputAndRefusesJunk(): void
    {
        self::assertTrue(Rules::bic()->test('deutdeff'));
        self::assertTrue(Rules::bic()->test('DEUTDEFF500'));
        self::assertFalse(Rules::bic()->test('1DEUTDEFF'));
        self::assertFalse(Rules::bic()->test('DEUTDEFF5'));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function cardNumbers(): array
    {
        return [
            'twelve digits' => ['411111111111', true],
            'nineteen digits' => ['4111111111111111111', true],
            'eleven digits' => ['41111111111', false],
            'twenty digits' => ['41111111111111111111', false],
            'spaces and hyphens are ignored' => ['4111 1111-1111 1111', true],
            'leading junk' => ['x4111111111111111', false],
            'trailing junk' => ['4111111111111111x', false],
        ];
    }

    #[DataProvider('cardNumbers')]
    public function testCardNumberShape(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::cardNumber(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function luhnCardNumbers(): array
    {
        return [
            'valid' => ['4111111111111111', true],
            'valid with doubled digits above nine' => ['4012888888881881', true],
            'valid with a single doubled digit above nine' => ['000000000059', true],
            'valid with spaces' => ['4012 8888 8888 1881', true],
            'wrong check digit' => ['4012888888881882', false],
            'letters only' => ['abcdefghijklm', false],
            'too short' => ['00000000000', false],
            'leading junk' => ['x4111111111111111', false],
            'trailing junk' => ['4111111111111111x', false],
            'twenty digits' => ['00000000000000000000', false],
        ];
    }

    #[DataProvider('luhnCardNumbers')]
    public function testCardNumberLuhnBoundaries(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::cardNumberLuhn(...), $value, $expected);
    }

    public function testInCaseInsensitiveFoldsMultibyteAllowedValues(): void
    {
        $rule = Rules::inCaseInsensitive(['Éclair']);

        self::assertTrue($rule->test('éCLAIR'));
        self::assertFalse($rule->test('eclair'));
    }

    /**
     * @return array<string, array{string, mixed, bool}>
     */
    public static function geoCoordinates(): array
    {
        return [
            'latitude lower bound' => ['latitude', -90, true],
            'latitude upper bound' => ['latitude', '90', true],
            'latitude above' => ['latitude', 90.5, false],
            'latitude boolean' => ['latitude', true, false],
            'latitude punctuation' => ['latitude', '.', false],
            'longitude lower bound' => ['longitude', -180, true],
            'longitude upper bound' => ['longitude', '180', true],
            'longitude above' => ['longitude', 180.5, false],
            'longitude boolean' => ['longitude', true, false],
            'longitude punctuation' => ['longitude', '.', false],
        ];
    }

    #[DataProvider('geoCoordinates')]
    public function testCoordinatesAcceptOnlyNumbersInRange(string $rule, mixed $value, bool $expected): void
    {
        self::assertSame($expected, Rules::$rule()->test($value));
    }

    /**
     * @return array<string, array{string, mixed, bool}>
     */
    public static function epochs(): array
    {
        return [
            'timestamp zero' => ['unixTimestamp', 0, true],
            'timestamp negative' => ['unixTimestamp', -1, false],
            'milliseconds zero' => ['epochMilliseconds', '0', true],
            'milliseconds negative' => ['epochMilliseconds', -1, false],
        ];
    }

    #[DataProvider('epochs')]
    public function testEpochsStartAtZero(string $rule, mixed $value, bool $expected): void
    {
        self::assertSame($expected, Rules::$rule()->test($value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function byteRanges(): array
    {
        return [
            'closed, open and suffix ranges' => ['bytes=0-4,10-,-5', true],
            'reversed' => ['bytes=5-4', false],
            'open range with leading junk' => ['bytes=x5-', false],
            'open range with trailing newline' => ["bytes=5-\n", false],
            'suffix range with trailing junk' => ['bytes=-5x', false],
            'suffix range with trailing newline' => ["bytes=-5\n", false],
            'closed range with trailing newline' => ["bytes=5-10\n", false],
            'bad range after an open range' => ['bytes=5-,abc', false],
            'bad range after a suffix range' => ['bytes=-5,abc', false],
        ];
    }

    #[DataProvider('byteRanges')]
    public function testByteRangeBoundaries(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::byteRange(...), $value, $expected);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function contentRanges(): array
    {
        return [
            'unsatisfied range' => ['bytes */5', true],
            'unsatisfied range with leading junk' => ['bytes x*/5', false],
            'unsatisfied range with trailing junk' => ['bytes */5x', false],
            'unsatisfied range with trailing newline' => ["bytes */5\n", false],
            'closed range' => ['bytes 0-4/5', true],
            'closed range with leading junk' => ['bytes x0-4/5', false],
            'end beyond the size' => ['bytes 0-5/5', false],
            'empty resource' => ['bytes 0-0/0', false],
        ];
    }

    #[DataProvider('contentRanges')]
    public function testContentRangeBoundaries(string $value, bool $expected): void
    {
        self::assertVerdict(Rules::contentRange(...), $value, $expected);
    }
}
