<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Exceptions\ZephyrusException;

final class ConfigurationExceptionTest extends TestCase
{
    public function testExtendsZephyrusException(): void
    {
        $e = ConfigurationException::fileNotFound('/path');
        self::assertInstanceOf(ZephyrusException::class, $e);
    }

    public function testMissingRequired(): void
    {
        $e = ConfigurationException::missingRequired('database', 'host');
        self::assertStringContainsString('database', $e->getMessage());
        self::assertStringContainsString('host', $e->getMessage());
    }

    public function testMissingRequiredExposesItsSectionAndFieldOnly(): void
    {
        $e = ConfigurationException::missingRequired('database', 'host');

        self::assertSame('database', $e->section());
        self::assertSame('host', $e->field());
        self::assertNull($e->reason());
        self::assertNull($e->messageWithoutValue());
    }

    public function testMissingRequiredQuotesAFieldThatIsNotAPlainKey(): void
    {
        self::assertSame(
            "Configuration section 'database' requires field \"a\\u001bb\" but none was provided.",
            ConfigurationException::missingRequired('database', "a\x1bb")->getMessage(),
        );
    }

    public function testRemovedFieldExposesItsSectionAndFieldOnly(): void
    {
        $e = ConfigurationException::removedField('database', 'emulate_prepares', 'Delete this line.');

        self::assertSame(
            "Configuration section 'database' field 'emulate_prepares' has been REMOVED from Zephyrus and is no "
            . 'longer honoured. Delete this line.',
            $e->getMessage(),
        );
        self::assertSame('database', $e->section());
        self::assertSame('emulate_prepares', $e->field());
        self::assertNull($e->reason());
        self::assertNull($e->messageWithoutValue());
    }

    public function testInvalidValueExposesItsPartsAndTheMessageWithoutTheValue(): void
    {
        $e = ConfigurationException::invalidValue('mailer', 'smtp.port', 'abc', 'must be an integer');

        self::assertSame('mailer', $e->section());
        self::assertSame('smtp.port', $e->field());
        self::assertSame('must be an integer', $e->reason());
        self::assertSame(
            "Configuration section 'mailer' field 'smtp.port' has invalid value [value]: must be an integer.",
            $e->messageWithoutValue(),
        );
    }

    public function testMessageWithoutValueQuotesAFieldThatIsNotAPlainKeyAsTheMessageDoes(): void
    {
        $e = ConfigurationException::invalidValue('security.headers', "csp\u{202E}", ['x'], 'unknown key');

        self::assertSame("csp\u{202E}", $e->field());
        self::assertSame(
            "Configuration section 'security.headers' field \"csp\\u202e\" has invalid value [value]: unknown key.",
            $e->messageWithoutValue(),
        );
    }

    public function testInvalidValueKeepsTheRawValueOutOfItsProperties(): void
    {
        $e = ConfigurationException::invalidValue('mailer', 'smtp.password', 'raw-secret-value', 'too short');

        $properties = (array) $e;
        unset($properties["\0*\0message"], $properties["\0Exception\0string"], $properties["\0Exception\0trace"]);

        self::assertStringNotContainsString('raw-secret-value', serialize($properties));
    }

    public function testInvalidValueKeepsTheRawValueOutOfTheTraceArguments(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            $e = ConfigurationException::invalidValue('mailer', 'smtp.password', 'raw-secret-value', 'too short');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        $frame = $e->getTrace()[0];
        self::assertSame('invalidValue', $frame['function']);
        self::assertInstanceOf(\SensitiveParameterValue::class, $frame['args'][2] ?? null);
    }

    /**
     * @return iterable<string, array{ConfigurationException}>
     */
    public static function factoriesWithoutAField(): iterable
    {
        yield 'fileNotFound' => [ConfigurationException::fileNotFound('/srv/app.yml')];
        yield 'loadFailed' => [ConfigurationException::loadFailed('/srv/app.yml')];
        yield 'parseFailed' => [ConfigurationException::parseFailed('/srv/app.yml')];
        yield 'invalidFormat' => [ConfigurationException::invalidFormat('/srv/app.yml')];
        yield 'invalidPath' => [ConfigurationException::invalidPath('Config path must not be empty.')];
        yield 'unwiredSecurity' => [ConfigurationException::unwiredSecurity(['security.csrf' => 'mount it'])];
        yield 'shadowedContentSecurityPolicy' => [ConfigurationException::shadowedContentSecurityPolicy()];
        yield 'constructor' => [new ConfigurationException('Configuration refused.')];
    }

    #[DataProvider('factoriesWithoutAField')]
    public function testOtherFactoriesLeaveEveryPartNull(ConfigurationException $e): void
    {
        self::assertNull($e->section());
        self::assertNull($e->field());
        self::assertNull($e->reason());
        self::assertNull($e->messageWithoutValue());
    }

    public function testInvalidValueQuotesAStringValue(): void
    {
        $e = ConfigurationException::invalidValue('session', 'sameSite', 'bad', 'must be Strict, Lax, or None');

        self::assertSame(
            "Configuration section 'session' field 'sameSite' has invalid value \"bad\": must be Strict, Lax, or None.",
            $e->getMessage(),
        );
    }

