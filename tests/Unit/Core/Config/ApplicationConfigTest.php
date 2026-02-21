<?php

declare(strict_types=1);

namespace Zephyrus2\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus2\Core\Config\ApplicationConfig;
use Zephyrus2\Core\Config\Environment;

final class ApplicationConfigTest extends TestCase
{
    public function testDefaultsToProductionWithoutDebug(): void
    {
        $config = ApplicationConfig::fromArray([]);

        self::assertSame(Environment::Production, $config->environment);
        self::assertFalse($config->debug);
    }

    public function testEnablesDebugByDefaultForDevelopmentAliases(): void
    {
        $config = ApplicationConfig::fromArray(['environment' => 'local']);

        self::assertSame(Environment::Development, $config->environment);
        self::assertTrue($config->debug);
    }

    public function testRespectsExplicitDebugFlag(): void
    {
        $config = ApplicationConfig::fromArray([
            'environment' => 'production',
            'debug' => '1',
        ]);

        self::assertSame(Environment::Production, $config->environment);
        self::assertTrue($config->debug);
    }
}
