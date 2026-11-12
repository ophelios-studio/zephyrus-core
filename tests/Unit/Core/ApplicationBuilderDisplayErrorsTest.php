<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;

final class ApplicationBuilderDisplayErrorsTest extends TestCase
{
    private string $displayErrors;

    private string $displayStartupErrors;

    protected function setUp(): void
    {
        $this->displayErrors = (string) ini_get('display_errors');
        $this->displayStartupErrors = (string) ini_get('display_startup_errors');
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
    }

    protected function tearDown(): void
    {
        ini_set('display_errors', $this->displayErrors);
        ini_set('display_startup_errors', $this->displayStartupErrors);
        App::reset();
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function bootWithoutDebugProvider(): array
    {
        return [
            'debug off'                      => [['environment' => 'development', 'debug' => false]],
            'debug forced off in production' => [['environment' => 'production', 'debug' => true]],
        ];
    }

    /**
     * @param array<string, mixed> $application
     */
    #[DataProvider('bootWithoutDebugProvider')]
    public function testABootRefusedWithoutDebugHasAlreadyTurnedDisplayErrorsOff(array $application): void
    {
        $builder = self::builderRefusedByTheSecurityWiringCheck($application);

        try {
            $builder->build();
            self::fail('expected the security wiring refusal');
        } catch (ConfigurationException) {
        }

        self::assertSame(['0', '0'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    public function testABuildWithoutConfigurationTurnsDisplayErrorsOff(): void
    {
        ApplicationBuilder::create()->build();

        self::assertSame(['0', '0'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    public function testADebugBootLeavesDisplayErrorsToTheDebugger(): void
    {
        $builder = self::builderRefusedByTheSecurityWiringCheck(['environment' => 'development', 'debug' => true]);

        try {
            $builder->build();
            self::fail('expected the security wiring refusal');
        } catch (ConfigurationException) {
        }

        self::assertSame(['1', '1'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    /**
     * @param array<string, mixed> $application
     */
    private static function builderRefusedByTheSecurityWiringCheck(array $application): ApplicationBuilder
    {
        return ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => $application,
            'security'    => ['forceHttps' => true],
        ]));
    }
}
