<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ApplicationConfig;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\Environment;

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

    public function testOffReadsAsDebugDisabled(): void
    {
        $config = ApplicationConfig::fromArray(['environment' => 'development', 'debug' => 'off']);

        self::assertFalse($config->debug);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unreadableDebugValues(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'unknown word' => ['enabled'],
            'int 2' => [2],
        ];
    }

    #[DataProvider('unreadableDebugValues')]
    public function testAnUnreadableDebugValueIsRefused(mixed $value): void
    {
        $this->expectException(ConfigurationException::class);

        ApplicationConfig::fromArray(['environment' => 'development', 'debug' => $value]);
    }

    public function testAMisspelledDebugIsRefused(): void
    {
        try {
            ApplicationConfig::fromArray(['debgu' => false]);

            self::fail('A misspelled debug was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'application' field 'debgu' is an unknown key: did you mean \"debug\"?",
                $exception->getMessage(),
            );
        }
    }
}
