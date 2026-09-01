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
     * WHAT THIS USED TO PIN, AND WHY IT CHANGED.
     *
     * This asserted the full path '/etc/missing.yml' was present in
     * getMessage(). Same ruling as invalidFormat() below: configuration loading
     * runs at BOOT, before the kernel's error handling exists, so this message
     * is among the likeliest in the framework to land raw in a log line or a
     * bluescreen, and it disclosed the deployment's filesystem layout for
     * nothing. The file NAME stays; the path moved to path().
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
     * Previously asserted only the prefix, so the leak went unnoticed here.
     * See testFileNotFoundKeepsTheServerPathOutOfTheMessage for the ruling.
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
     * THE SPLIT THIS PINS. parseFailed() has two halves and they were ruled
     * differently.
     *
     * OUR half interpolated the full path. That is us formatting a filesystem
     * fact ourselves, so it is now a basename and the path lives on path().
     *
     * The PARSER's half is appended verbatim and is KEPT, the same category
     * ruled KEEP for RenderException::renderFailed(): it is a preserved
     * upstream diagnostic and it carries the line number a developer actually
     * needs. Symfony's ParseException names the absolute file in SOME of its
     * messages (a tab-indentation error does, a malformed-inline error does
     * not), so this message can still disclose a path in practice on some
     * inputs. That is a knowing trade, not an oversight, which is exactly why
     * it is written down here and in the factory docblock.
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
     * With no previous exception there is no inherited half at all, so the
     * message this class builds on its own must be path-free.
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
     * WHAT THIS USED TO PIN, AND WHY IT CHANGED.
     *
     * This asserted that the path passed in was present in getMessage(), which
     * with a real absolute path meant the message disclosed the deployment's
     * filesystem layout. Configuration loading runs at BOOT, before the
     * kernel's error handling exists, so this message is among the likeliest in
     * the framework to land raw in a log line or a bluescreen.
     *
     * The file NAME stays, because that is the diagnostic. The path moved to
     * path(), the same shape RenderException::templateNotFound() uses.
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
     * The accessor is null for a factory that carries no path, so "no path
     * recorded" stays distinguishable from "the path was empty".
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
