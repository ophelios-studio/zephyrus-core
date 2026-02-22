<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\DatabaseConfig;

final class DatabaseConfigTest extends TestCase
{
    public function testBuildsWithDefaults(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'molt',
        ]);

        self::assertSame('localhost', $config->host);
        self::assertSame(3306, $config->port);
        self::assertSame('utf8mb4', $config->charset);
    }

    public function testThrowsForMissingRequiredValues(): void
    {
        $this->expectException(ConfigurationException::class);

        DatabaseConfig::fromArray(['username' => 'molt']);
    }
}