    public function testInvalidValueEscapesControlAndBidiCharactersOfTheValue(): void
    {
        $e = ConfigurationException::invalidValue('security', 'allowedHosts[0]', "a\x1b[31m\u{202E}\x7f", 'not a host');

        self::assertSame(
            "Configuration section 'security' field 'allowedHosts[0]' has invalid value \"a\\u001b[31m\\u202e\\u007f\": not a host.",
            $e->getMessage(),
        );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function nonStringValues(): iterable
    {
        yield 'int' => [-1, '-1'];
        yield 'float' => [1.0, '1.0'];
        yield 'bool' => [false, 'false'];
        yield 'null' => [null, 'null'];
        yield 'array' => [['a'], 'array'];
    }

    #[DataProvider('nonStringValues')]
    public function testInvalidValueShowsANonStringByItsValueOrType(mixed $value, string $shown): void
    {
        $e = ConfigurationException::invalidValue('session', 'lifetime', $value, 'must be 0 or greater');

        self::assertSame(
            "Configuration section 'session' field 'lifetime' has invalid value $shown: must be 0 or greater.",
            $e->getMessage(),
        );
    }

    public function testInvalidValueQuotesAFieldNameThatIsNotAPlainKey(): void
    {
        $e = ConfigurationException::invalidValue('security.headers', "csp\0\u{202E}x", 'x', 'unknown key');

        self::assertSame(
            "Configuration section 'security.headers' field \"csp\\u0000\\u202ex\" has invalid value \"x\": unknown key.",
            $e->getMessage(),
        );
    }

    /**
     * The message names the file, not its path: boot-time errors can reach logs and bluescreens.
     */
    public function testFileNotFoundKeepsTheServerPathOutOfTheMessage(): void
    {
        $e = ConfigurationException::fileNotFound('/srv/app/config/missing.yml');

        self::assertStringContainsString('not found', $e->getMessage());
        self::assertStringContainsString('missing.yml', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/config/', $e->getMessage());
        self::assertSame('/srv/app/config/missing.yml', $e->path());
    }

    /**
     * Same rule as the file-not-found case: the server path stays out of the message.
     */
    public function testLoadFailedKeepsTheServerPathOutOfTheMessage(): void
    {
        $previous = new \RuntimeException('boom');
        $e = ConfigurationException::loadFailed('/srv/app/config/config.php', $previous);

        self::assertStringContainsString('failed to load', $e->getMessage());
        self::assertStringContainsString('config.php', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/config/', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
        self::assertSame('/srv/app/config/config.php', $e->path());
    }

    public function testLoadFailedWithoutPrevious(): void
    {
        $e = ConfigurationException::loadFailed('/srv/app/config/config.php');

        self::assertStringContainsString('failed to load', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/config/', $e->getMessage());
        self::assertNull($e->getPrevious());
        self::assertSame('/srv/app/config/config.php', $e->path());
    }

    /**
     * Our half names the file by basename. The parser's diagnostic is kept verbatim for its line
     * number, and can still name the absolute path.
     */
    public function testParseFailedBasenamesOurHalfAndKeepsTheParserDiagnostic(): void
    {
        $previous = new \RuntimeException('syntax error');
        $e = ConfigurationException::parseFailed('/srv/app/config/config.yml', $previous);

        self::assertStringContainsString('Failed to parse', $e->getMessage());
        self::assertStringContainsString('config.yml', $e->getMessage());
        self::assertStringContainsString('syntax error', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
        self::assertSame('/srv/app/config/config.yml', $e->path());
    }

    /**
     * Without a previous exception, the message must be path-free on its own.
     */
    public function testParseFailedWithoutPreviousIsEntirelyPathFree(): void
    {
        $e = ConfigurationException::parseFailed('/srv/app/config/config.yml');

        self::assertStringContainsString('Failed to parse', $e->getMessage());
        self::assertStringContainsString('config.yml', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/config/', $e->getMessage());
        self::assertNull($e->getPrevious());
        self::assertSame('/srv/app/config/config.yml', $e->path());
    }

    /**
     * The message names the file only (loading runs before error handling exists); the path is on path().
     */
    public function testInvalidFormatKeepsTheServerPathOutOfTheMessage(): void
    {
        $e = ConfigurationException::invalidFormat('/srv/app/config/config.php');

        self::assertStringContainsString('config.php', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/config/', $e->getMessage());
        self::assertStringContainsString('must return an array', $e->getMessage());
        self::assertSame('/srv/app/config/config.php', $e->path());
    }

    public function testInvalidFormatWithCustomReason(): void
    {
        $e = ConfigurationException::invalidFormat('/srv/app/config/config.php', 'must be valid YAML');

        self::assertStringContainsString('must be valid YAML', $e->getMessage());
        self::assertStringNotContainsString('/srv/app/config/', $e->getMessage());
    }

    /**
     * A factory without a path returns null, which is distinct from an empty path.
     */
    public function testPathIsNullForAFactoryThatCarriesNoPath(): void
    {
        self::assertNull(ConfigurationException::invalidPath('Config path must not be empty.')->path());
    }

    public function testInvalidPath(): void
    {
        $e = ConfigurationException::invalidPath('Config path must not be empty.');
        self::assertSame('Config path must not be empty.', $e->getMessage());
    }
}
