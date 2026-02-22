<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\SessionConfig;

final class SessionConfigTest extends TestCase
{
    public function testBuildsWithDefaults(): void
    {
        $config = SessionConfig::fromArray([]);

        self::assertSame('PHPSESSID', $config->name);
        self::assertTrue($config->httpOnly);
        self::assertSame('Lax', $config->sameSite);
    }

    public function testThrowsForInvalidSameSite(): void
    {
        $this->expectException(ConfigurationException::class);

        SessionConfig::fromArray(['sameSite' => 'Loose']);
    }
}
