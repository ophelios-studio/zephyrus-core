<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tracy\Debugger;
use Zephyrus\Core\DebugIntegration;

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

        DebugIntegration::initialize(debug: true);

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

        DebugIntegration::initialize(debug: true);

        self::assertTrue(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testLoopbackStillGetsTheDebugger(): void
    {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        DebugIntegration::initialize(debug: true);

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

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheTracyDebugCookieGrantsAnOtherwiseRefusedClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $_COOKIE['tracy-debug'] = 'a-shared-secret';

        DebugIntegration::initialize(debug: true, allowedClients: 'a-shared-secret@203.0.113.77');

        self::assertFalse(Debugger::$productionMode);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheWrongCookieDoesNotGrantTheClient(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.77';
        $_COOKIE['tracy-debug'] = 'guessed';

        DebugIntegration::initialize(debug: true, allowedClients: 'a-shared-secret@203.0.113.77');

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
