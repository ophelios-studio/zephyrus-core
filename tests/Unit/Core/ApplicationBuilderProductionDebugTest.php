<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Zephyrus\Core\App;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;

/**
 * `environment: production` plus `debug: true` must not boot a live debugger.
 *
 * The framework already refuses to boot on an unwired security setting, and
 * EnvironmentContract already forces display_errors off because the image may
 * ship no php.ini. Two lines later, this combination booted silently and undid
 * both. isProductionLike() existed and was used only to compute a DEFAULT,
 * never as a guard.
 */
final class ApplicationBuilderProductionDebugTest extends TestCase
{
    protected function tearDown(): void
    {
        App::reset();
    }

    public function testProductionWithDebugResolvesToDebugOff(): void
    {
        $builder = ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'production', 'debug' => true],
        ]));

        self::assertFalse($builder->isDebugEnabledForBoot());
    }

    public function testStagingWithDebugResolvesToDebugOff(): void
    {
        $builder = ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'staging', 'debug' => true],
        ]));

        self::assertFalse($builder->isDebugEnabledForBoot());
    }

    public function testDevelopmentWithDebugIsUntouched(): void
    {
        $builder = ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'development', 'debug' => true],
        ]));

        self::assertTrue($builder->isDebugEnabledForBoot());
    }

    public function testAnExplicitAcknowledgementRestoresIt(): void
    {
        $builder = ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'production', 'debug' => true],
        ]))->withProductionDebugAcknowledged();

        self::assertTrue($builder->isDebugEnabledForBoot());
    }

    public function testAcknowledgementIsImmutableLikeEveryOtherBuilderOption(): void
    {
        $builder = ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'production', 'debug' => true],
        ]));

        $acknowledged = $builder->withProductionDebugAcknowledged();

        self::assertFalse($builder->isDebugEnabledForBoot());
        self::assertTrue($acknowledged->isDebugEnabledForBoot());
    }

    /**
     * Forcing it off silently would trade one invisible failure for another,
     * so the boot has to say so. The line names both settings and the escape
     * hatch, and carries no value of any kind.
     */
    public function testTheRefusalIsWrittenToTheErrorLogOnEveryBoot(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zephyrus-debug-guard-');
        self::assertIsString($logFile);

        $previousLog = ini_get('error_log');
        $previousDisplay = ini_get('log_errors');
        ini_set('error_log', $logFile);
        ini_set('log_errors', '1');

        try {
            ApplicationBuilder::fromConfiguration(Configuration::fromArray([
                'application' => ['environment' => 'production', 'debug' => true],
            ]))->build();

            $written = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            ini_set('log_errors', $previousDisplay === false ? '1' : $previousDisplay);
            @unlink($logFile);
        }

        self::assertStringContainsString(ApplicationBuilder::PRODUCTION_DEBUG_REFUSED, $written);
    }

    public function testNothingIsLoggedWhenDebugWasNeverAskedFor(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'zephyrus-debug-guard-');
        self::assertIsString($logFile);

        $previousLog = ini_get('error_log');
        ini_set('error_log', $logFile);
        ini_set('log_errors', '1');

        try {
            ApplicationBuilder::fromConfiguration(Configuration::fromArray([
                'application' => ['environment' => 'production', 'debug' => false],
            ]))->build();

            $written = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
            @unlink($logFile);
        }

        self::assertStringNotContainsString('PRODUCTION_DEBUG', $written);
        self::assertStringNotContainsString('FORCED OFF', $written);
    }

    /**
     * The end-to-end shape of the leak: a production tier an operator has put
     * in debug must not come out of build() with a live debugger attached.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testBuildingAProductionAppWithDebugNeverEnablesTracy(): void
    {
        // The guard logs, and in a child process error_log() would go to stderr
        // and be read as a failure. Redirect it; testTheRefusalIsWritten...
        // above is what asserts the content.
        ini_set('error_log', (string) tempnam(sys_get_temp_dir(), 'zephyrus-debug-guard-'));

        ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'production', 'debug' => true],
        ]))->build();

        self::assertFalse(Debugger::isEnabled());
    }

    /**
     * Even the acknowledged case does not broadcast: DebugIntegration still
     * runs Tracy in Detect mode, so a remote client is served nothing.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnAcknowledgedProductionDebugStillRefusesARemoteClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';

        ApplicationBuilder::fromConfiguration(Configuration::fromArray([
            'application' => ['environment' => 'production', 'debug' => true],
        ]))->withProductionDebugAcknowledged()->build();

        self::assertTrue(Debugger::isEnabled());
        self::assertTrue(Debugger::$productionMode, 'The remote client must still be refused the Bluescreen.');
    }
}
