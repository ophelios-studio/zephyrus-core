<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Security\SecureHeadersConfig;

final class SecureHeadersConfigTest extends TestCase
{
    // ── defaults() ───────────────────────────────────────────────────────────

    public function testDefaultsXFrameOptions(): void
    {
        self::assertSame('SAMEORIGIN', SecureHeadersConfig::defaults()->xFrameOptions);
    }

    public function testDefaultsXContentTypeOptions(): void
    {
        self::assertSame('nosniff', SecureHeadersConfig::defaults()->xContentTypeOptions);
    }

    public function testDefaultsReferrerPolicy(): void
    {
        self::assertSame('strict-origin-when-cross-origin', SecureHeadersConfig::defaults()->referrerPolicy);
    }

    public function testDefaultsXssProtection(): void
    {
        self::assertSame('0', SecureHeadersConfig::defaults()->xssProtection);
    }

    public function testDefaultsHstsDisabled(): void
    {
        $config = SecureHeadersConfig::defaults();
        self::assertSame(0, $config->hstsMaxAge);
        self::assertFalse($config->hstsIncludeSubdomains);
    }

    public function testDefaultsCspEmpty(): void
    {
        self::assertSame('', SecureHeadersConfig::defaults()->csp);
    }

    public function testDefaultsPermissionsPolicyEmpty(): void
    {
        self::assertSame('', SecureHeadersConfig::defaults()->permissionsPolicy);
    }

    // ── fromArray(): camelCase keys ─────────────────────────────────────────

