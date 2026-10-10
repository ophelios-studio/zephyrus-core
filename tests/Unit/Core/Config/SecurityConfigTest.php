<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\SecurityConfig;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Http\Request;
use Zephyrus\Security\SecureHeadersConfig;

final class SecurityConfigTest extends TestCase
{
    public function testBuildsWithDefaults(): void
    {
        $config = SecurityConfig::fromArray([]);

        self::assertFalse($config->forceHttps);
        self::assertTrue($config->csrfEnabled);
        self::assertFalse($config->csrfAutoHtml);
        self::assertSame([], $config->csrfExceptions);
        self::assertSame([], $config->allowedHosts);
        self::assertSame(2_097_152, $config->maxBodySize);
        self::assertNull($config->encryptionKey);
    }

    public function testAcceptsCamelCaseKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'forceHttps' => true,
            'csrfEnabled' => false,
            'csrfAutoHtml' => false,
            'csrfExceptions' => ['#^/webhooks/#'],
            'allowedHosts' => ['example.com', 'api.example.com'],
            'maxBodySize' => 1_048_576,
        ]);

        self::assertTrue($config->forceHttps);
        self::assertFalse($config->csrfEnabled);
        self::assertFalse($config->csrfAutoHtml);
        self::assertSame(['#^/webhooks/#'], $config->csrfExceptions);
        self::assertSame(['example.com', 'api.example.com'], $config->allowedHosts);
        self::assertSame(1_048_576, $config->maxBodySize);
    }

    public function testAcceptsSnakeCaseKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'force_https' => true,
            'csrf_enabled' => false,
            'csrf_auto_html' => false,
            'csrf_exceptions' => ['#^/hooks/#'],
            'allowed_hosts' => ['app.local'],
            'max_body_size' => 512,
        ]);

        self::assertTrue($config->forceHttps);
        self::assertFalse($config->csrfEnabled);
        self::assertFalse($config->csrfAutoHtml);
        self::assertSame(['#^/hooks/#'], $config->csrfExceptions);
        self::assertSame(['app.local'], $config->allowedHosts);
        self::assertSame(512, $config->maxBodySize);
    }

    public function testCamelCaseTakesPrecedenceOverSnakeCase(): void
    {
        $config = SecurityConfig::fromArray([
            'forceHttps' => true,
            'force_https' => false,
            'csrfAutoHtml' => false,
            'csrf_auto_html' => true,
            'csrfExceptions' => ['#^/camel/#'],
            'csrf_exceptions' => ['#^/snake/#'],
        ]);

        self::assertTrue($config->forceHttps);
        self::assertFalse($config->csrfAutoHtml);
        self::assertSame(['#^/camel/#'], $config->csrfExceptions);
    }

    public function testMaxBodySizeZeroIsUnlimited(): void
    {
        $config = SecurityConfig::fromArray(['maxBodySize' => 0]);

        self::assertSame(0, $config->maxBodySize);
    }

    public function testAllowedHostsAreReindexed(): void
    {
        $config = SecurityConfig::fromArray([
            'allowedHosts' => [5 => 'a.test', 10 => 'b.test'],
        ]);

        self::assertSame(['a.test', 'b.test'], $config->allowedHosts);
    }

    public function testCsrfExceptionsAreReindexed(): void
    {
        $config = SecurityConfig::fromArray([
            'csrfExceptions' => [5 => '#^/a$#', 10 => '#^/b$#'],
        ]);

        self::assertSame(['#^/a$#', '#^/b$#'], $config->csrfExceptions);
    }

    public function testThrowsForNegativeMaxBodySize(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('maxBodySize');

        SecurityConfig::fromArray(['maxBodySize' => -1]);
    }

    public function testAcceptsMaxBodySizeAsDigitString(): void
    {
        self::assertSame(1024, SecurityConfig::fromArray(['maxBodySize' => '1024'])->maxBodySize);
        self::assertSame(0, SecurityConfig::fromArray(['maxBodySize' => '0'])->maxBodySize);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidMaxBodySizes(): iterable
    {
        yield 'unit suffix' => ['2MB'];
        yield 'letters' => ['abc'];
        yield 'leading space' => [' 2'];
        yield 'negative string' => ['-1'];
        yield 'trailing newline' => ["2\n"];
        yield 'empty string' => [''];
        yield 'float' => [1.5];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'array' => [[2]];
        yield '19 digits' => ['1000000000000000000'];
        yield '310 digits' => ['1' . str_repeat('0', 309)];
    }

    public function testAcceptsMaxBodySizeOfEighteenDigits(): void
    {
        self::assertSame(
            999_999_999_999_999_999,
            SecurityConfig::fromArray(['maxBodySize' => '999999999999999999'])->maxBodySize,
        );
    }

    #[DataProvider('invalidMaxBodySizes')]
    public function testThrowsForMaxBodySizeThatIsNotAByteCount(mixed $value): void
    {
        try {
            SecurityConfig::fromArray(['maxBodySize' => $value]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString("'security' field 'maxBodySize'", $e->getMessage());
            self::assertStringContainsString(
                'must be a number of bytes, for example 2097152 (no unit suffix)',
                $e->getMessage(),
            );
        }
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function refusedSnakeCaseMaxBodySizes(): iterable
    {
        yield 'unit suffix' => ['8MB', '"8MB"'];
        yield 'bool' => [true, 'true'];
        yield 'float' => [1e7, '10000000.0'];
        yield 'array' => [[1], 'array'];
        yield 'negative integer' => [-5, '-5'];
    }

    #[DataProvider('refusedSnakeCaseMaxBodySizes')]
    public function testMaxBodySizeRefusalNamesTheKeyAsWrittenAndShowsTheValue(mixed $value, string $shown): void
    {
        try {
            SecurityConfig::fromArray(['max_body_size' => $value]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString("'security' field 'max_body_size' has invalid value " . $shown . ': ', $e->getMessage());
        }
    }

    public function testThrowsForEmptyStringInAllowedHosts(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('allowedHosts');

        SecurityConfig::fromArray(['allowedHosts' => ['valid.com', '']]);
    }

    public function testThrowsForNonStringInAllowedHosts(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['allowedHosts' => [42]]);
    }

    public function testThrowsForEmptyStringInCsrfExceptions(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('csrfExceptions');

        SecurityConfig::fromArray(['csrfExceptions' => ['#^/ok$#', '']]);
    }

    public function testAnUnquotedYamlPatternStartingWithAHashIsRefusedWithAQuotingHint(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'zephyrus-yaml-');
        self::assertIsString($file);

        try {
            file_put_contents($file, "security:\n  csrf:\n    exceptions:\n      - #^/webhooks/#\n");
            Configuration::fromYamlFile($file);

            self::fail('An empty csrf exception entry was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'security' field 'csrfExceptions[0]' has invalid value null: "
                . 'each entry must be a non-empty string; quote a pattern that starts with "#" in YAML.',
                $exception->getMessage(),
            );
        } finally {
            unlink($file);
        }
    }

    public function testAQuotedYamlPatternStartingWithAHashIsAccepted(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'zephyrus-yaml-');
        self::assertIsString($file);

        try {
            file_put_contents($file, "security:\n  csrf:\n    exceptions:\n      - \"#^/webhooks/#\"\n");
            $configuration = Configuration::fromYamlFile($file);
        } finally {
            unlink($file);
        }

        self::assertSame(['#^/webhooks/#'], $configuration->security->csrfExceptions);
    }

    public function testAnEmptyStringCsrfExceptionEntryGetsNoQuotingHint(): void
    {
        try {
            SecurityConfig::fromArray(['csrfExceptions' => ['']]);

            self::fail('An empty csrf exception entry was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'security' field 'csrfExceptions[0]' has invalid value \"\": "
                . 'each entry must be a non-empty string.',
                $exception->getMessage(),
            );
        }
    }

    public function testANonStringCsrfExceptionEntryGetsNoQuotingHint(): void
    {
        try {
            SecurityConfig::fromArray(['csrfExceptions' => [123]]);

            self::fail('A numeric csrf exception entry was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringNotContainsString('quote a pattern', $exception->getMessage());
        }
    }

    public function testThrowsForNonStringInCsrfExceptions(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['csrfExceptions' => [123]]);
    }

    public function testTrustedProxiesDefaultsToEmpty(): void
    {
        $config = SecurityConfig::fromArray([]);
        self::assertSame([], $config->trustedProxies);
    }

    public function testTrustedProxiesAcceptsCamelCase(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedProxies' => ['127.0.0.1', '10.0.0.0/8'],
        ]);

        self::assertSame(['127.0.0.1', '10.0.0.0/8'], $config->trustedProxies);
    }

    public function testTrustedProxiesAcceptsSnakeCase(): void
    {
        $config = SecurityConfig::fromArray([
            'trusted_proxies' => ['127.0.0.1'],
        ]);

        self::assertSame(['127.0.0.1'], $config->trustedProxies);
    }

    public function testTrustedProxiesWildcard(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedProxies' => ['*'],
        ]);

        self::assertSame(['*'], $config->trustedProxies);
    }

    public function testThrowsForEmptyStringInTrustedProxies(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('trustedProxies');

        SecurityConfig::fromArray(['trustedProxies' => ['127.0.0.1', '']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTrustedProxyEntries(): iterable
    {
        yield 'prefix above ipv4 width' => ['10.0.0.0/33'];
        yield 'prefix above ipv6 width' => ['::/129'];
        yield 'negative prefix' => ['10.0.0.0/-1'];
        yield 'alphabetic prefix' => ['10.0.0.0/abc'];
        yield 'empty prefix' => ['10.0.0.0/'];
        yield 'letter O for zero' => ['10.0.0.0/O8'];
        yield 'two slashes' => ['10.0.0.0/8/9'];
        yield 'trailing space' => ['10.0.0.0/8 '];
        yield 'nul byte after prefix' => ["10.0.0.0/8\0"];
        yield 'nul byte in address' => ["10.0.0.1\0"];
        yield 'overflowing ipv4 prefix' => ['10.0.0.0/' . str_repeat('9', 309)];
        yield 'overflowing ipv6 prefix' => ['2001:db8::/' . str_repeat('9', 309)];
        yield 'octet out of range' => ['999.1.1.1'];
        yield 'hostname' => ['example.com'];
        yield 'leading space' => [' 10.0.0.1'];
        yield 'bracketed ipv6' => ['[::1]'];
        yield 'comma separated pair' => ['10.0.0.0/8,172.16.0.0/12'];
        yield 'ipv4 compatible range below /96' => ['::10.0.0.0/8'];
        yield 'nat64 range below /96' => ['64:ff9b::10.0.0.0/8'];
    }

    #[DataProvider('malformedTrustedProxyEntries')]
    public function testThrowsForMalformedTrustedProxyEntry(string $entry): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'trustedProxies[0]' has invalid value " . MessageValue::quote($entry) . ': ');

        SecurityConfig::fromArray(['trustedProxies' => [$entry]]);
    }

    public function testIpv4MappedTrustedProxyShorterThan96IsRefusedWithTheIpv4Form(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has invalid value \"::ffff:10.0.0.0/8\": an IPv4-mapped IPv6 range shorter than /96");

        SecurityConfig::fromArray(['trustedProxies' => ['::ffff:10.0.0.0/8']]);
    }

    public function testIpv4MappedTrustedProxyAtSlash96WithHostBitsIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("has invalid value \"::ffff:10.0.0.0/96\": ::ffff:10.0.0.0/96 covers every IPv4 address; write ::ffff:10.0.0.0/128 for one IPv6 peer or 10.0.0.0/32 for one IPv4 peer");

        SecurityConfig::fromArray(['trustedProxies' => ['::ffff:10.0.0.0/96']]);
    }

    public function testIpv4MappedTrustedProxyAtSlash104IsAccepted(): void
    {
        $config = SecurityConfig::fromArray(['trustedProxies' => ['::ffff:10.0.0.0/104']]);

        self::assertSame(['::ffff:10.0.0.0/104'], $config->trustedProxies);
    }

    public function testTrustedProxiesStringIsSplitOnCommas(): void
    {
        $config = SecurityConfig::fromArray(['trustedProxies' => '10.0.0.1, 10.0.0.2']);

        self::assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies);
    }

    public function testTrustedProxiesStringDropsEmptyEntries(): void
    {
        $config = SecurityConfig::fromArray(['trustedProxies' => ' 10.0.0.1 , ,, 10.0.0.2 ,']);

        self::assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringsNamingNothing(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => [' '];
        yield 'lone comma' => [','];
        yield 'commas and spaces' => [' , '];
    }

    #[DataProvider('stringsNamingNothing')]
    public function testTrustedProxiesStringNamingNothingTrustsNoProxy(string $value): void
    {
        self::assertSame([], SecurityConfig::fromArray(['trustedProxies' => $value])->trustedProxies);
    }

    public function testTrustedProxiesEmptyArrayMeansNoProxy(): void
    {
        self::assertSame([], SecurityConfig::fromArray(['trustedProxies' => []])->trustedProxies);
    }

    public function testTrustedProxiesStringValidatesEachEntry(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'trustedProxies[1]' has invalid value \"bogus\": ");

        SecurityConfig::fromArray(['trustedProxies' => '10.0.0.1, bogus']);
    }

    public function testCommaSeparatedTrustedProxiesAskForOneEntryPerListItem(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('use list items, not a comma inside one item');

        SecurityConfig::fromArray(['trustedProxies' => ['10.0.0.0/8,172.16.0.0/12']]);
    }

    public function testAcceptsWildcardAddressAndValidCidrTrustedProxies(): void
    {
        $entries = ['*', '10.0.0.1', '10.0.0.0/8', '::1', '2001:db8::/32', '0.0.0.0/0', '::/0', '10.0.0.0/32', '::/128'];

        $config = SecurityConfig::fromArray(['trustedProxies' => $entries]);

        self::assertSame($entries, $config->trustedProxies);
    }

    public function testTrustedProxiesAreReindexed(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedProxies' => [5 => '10.0.0.1', 10 => '10.0.0.2'],
        ]);

        self::assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies);
    }

    public function testTrustedHeadersDefaultsToTheXForwardedFamily(): void
    {
        $config = SecurityConfig::fromArray([]);

        self::assertSame(
            ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port'],
            $config->trustedHeaders,
        );
        self::assertSame(Request::TRUSTED_HEADERS_DEFAULT, $config->trustedHeaders);
    }

    public function testTrustedHeadersAcceptsSnakeCaseKey(): void
    {
        $config = SecurityConfig::fromArray([
            'trusted_headers' => ['forwarded'],
        ]);

        self::assertSame(['forwarded'], $config->trustedHeaders);
    }

    public function testTrustedHeadersNormalizesCaseAndWhitespace(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedHeaders' => ['  X-Forwarded-For ', 'FORWARDED'],
        ]);

        self::assertSame(['x-forwarded-for', 'forwarded'], $config->trustedHeaders);
    }

    public function testTrustedHeadersAreDeduplicated(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedHeaders' => ['x-forwarded-for', 'X-Forwarded-For'],
        ]);

        self::assertSame(['x-forwarded-for'], $config->trustedHeaders);
    }

    public function testTrustedHeadersMayBeExplicitlyEmpty(): void
    {
        // An empty list is a real setting, not an absent key taking the default.
        $config = SecurityConfig::fromArray([
            'trustedHeaders' => [],
        ]);

        self::assertSame([], $config->trustedHeaders);
    }

    public function testTrustedHeadersAcceptsEveryOptInName(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedHeaders' => ['forwarded', 'x-real-ip', 'cf-connecting-ip', 'x-client-ip'],
        ]);

        self::assertSame(['forwarded', 'x-real-ip', 'cf-connecting-ip', 'x-client-ip'], $config->trustedHeaders);
    }

    public function testThrowsForUnknownTrustedHeader(): void
    {
        // A typo is rejected, never silently dropped.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('trustedHeaders');

        SecurityConfig::fromArray(['trustedHeaders' => ['x-forwarded-fro']]);
    }

    public function testThrowsForEmptyStringInTrustedHeaders(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('trustedHeaders');

        SecurityConfig::fromArray(['trustedHeaders' => ['x-forwarded-for', '']]);
    }

    public function testNestedCsrfSectionTakesPrecedenceOverFlatKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'csrfEnabled' => true,  // flat key
            'csrf' => [
                'enabled' => false,  // nested takes precedence
                'autoHtml' => false,
                'exceptions' => ['#^/api/#'],
            ],
        ]);

        self::assertFalse($config->csrfEnabled);
        self::assertFalse($config->csrfAutoHtml);
        self::assertSame(['#^/api/#'], $config->csrfExceptions);
    }

    public function testNestedCsrfSectionWithSnakeCaseKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'csrf' => [
                'auto_html' => false,
            ],
        ]);

        self::assertFalse($config->csrfAutoHtml);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function autoHtmlEnablingSpellings(): iterable
    {
        yield 'flat camelCase' => [['csrfAutoHtml' => true]];
        yield 'flat snake_case' => [['csrf_auto_html' => true]];
        yield 'nested autoHtml' => [['csrf' => ['autoHtml' => true]]];
        yield 'nested auto_html' => [['csrf' => ['auto_html' => true]]];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('autoHtmlEnablingSpellings')]
    public function testAutomaticTokenInjectionIsRefusedAtBoot(array $values): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Automatic token injection is not supported');

        SecurityConfig::fromArray($values);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function autoHtmlRefusalSources(): iterable
    {
        yield 'nested auto_html written as a string' => [
            ['csrf' => ['auto_html' => 'yes']],
            "field 'csrf.auto_html' has invalid value \"yes\": ",
        ];
        yield 'nested autoHtml written as a bool' => [
            ['csrf' => ['autoHtml' => true]],
            "field 'csrf.autoHtml' has invalid value true: ",
        ];
        yield 'flat csrf_auto_html written as a string' => [
            ['csrf_auto_html' => 'yes'],
            "field 'csrf_auto_html' has invalid value \"yes\": ",
        ];
        yield 'flat csrfAutoHtml written as a bool' => [
            ['csrfAutoHtml' => true],
            "field 'csrfAutoHtml' has invalid value true: ",
        ];
        yield 'null spelling is skipped in favour of the one that was written' => [
            ['csrf' => ['autoHtml' => null, 'auto_html' => 'yes']],
            "field 'csrf.auto_html' has invalid value \"yes\": ",
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('autoHtmlRefusalSources')]
    public function testAutoHtmlRefusalNamesTheKeyWrittenAndItsRawValue(array $values, string $expected): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($expected);

        SecurityConfig::fromArray($values);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function shownValueCases(): iterable
    {
        yield 'long value is cut at 64 bytes' => [
            ['csrf' => ['auto_html' => str_repeat('a', 200)]],
            str_repeat('a', 64),
            str_repeat('a', 65),
        ];
        yield 'line feed is escaped' => [
            ['csrf' => ['auto_html' => "on\nforged"]],
            'on\\nforged',
            "\n",
        ];
        yield 'multibyte value is cut on a character boundary' => [
            ['csrf' => ['auto_html' => str_repeat("\u{e9}", 40)]],
            str_repeat("\u{e9}", 32),
            str_repeat("\u{e9}", 33),
        ];
        yield 'invalid UTF-8 is replaced' => [
            ['csrf' => ['auto_html' => "ok\xffend"]],
            "ok\u{FFFD}end",
            "\xff",
        ];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('shownValueCases')]
    public function testAutoHtmlRefusalBoundsAndEscapesTheShownValue(array $values, string $shown, string $hidden): void
    {
        try {
            SecurityConfig::fromArray($values);
            self::fail('The automatic injection spelling must be refused.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString($shown, $e->getMessage());
            self::assertStringNotContainsString($hidden, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedListEntryCases(): iterable
    {
        yield 'huge trusted proxy is cut' => ['trustedProxies', str_repeat('9', 5 * 1024 * 1024), '"' . str_repeat('9', 64) . '..." (5242880 bytes)'];
        yield 'huge allowed host is cut' => ['allowedHosts', str_repeat('h', 5 * 1024 * 1024), '"' . str_repeat('h', 64) . '..." (5242880 bytes)'];
        yield 'trusted proxy line feed is escaped' => ['trustedProxies', "10.0.0.1\n10.0.0.2", '"10.0.0.1\n10.0.0.2"'];
        yield 'allowed host control byte is escaped' => ['allowedHosts', "a\x7fb", '"a\u007fb"'];
        yield 'allowed host bidi override is escaped' => ['allowedHosts', "a\u{202E}b", '"a\u202eb"'];
    }

    #[DataProvider('refusedListEntryCases')]
    public function testRefusedListEntryIsBoundedAndEscapedInTheMessage(string $setting, string $entry, string $shown): void
    {
        try {
            SecurityConfig::fromArray([$setting => [$entry]]);
            self::fail('The entry must be refused.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('has invalid value ' . $shown . ': ', $e->getMessage());
            self::assertLessThan(1024, strlen($e->getMessage()));
        }
    }

    public function testAutoHtmlRefusalTellsTheOperatorToRemoveTheLine(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/remove this line.*CsrfTokenManagerInterface::getToken\(\)\.$/s');

        SecurityConfig::fromArray(['csrf' => ['autoHtml' => true]]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function autoHtmlRefusalMessageSources(): iterable
    {
        yield 'nested auto_html' => [['csrf' => ['auto_html' => true]]];
        yield 'flat csrfAutoHtml' => [['csrfAutoHtml' => true]];
    }

    /**
     * @param array<string, mixed> $values
     */
    #[DataProvider('autoHtmlRefusalMessageSources')]
    public function testAutoHtmlRefusalMessageEndsWithOnePeriod(array $values): void
    {
        try {
            SecurityConfig::fromArray($values);
            self::fail('The automatic injection spelling must be refused.');
        } catch (ConfigurationException $e) {
            self::assertStringEndsWith('CsrfTokenManagerInterface::getToken().', $e->getMessage());
            self::assertStringNotContainsString('..', $e->getMessage());
        }
    }

    public function testCsrfAutoHtmlFalseIsAccepted(): void
    {
        $config = SecurityConfig::fromArray(['csrf' => ['autoHtml' => false]]);

        self::assertFalse($config->csrfAutoHtml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function falseAutoHtmlSpellings(): iterable
    {
        yield 'off' => ['off'];
        yield 'false' => ['false'];
    }

    #[DataProvider('falseAutoHtmlSpellings')]
    public function testCsrfAutoHtmlFalseSpellingIsAccepted(string $value): void
    {
        $config = SecurityConfig::fromArray(['csrf' => ['autoHtml' => $value]]);

        self::assertFalse($config->csrfAutoHtml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableAutoHtmlSpellings(): iterable
    {
        yield 'empty string' => [''];
    }

    #[DataProvider('unreadableAutoHtmlSpellings')]
    public function testCsrfAutoHtmlUnreadableSpellingIsRefused(string $value): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['csrf' => ['autoHtml' => $value]]);
    }

    public function testNestedCsrfSectionDefaults(): void
    {
        $config = SecurityConfig::fromArray([
            'csrf' => [],
        ]);

        self::assertTrue($config->csrfEnabled);
        self::assertFalse($config->csrfAutoHtml);
        self::assertSame([], $config->csrfExceptions);
    }

    public function testEncryptionKeyFromNestedSection(): void
    {
        $config = SecurityConfig::fromArray([
            'encryption' => [
                'key' => 'my-secret-key-32-chars-long!!!!!',
            ],
        ]);

        self::assertSame('my-secret-key-32-chars-long!!!!!', $config->encryptionKey);
    }

    public function testEncryptionKeyFromFlatCamelCase(): void
    {
        $config = SecurityConfig::fromArray([
            'encryptionKey' => 'flat-key-value',
        ]);

        self::assertSame('flat-key-value', $config->encryptionKey);
    }

    public function testEncryptionKeyFromFlatSnakeCase(): void
    {
        $config = SecurityConfig::fromArray([
            'encryption_key' => 'snake-key-value',
        ]);

        self::assertSame('snake-key-value', $config->encryptionKey);
    }

    public function testEncryptionKeyNestedTakesPrecedenceOverFlat(): void
    {
        $config = SecurityConfig::fromArray([
            'encryptionKey' => 'flat-value',
            'encryption' => [
                'key' => 'nested-value',
            ],
        ]);

        self::assertSame('nested-value', $config->encryptionKey);
    }

    public function testEncryptionKeyEmptyStringNormalizesToNull(): void
    {
        $config = SecurityConfig::fromArray([
            'encryption' => ['key' => '   '],
        ]);

        self::assertNull($config->encryptionKey);
    }

    public function testEncryptionKeyNonStringNormalizesToNull(): void
    {
        $config = SecurityConfig::fromArray([
            'encryption' => ['key' => 123],
        ]);

        self::assertNull($config->encryptionKey);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function malformedAllowedHostEntries(): iterable
    {
        yield 'bare star' => ['*', 'use an empty list to allow every host'];
        yield 'scheme' => ['https://example.com', 'drop the scheme'];
        yield 'unicode name' => ['bücher.example', 'punycode'];
        yield 'path' => ['example.com/x', 'must be a host name'];
        yield 'non-numeric port' => ['example.com:evil', 'must be a host name'];
    }

    public function testAllowedHostsStringIsSplitAndTrimmed(): void
    {
        $config = SecurityConfig::fromArray(['allowedHosts' => 'a.example, ,b.example']);

        self::assertSame(['a.example', 'b.example'], $config->allowedHosts);
    }

    #[DataProvider('stringsNamingNothing')]
    public function testAllowedHostsStringNamingNothingIsRefused(string $value): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'allowedHosts' has invalid value");
        $this->expectExceptionMessage('set the variable to at least one entry, or remove it');

        SecurityConfig::fromArray(['allowedHosts' => $value]);
    }

    public function testDeclaredNullAllowedHostsIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'allowedHosts' has invalid value null: ");

        SecurityConfig::fromArray(['allowedHosts' => null]);
    }

    public function testAbsentAllowedHostsIsAnEmptyList(): void
    {
        self::assertSame([], SecurityConfig::fromArray([])->allowedHosts);
    }

    public function testAllowedHostsStringValidatesEachEntry(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'allowedHosts[1]' has invalid value \"bad host\": ");

        SecurityConfig::fromArray(['allowedHosts' => 'a.example, bad host']);
    }

    public function testCommaSeparatedAllowedHostsAskForOneEntryPerListItem(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('use list items, not a comma inside one item');

        SecurityConfig::fromArray(['allowedHosts' => ['a.example.com,b.example.com']]);
    }

    public function testAllowedHostWithAPortAsksForTheHostOnly(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('ports are not matched: list "example.com" only');

        SecurityConfig::fromArray(['allowedHosts' => ['example.com:8080']]);
    }

    #[DataProvider('malformedAllowedHostEntries')]
    public function testMalformedAllowedHostEntryFailsAtBootWithTheFix(string $entry, string $fix): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'allowedHosts[0]' has invalid value " . MessageValue::quote($entry) . ': ');
        $this->expectExceptionMessage($fix);

        SecurityConfig::fromArray(['allowedHosts' => [$entry]]);
    }

    public function testWildcardAndIpLiteralAllowedHostEntriesAreAccepted(): void
    {
        $config = SecurityConfig::fromArray([
            'allowedHosts' => ['*.example.com', '[2001:db8::1]', 'my_app.example.com'],
        ]);

        self::assertSame(['*.example.com', '[2001:db8::1]', 'my_app.example.com'], $config->allowedHosts);
    }

    public function testAnEmptyCsrfSwitchIsRefusedInsteadOfDisablingCsrf(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['csrfEnabled' => '']);
    }

    public function testAnEmptyForceHttpsIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['forceHttps' => '']);
    }

    public function testTheStringZeroDisablesCsrfExplicitly(): void
    {
        self::assertFalse(SecurityConfig::fromArray(['csrfEnabled' => '0'])->csrfEnabled);
    }

    public function testNestedCsrfEnabledOffDisablesCsrf(): void
    {
        self::assertFalse(SecurityConfig::fromArray(['csrf' => ['enabled' => 'off']])->csrfEnabled);
    }

    public function testNestedCsrfEnabledEmptyStringIsRefusedInsteadOfDisablingCsrf(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['csrf' => ['enabled' => '']]);
    }

    public function testFlatCsrfEnabledEmptyStringIsRefusedInsteadOfDisablingCsrf(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['csrfEnabled' => '']);
    }

    public function testAbsentHeadersSectionGivesTheDefaultsAndIsNotDeclared(): void
    {
        $config = SecurityConfig::fromArray([]);

        self::assertEquals(SecureHeadersConfig::defaults(), $config->headers);
        self::assertFalse($config->isDeclared('headers'));
    }

    public function testHeadersSectionIsReadThroughSecureHeadersConfig(): void
    {
        $config = SecurityConfig::fromArray([
            'headers' => ['xFrameOptions' => 'DENY', 'hstsMaxAge' => '31536000'],
        ]);

        self::assertSame('DENY', $config->headers->xFrameOptions);
        self::assertSame(31_536_000, $config->headers->hstsMaxAge);
        self::assertSame('nosniff', $config->headers->xContentTypeOptions);
        self::assertTrue($config->isDeclared('headers'));
    }

    public function testNullHeadersSectionIsDeclaredAndGivesTheDefaults(): void
    {
        $config = SecurityConfig::fromArray(['headers' => null]);

        self::assertEquals(SecureHeadersConfig::defaults(), $config->headers);
        self::assertTrue($config->isDeclared('headers'));
    }

    public function testAScalarHeadersSectionIsRefusedNamingTheSection(): void
    {
        try {
            SecurityConfig::fromArray(['headers' => true]);

            self::fail('A scalar headers section was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('security.headers section', $exception->getMessage());
        }
    }

    public function testAHeadersSectionWithAMisspelledKeyIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/field 'contentSecurityPolicy'/");

        SecurityConfig::fromArray(['headers' => ['contentSecurityPolicy' => "default-src 'self'"]]);
    }
}
