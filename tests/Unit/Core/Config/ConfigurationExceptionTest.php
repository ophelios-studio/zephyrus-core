<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

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

    public function testInvalidValue(): void
    {
        $e = ConfigurationException::invalidValue('session', 'sameSite', 'bad', 'must be Strict, Lax, or None');
        self::assertStringContainsString('session', $e->getMessage());
        self::assertStringContainsString('sameSite', $e->getMessage());
        self::assertStringContainsString('bad', $e->getMessage());
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
