<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Tracy\Dumper;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\DebugIntegration;
use Zephyrus\Http\Response;

/**
 * The debugger must not render to a client that did not earn it.
 *
 * Tracy's Development mode renders the Bluescreen: the exception, the stack
 * trace WITH argument values, $_SERVER and $_ENV. DebugIntegration hardcoded
 * that mode, so enabling debug served every one of those to whichever client
 * managed to trigger a 500 -- an anonymous caller included, on a production
 * tier an operator had put in debug for ten minutes to diagnose an incident.
 *
 * Every case runs in its own process: Debugger::enable() installs global error
 * handlers and latches Debugger::$productionMode, so a second call in the same
 * process no longer recomputes anything.
 */
final class DebugIntegrationExposureTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARemoteClientDoesNotGetTheDebuggerRendered(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';

        DebugIntegration::initialize(debug: true, serverApi: 'cli-server');

        self::assertTrue(
            Debugger::$productionMode,
            'A remote client must be served the production (non-rendering) strategy.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARemoteClientBehindAProxyDoesNotGetTheDebuggerRendered(): void
    {
        // The shape of a Fly / load-balanced deployment: the peer is the proxy
        // and the forwarded header is present, which is exactly when Tracy
        // refuses to grant loopback automatically.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';

        DebugIntegration::initialize(debug: true, serverApi: 'cli-server');

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoopbackStillGetsTheDebugger(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: true, serverApi: 'cli-server');

        self::assertFalse(
            Debugger::$productionMode,
            'A developer on their own machine must keep the Bluescreen.',
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnAllowlistedClientGetsTheDebugger(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';

        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77']);

        self::assertFalse(Debugger::$productionMode);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonBuiltInServerApiProvider(): array
    {
        return [
            'FastCGI process manager' => ['fpm-fcgi'],
            'Apache module'           => ['apache2handler'],
            'command line'            => ['cli'],
        ];
    }

    #[DataProvider('nonBuiltInServerApiProvider')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutAnAllowlistLoopbackDoesNotGetTheDebuggerOutsideTheBuiltInServer(string $serverApi): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: true, serverApi: $serverApi);

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testALoopbackAllowlistGetsTheDebuggerUnderAWebServerApi(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: true, allowedClients: ['127.0.0.1'], serverApi: 'fpm-fcgi');

        self::assertFalse(Debugger::$productionMode);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function loopbackAddressProvider(): array
    {
        return [
            'IPv4 loopback' => ['127.0.0.1'],
            'IPv6 loopback' => ['::1'],
        ];
    }

    #[DataProvider('loopbackAddressProvider')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoopbackDoesNotGetTheDebuggerWhenTheAllowlistOmitsIt(string $loopback): void
    {
        $_SERVER['REMOTE_ADDR'] = $loopback;

        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77', 'a-shared-secret-01@203.0.113.78']);

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoopbackGetsTheDebuggerWhenTheAllowlistNamesIt(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77', '127.0.0.1']);

        self::assertFalse(Debugger::$productionMode);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function proxiedLoopbackProvider(): array
    {
        return [
            'IPv4 loopback with X-Forwarded-For' => ['127.0.0.1', 'HTTP_X_FORWARDED_FOR'],
            'IPv6 loopback with Forwarded'       => ['::1', 'HTTP_FORWARDED'],
            'another 127/8 address'              => ['127.0.0.2', 'HTTP_X_FORWARDED_FOR'],
            'IPv4-mapped loopback'               => ['::ffff:127.0.0.1', 'HTTP_FORWARDED'],
        ];
    }

    #[DataProvider('proxiedLoopbackProvider')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testABareLoopbackEntryDoesNotAdmitARequestCarryingAForwardingHeader(string $loopback, string $header): void
    {
        $_SERVER['REMOTE_ADDR'] = $loopback;
        $_SERVER[$header] = '198.51.100.20';

        DebugIntegration::initialize(debug: true, allowedClients: [$loopback]);

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASecretLoopbackEntryAdmitsARequestCarryingAForwardingHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
        $_COOKIE['tracy-debug'] = 'a-shared-secret-01';

        DebugIntegration::initialize(debug: true, allowedClients: ['a-shared-secret-01@127.0.0.1']);

        self::assertFalse(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testABareLoopbackEntryDoesNotAdmitARequestCarryingXRealIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.20';

        DebugIntegration::initialize(debug: true, allowedClients: ['127.0.0.1']);

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASecretLoopbackEntryAdmitsARequestCarryingXRealIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_REAL_IP'] = '198.51.100.20';
        $_COOKIE['tracy-debug'] = 'a-shared-secret-01';

        DebugIntegration::initialize(debug: true, allowedClients: ['a-shared-secret-01@127.0.0.1']);

        self::assertFalse(Debugger::$productionMode);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function forwardingHeaderProvider(): array
    {
        return [
            'X-Real-IP'       => ['HTTP_X_REAL_IP', '198.51.100.20'],
            'X-Forwarded-For' => ['HTTP_X_FORWARDED_FOR', '198.51.100.20'],
            'Forwarded'       => ['HTTP_FORWARDED', 'for=198.51.100.20'],
        ];
    }

    #[DataProvider('forwardingHeaderProvider')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWithoutAnAllowlistLoopbackCarryingAForwardingHeaderDoesNotGetTheDebugger(string $header, string $value): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER[$header] = $value;

        DebugIntegration::initialize(debug: true, serverApi: 'cli-server');

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testABareNonLoopbackEntryAdmitsARequestCarryingAForwardingHeader(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';

        DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.77']);

        self::assertFalse(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTracyDebugCookieDoesNotGrantAnAddressItIsNotPairedWith(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $_COOKIE['tracy-debug'] = 'a-shared-secret-01';

        DebugIntegration::initialize(debug: true, allowedClients: 'a-shared-secret-01@203.0.113.77');

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTracyDebugCookieGrantsAnOtherwiseRefusedClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $_COOKIE['tracy-debug'] = 'a-shared-secret-01';

        DebugIntegration::initialize(debug: true, allowedClients: 'a-shared-secret-01@203.0.113.77');

        self::assertFalse(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheWrongCookieDoesNotGrantTheClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $_COOKIE['tracy-debug'] = 'guessed';

        DebugIntegration::initialize(debug: true, allowedClients: 'a-shared-secret-01@203.0.113.77');

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFrameworkSecretKeyNamesAreHiddenFromEveryTracySurface(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: true);

        // Tracy's own defaults cover the raw config array, where the SMTP
        // password sits under the key 'password'. They do NOT cover the typed
        // property hydrated beside it, so the same secret rendered twice and
        // was masked once.
        self::assertContains('smtpPassword', Debugger::getBlueScreen()->keysToHide);
        self::assertContains('encryptionKey', Debugger::getBlueScreen()->keysToHide);
        self::assertContains('smtpPassword', Debugger::$keysToHide);
        self::assertContains('encryptionKey', Debugger::$keysToHide);
        self::assertContains('Zephyrus\Core\ApplicationBuilder::$debugAllowedClients', Debugger::getBlueScreen()->keysToHide);
        self::assertContains('Zephyrus\Core\ApplicationBuilder::$debugAllowedClients', Debugger::$keysToHide);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheDebugClientAllowlistIsMaskedInADumpOfTheBuilder(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: true);
        $builder = ApplicationBuilder::create()->withDebugClientAllowlist('allowlistsecret123@172.17.0.1');

        self::assertStringNotContainsString(
            'allowlistsecret123',
            Dumper::toText($builder, [Dumper::KEYS_TO_HIDE => Debugger::$keysToHide]),
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSetCookieLinesAreMaskedInADumpOfAResponse(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: true);
        $response = Response::text('ok')->withCookie('app_session', 'sessionid0123456789');

        self::assertStringNotContainsString(
            'sessionid0123456789',
            Dumper::toText($response, [Dumper::KEYS_TO_HIDE => Debugger::$keysToHide]),
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testInstallingTheSecretMaskingDoesNotEnableTracyNorTouchTheHandlers(): void
    {
        $errorHandler = static fn (int $no, string $str): bool => false;
        $exceptionHandler = static function (\Throwable $e): void {
        };
        set_error_handler($errorHandler);
        set_exception_handler($exceptionHandler);

        DebugIntegration::installSecretMasking();

        $currentError = set_error_handler(static fn (int $no, string $str): bool => false);
        restore_error_handler();
        $currentException = set_exception_handler(null);
        restore_exception_handler();

        self::assertFalse(Debugger::isEnabled());
        self::assertSame($errorHandler, $currentError);
        self::assertSame($exceptionHandler, $currentException);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheMaskingHidesSecretsInADumpWithoutTracyEnabled(): void
    {
        $value = 'value-that-must-not-appear';
        $section = new class(['api' => ['signing' => $value, 'host' => 'visible-host']]) extends ConfigSection {
            protected array $secretKeys = ['api.signing'];
        };

        DebugIntegration::installSecretMasking('app_session_name');
        $options = [Dumper::KEYS_TO_HIDE => Debugger::$keysToHide];

        $array = Dumper::toText(['password' => $value, 'app_session_name' => $value], $options);
        $dumpedSection = Dumper::toText($section, $options);

        self::assertFalse(Debugger::isEnabled());
        self::assertStringNotContainsString($value, $array);
        self::assertStringNotContainsString($value, $dumpedSection);
        self::assertStringContainsString('visible-host', $dumpedSection);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASecondInstallationDoesNotComposeTheScrubberAgain(): void
    {
        DebugIntegration::installSecretMasking();
        $first = Debugger::getBlueScreen()->scrubber;

        DebugIntegration::installSecretMasking();

        self::assertNotNull($first);
        self::assertSame($first, Debugger::getBlueScreen()->scrubber);
    }

    public function testDebugFalseStillInitializesNothing(): void
    {
        // Guards the one property that matters: with debug off, Tracy's
        // enable() never runs, so it never flips display_errors or
        // zend.exception_ignore_args behind the application's back.
        $before = ini_get('zend.exception_ignore_args');

        DebugIntegration::initialize(debug: false);

        self::assertSame($before, ini_get('zend.exception_ignore_args'));
    }
}