    public function testFromArrayCamelCaseKeys(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'xFrameOptions'       => 'DENY',
            'xContentTypeOptions' => 'nosniff',
            'referrerPolicy'      => 'no-referrer',
            'xssProtection'       => '1; mode=block',
            'hstsMaxAge'          => 31_536_000,
            'hstsIncludeSubdomains' => true,
            'csp'                 => "default-src 'self'",
            'permissionsPolicy'   => 'camera=()',
        ]);

        self::assertSame('DENY', $config->xFrameOptions);
        self::assertSame('nosniff', $config->xContentTypeOptions);
        self::assertSame('no-referrer', $config->referrerPolicy);
        self::assertSame('1; mode=block', $config->xssProtection);
        self::assertSame(31_536_000, $config->hstsMaxAge);
        self::assertTrue($config->hstsIncludeSubdomains);
        self::assertSame("default-src 'self'", $config->csp);
        self::assertSame('camera=()', $config->permissionsPolicy);
    }

    // ── fromArray(): snake_case keys ────────────────────────────────────────

    public function testFromArraySnakeCaseKeys(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'x_frame_options'         => 'DENY',
            'x_content_type_options'  => 'nosniff',
            'referrer_policy'         => 'no-referrer',
            'xss_protection'          => '1',
            'hsts_max_age'            => 86_400,
            'hsts_include_subdomains' => true,
            'permissions_policy'      => 'microphone=()',
        ]);

        self::assertSame('DENY', $config->xFrameOptions);
        self::assertSame('nosniff', $config->xContentTypeOptions);
        self::assertSame('no-referrer', $config->referrerPolicy);
        self::assertSame('1', $config->xssProtection);
        self::assertSame(86_400, $config->hstsMaxAge);
        self::assertTrue($config->hstsIncludeSubdomains);
        self::assertSame('microphone=()', $config->permissionsPolicy);
    }

    public function testFromArrayCamelCaseTakesPrecedenceOverSnakeCase(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'xFrameOptions' => 'DENY',
            'x_frame_options' => 'SAMEORIGIN',
        ]);

        self::assertSame('DENY', $config->xFrameOptions);
    }

    public function testFromArrayMissingKeysUsesDefaults(): void
    {
        $config = SecureHeadersConfig::fromArray([]);
        $defaults = SecureHeadersConfig::defaults();

        self::assertSame($defaults->xFrameOptions, $config->xFrameOptions);
        self::assertSame($defaults->xContentTypeOptions, $config->xContentTypeOptions);
        self::assertSame($defaults->referrerPolicy, $config->referrerPolicy);
        self::assertSame($defaults->xssProtection, $config->xssProtection);
        self::assertSame($defaults->hstsMaxAge, $config->hstsMaxAge);
        self::assertSame($defaults->hstsIncludeSubdomains, $config->hstsIncludeSubdomains);
        self::assertSame($defaults->csp, $config->csp);
        self::assertSame($defaults->permissionsPolicy, $config->permissionsPolicy);
    }

    public function testFromArrayEmptyStringDisablesHeader(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'xFrameOptions' => '',
            'xContentTypeOptions' => '',
        ]);

        self::assertSame('', $config->xFrameOptions);
        self::assertSame('', $config->xContentTypeOptions);
    }

    // ── hstsHeaderValue() ────────────────────────────────────────────────────

    public function testHstsHeaderValueEmptyWhenDisabled(): void
    {
        self::assertSame('', SecureHeadersConfig::defaults()->hstsHeaderValue());
    }

    public function testHstsHeaderValueWithMaxAgeOnly(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 31_536_000]);
        self::assertSame('max-age=31536000', $config->hstsHeaderValue());
    }

    public function testHstsHeaderValueWithIncludeSubdomains(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'hstsMaxAge'            => 31_536_000,
            'hstsIncludeSubdomains' => true,
        ]);
        self::assertSame('max-age=31536000; includeSubDomains', $config->hstsHeaderValue());
    }

    public function testHstsHeaderValueZeroMaxAgeReturnsEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'hstsMaxAge'            => 0,
            'hstsIncludeSubdomains' => true,
        ]);
        self::assertSame('', $config->hstsHeaderValue());
    }

    public function testHstsIncludeSubdomainsOffReadsAsDisabled(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'hstsMaxAge'            => 31536000,
            'hstsIncludeSubdomains' => 'off',
        ]);

        self::assertFalse($config->hstsIncludeSubdomains);
        self::assertSame('max-age=31536000', $config->hstsHeaderValue());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unreadableIncludeSubdomainsValues(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'unknown word' => ['enabled'],
            'int 2' => [2],
        ];
    }

    #[DataProvider('unreadableIncludeSubdomainsValues')]
    public function testAnUnreadableIncludeSubdomainsValueIsRefused(mixed $value): void
    {
        $this->expectException(ConfigurationException::class);

        SecureHeadersConfig::fromArray(['hstsIncludeSubdomains' => $value]);
    }

    public function testAnUnreadableSnakeCaseIncludeSubdomainsIsRefusedNamingTheKeyWritten(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'hsts_include_subdomains' has invalid value");

        SecureHeadersConfig::fromArray(['hsts_include_subdomains' => 'maybe']);
    }

    public function testADeclaredNullIncludeSubdomainsIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/field 'hstsIncludeSubdomains'/");

        SecureHeadersConfig::fromArray(['hstsIncludeSubdomains' => null]);
    }

    public function testANullCamelCaseIncludeSubdomainsIsNotRescuedByTheSnakeCaseSpelling(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/field 'hstsIncludeSubdomains'/");

        SecureHeadersConfig::fromArray(['hstsIncludeSubdomains' => null, 'hsts_include_subdomains' => true]);
    }

    /** @return array<string, array{string}> */
    public static function controlCharacterValues(): array
    {
        return [
            'vertical tab' => ["DENY\x0bX"],
            'start of heading' => ["DENY\x01"],
            'form feed' => ["DENY\x0c"],
            'delete' => ["DENY\x7f"],
        ];
    }

    #[DataProvider('controlCharacterValues')]
    public function testAHeaderValueWithAControlCharacterIsRefused(string $value): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/field 'xFrameOptions'/");

        SecureHeadersConfig::fromArray(['xFrameOptions' => $value]);
    }

    public function testAHeaderValueWithAHorizontalTabIsAccepted(): void
    {
        self::assertSame("a\tb", SecureHeadersConfig::fromArray(['permissionsPolicy' => "a\tb"])->permissionsPolicy);
    }

    public function testEverySpellingTheUnknownKeyCheckAcceptsIsRead(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'x_frame_options' => 'a', 'x_content_type_options' => 'b', 'referrer_policy' => 'c',
            'xss_protection' => 'd', 'hsts_max_age' => 5, 'hsts_include_subdomains' => true,
            'csp' => 'e', 'permissions_policy' => 'f',
        ]);

        self::assertSame(['a', 'b', 'c', 'd', 5, true, 'e', 'f'], [
            $config->xFrameOptions, $config->xContentTypeOptions, $config->referrerPolicy,
            $config->xssProtection, $config->hstsMaxAge, $config->hstsIncludeSubdomains,
            $config->csp, $config->permissionsPolicy,
        ]);
    }

    public function testTheUnknownKeyRefusalListsTheCamelCaseNamesOnly(): void
    {
        try {
            SecureHeadersConfig::fromArray(['bogus' => 'x']);

            self::fail('An unknown key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'xFrameOptions, xContentTypeOptions, referrerPolicy, xssProtection, hstsMaxAge, '
                . 'hstsIncludeSubdomains, csp, permissionsPolicy',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('x_frame_options', $exception->getMessage());
        }
    }

    public function testANullStringHeaderRefusalSaysHowToOmitTheHeader(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/; use '' to omit the header\\./");

        SecureHeadersConfig::fromArray(['csp' => null]);
    }

    public function testAFloatHstsMaxAgeIsShownWithItsDecimalForm(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("invalid value '1.0'");

        SecureHeadersConfig::fromArray(['hstsMaxAge' => 1.0]);
    }

    // ── fromArray(): refusals ───────────────────────────────────────────────

    public function testAMisspelledKeyIsRefusedNamingTheKeyAndTheAcceptedOnes(): void
    {
        try {
            SecureHeadersConfig::fromArray(['contentSecurityPolicy' => "default-src 'self'"]);

            self::fail('A misspelled key was accepted and would silently leave the policy off.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("field 'contentSecurityPolicy'", $exception->getMessage());
            self::assertStringContainsString('security.headers', $exception->getMessage());
            self::assertStringContainsString('csp', $exception->getMessage());
        }
    }

    public function testAnUnknownSnakeCaseKeyIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/field 'frame_options'.*unknown key/");

        SecureHeadersConfig::fromArray(['frame_options' => 'DENY']);
    }

    public function testAKeyWithNulBytesIsShownEscapedInTheRefusal(): void
    {
        try {
            SecureHeadersConfig::fromArray(["csp\0x" => "default-src 'self'"]);

            self::fail('An unknown key containing a NUL byte was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString("\0", $exception->getMessage());
        }
    }

    /** @return array<string, array{string, mixed}> */
    public static function nonScalarStringHeaders(): array
    {
        return [
            'csp as array' => ['csp', ["default-src 'self'"]],
            'xFrameOptions as array' => ['xFrameOptions', ['DENY']],
            'permissions_policy as object' => ['permissions_policy', new \stdClass()],
            'referrerPolicy as nested array' => ['referrerPolicy', ['a' => ['b']]],
            'csp declared null' => ['csp', null],
            'xssProtection declared null' => ['xssProtection', null],
        ];
    }

    #[DataProvider('nonScalarStringHeaders')]
    public function testANonScalarOrNullHeaderIsRefusedNamingTheKey(string $key, mixed $value): void
    {
        try {
            SecureHeadersConfig::fromArray([$key => $value]);

            self::fail("The value written under '$key' was accepted.");
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("field '$key'", $exception->getMessage());
            self::assertStringNotContainsString('Array', $exception->getMessage());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function headerValuesWithLineBreaksOrNul(): array
    {
        return [
            'csp with CRLF' => ['csp', "default-src 'self'\r\nSet-Cookie: x=1"],
            'csp with LF' => ['csp', "default-src 'self'\nX-Injected: 1"],
            'csp with interior CR' => ['csp', "default-src 'self'\rimg-src *"],
            'xFrameOptions with NUL' => ['xFrameOptions', "DENY\0"],
            'permissions_policy with interior LF' => ['permissions_policy', "camera=()\nmicrophone=()"],
        ];
    }

    #[DataProvider('headerValuesWithLineBreaksOrNul')]
    public function testAHeaderValueWithLineBreaksOrNulIsRefused(string $key, string $value): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/field '$key'/");

        SecureHeadersConfig::fromArray([$key => $value]);
    }

    /** @return array<string, array{mixed}> */
    public static function unreadableHstsMaxAgeValues(): array
    {
        return [
            'phrase' => ['1 year'],
            'letters' => ['abc'],
            'float' => [1.5],
            'true' => [true],
            'false' => [false],
            'negative string' => ['-1'],
            'plus sign' => ['+60'],
            'leading space' => [' 60'],
            'empty string' => [''],
            'overflowing digits' => ['99999999999999999999999'],
            'declared null' => [null],
            'array' => [[60]],
        ];
    }

    #[DataProvider('unreadableHstsMaxAgeValues')]
    public function testAnUnreadableHstsMaxAgeIsRefusedNamingTheKey(mixed $value): void
    {
        try {
            SecureHeadersConfig::fromArray(['hstsMaxAge' => $value]);

            self::fail('An hstsMaxAge that is not an integer was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString("field 'hstsMaxAge'", $exception->getMessage());
        }
    }

    /** @return array<string, array{mixed, int}> */
    public static function readableHstsMaxAgeValues(): array
    {
        return [
            'int' => [31_536_000, 31_536_000],
            'digit string' => ['31536000', 31_536_000],
            'zero string' => ['0', 0],
            'leading zeros' => ['0010', 10],
            'snake_case digit string' => [['hsts_max_age' => '86400'], 86_400],
        ];
    }

    #[DataProvider('readableHstsMaxAgeValues')]
    public function testAnIntegerOrDigitStringHstsMaxAgeIsAccepted(mixed $value, int $expected): void
    {
        $values = is_array($value) ? $value : ['hstsMaxAge' => $value];

        self::assertSame($expected, SecureHeadersConfig::fromArray($values)->hstsMaxAge);
    }

    public function testAnIntegerXssProtectionFromYamlIsAccepted(): void
    {
        self::assertSame('0', SecureHeadersConfig::fromArray(['xssProtection' => 0])->xssProtection);
    }

    #[DataProvider('headerFields')]
    public function testConstructorRefusesAControlCharacterInAHeaderField(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SecureHeadersConfig(...$this->withValueIn($field, "DENY\x01"));
    }

    public function testConstructorRefusalNamesTheFieldButNotTheValue(): void
    {
        try {
            new SecureHeadersConfig(...$this->withValueIn('permissionsPolicy', "camera=()\x7F evil.example.com"));
            self::fail('A control character must be refused.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('permissionsPolicy', $exception->getMessage());
            self::assertStringNotContainsString('evil.example.com', $exception->getMessage());
        }
    }

    public function testAYamlFoldedValueEndingWithANewlineIsAcceptedWithoutIt(): void
    {
        $config = SecureHeadersConfig::fromArray(['csp' => "default-src 'self'\n"]);

        self::assertSame("default-src 'self'", $config->csp);
    }

    public function testAControlCharacterStillRefusedInAValueThatEndsWithANewline(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/field 'csp'/");

        SecureHeadersConfig::fromArray(['csp' => "default-src\x01 'self'\n"]);
    }

    public function testATrailingNulIsRefusedAndNotTrimmedAway(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/field 'csp'/");

        SecureHeadersConfig::fromArray(['csp' => "default-src 'self'\0"]);
    }

    public function testConstructorAcceptsHorizontalTabInAHeaderField(): void
    {
        $config = new SecureHeadersConfig(...$this->withValueIn('csp', "default-src 'self'\t"));

        self::assertSame("default-src 'self'\t", $config->csp);
    }

    /** @return array<string, mixed> */
    private function withValueIn(string $field, string $value): array
    {
        $arguments = [
            'xFrameOptions' => 'SAMEORIGIN',
            'xContentTypeOptions' => 'nosniff',
            'referrerPolicy' => 'strict-origin-when-cross-origin',
            'xssProtection' => '0',
            'hstsMaxAge' => 0,
            'hstsIncludeSubdomains' => false,
            'csp' => '',
            'permissionsPolicy' => '',
        ];
        $arguments[$field] = $value;

        return $arguments;
    }

    public static function headerFields(): iterable
    {
        yield 'xFrameOptions' => ['xFrameOptions'];
        yield 'xContentTypeOptions' => ['xContentTypeOptions'];
        yield 'referrerPolicy' => ['referrerPolicy'];
        yield 'xssProtection' => ['xssProtection'];
        yield 'csp' => ['csp'];
        yield 'permissionsPolicy' => ['permissionsPolicy'];
    }
}
