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

    public function testFileNotFound(): void
    {
        $e = ConfigurationException::fileNotFound('/etc/missing.yml');
        self::assertStringContainsString('not found', $e->getMessage());
        self::assertStringContainsString('/etc/missing.yml', $e->getMessage());
    }

    public function testLoadFailed(): void
    {
        $previous = new \RuntimeException('boom');
        $e = ConfigurationException::loadFailed('/config.php', $previous);
        self::assertStringContainsString('failed to load', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testLoadFailedWithoutPrevious(): void
    {
        $e = ConfigurationException::loadFailed('/config.php');
        self::assertStringContainsString('failed to load', $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    public function testParseFailed(): void
    {
        $previous = new \RuntimeException('syntax error');
        $e = ConfigurationException::parseFailed('/config.yml', $previous);
        self::assertStringContainsString('Failed to parse', $e->getMessage());
        self::assertStringContainsString('syntax error', $e->getMessage());
        self::assertSame($previous, $e->getPrevious());
    }

    public function testParseFailedWithoutPrevious(): void
    {
        $e = ConfigurationException::parseFailed('/config.yml');
        self::assertStringContainsString('Failed to parse', $e->getMessage());
        self::assertNull($e->getPrevious());
    }

    public function testInvalidFormat(): void
    {
        $e = ConfigurationException::invalidFormat('/config.php');
        self::assertStringContainsString('/config.php', $e->getMessage());
        self::assertStringContainsString('must return an array', $e->getMessage());
    }

    public function testInvalidFormatWithCustomReason(): void
    {
        $e = ConfigurationException::invalidFormat('/config.php', 'must be valid YAML');
        self::assertStringContainsString('must be valid YAML', $e->getMessage());
    }

    public function testInvalidPath(): void
    {
        $e = ConfigurationException::invalidPath('Config path must not be empty.');
        self::assertSame('Config path must not be empty.', $e->getMessage());
    }
}
