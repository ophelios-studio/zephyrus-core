<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Tracy\Debugger;
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
        unset($_SERVER['REMOTE_ADDR'], $_COOKIE['tracy-debug']);
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

        self::buildUntilRefused($builder);

        self::assertSame(['0', '0'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    public function testABuildWithoutConfigurationTurnsDisplayErrorsOff(): void
    {
        ApplicationBuilder::create()->build();

        self::assertSame(['0', '0'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    public function testADebugBootInTheCommandLineLeavesDisplayErrorsOn(): void
    {
        $builder = self::builderRefusedByTheSecurityWiringCheck(['environment' => 'development', 'debug' => true]);

        self::buildUntilRefused($builder);

        self::assertSame(['1', '1'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    public function testADebugBootLeavesDisplayErrorsOnForAClientTheAllowlistAdmits(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $_COOKIE['tracy-debug'] = 's3cr3t';
        $builder = self::builderRefusedByTheSecurityWiringCheck(['environment' => 'development', 'debug' => true])
            ->withDebugClientAllowlist('s3cr3t@203.0.113.9');

        self::buildUntilRefused(self::servedOverHttp($builder));

        self::assertSame(['1', '1'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    public function testADebugBootTurnsDisplayErrorsOffForAClientTheAllowlistOmits(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $builder = self::builderRefusedByTheSecurityWiringCheck(['environment' => 'development', 'debug' => true])
            ->withDebugClientAllowlist('s3cr3t@203.0.113.9');

        self::buildUntilRefused(self::servedOverHttp($builder));

        self::assertSame(['0', '0'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function webServerApiProvider(): array
    {
        return [
            'FastCGI process manager' => ['fpm-fcgi'],
            'Apache module'           => ['apache2handler'],
        ];
    }

    #[DataProvider('webServerApiProvider')]
    public function testADebugBootWithoutAnAllowlistTurnsDisplayErrorsOffForLoopbackUnderAWebServerApi(string $serverApi): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $builder = self::builderRefusedByTheSecurityWiringCheck(['environment' => 'development', 'debug' => true]);

        self::buildUntilRefused(self::servedOverHttp($builder, $serverApi));

        self::assertSame(['0', '0'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    public function testADebugBootWithoutAnAllowlistLeavesDisplayErrorsOnForLoopbackUnderTheBuiltInServer(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $builder = self::builderRefusedByTheSecurityWiringCheck(['environment' => 'development', 'debug' => true]);

        self::buildUntilRefused(self::servedOverHttp($builder, 'cli-server'));

        self::assertSame(['1', '1'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    public function testADebugBootLeavesDisplayErrorsOnForALoopbackAllowlistUnderAWebServerApi(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $builder = self::builderRefusedByTheSecurityWiringCheck(['environment' => 'development', 'debug' => true])
            ->withDebugClientAllowlist(['127.0.0.1']);

        self::buildUntilRefused(self::servedOverHttp($builder));

        self::assertSame(['1', '1'], [ini_get('display_errors'), ini_get('display_startup_errors')]);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testABuildHandsTheDebuggerProductionModeToLoopbackUnderAWebServerApi(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $builder = ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'development', 'debug' => true],
        ]));

        self::servedOverHttp($builder, 'fpm-fcgi')->build();

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testABuildLeavesTheDebuggerRenderingForLoopbackUnderTheBuiltInServer(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $builder = ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'development', 'debug' => true],
        ]));

        self::servedOverHttp($builder, 'cli-server')->build();

        self::assertFalse(Debugger::$productionMode);
    }

    private static function buildUntilRefused(ApplicationBuilder $builder): void
    {
        try {
            $builder->build();
            self::fail('expected the security wiring refusal');
        } catch (ConfigurationException) {
        }
    }

    /** Builds as a web server SAPI would, where only a client the debugger admits sees errors. */
    private static function servedOverHttp(ApplicationBuilder $builder, string $serverApi = 'fpm-fcgi'): ApplicationBuilder
    {
        (new ReflectionProperty(ApplicationBuilder::class, 'serverApi'))->setValue($builder, $serverApi);

        return $builder;
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
