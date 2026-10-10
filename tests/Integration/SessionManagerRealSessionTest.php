<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Session\SessionException;
use Zephyrus\Session\SessionManager;
use Zephyrus\Tests\Support\IsolatedSessionSavePath;

/**
 * Integration tests for SessionManager against real PHP sessions.
 *
 * Tests touching real session state run in a separate process, so no state leaks between them.
 */
final class SessionManagerRealSessionTest extends TestCase
{
    use IsolatedSessionSavePath;

    protected function setUp(): void
    {
        $this->useIsolatedSessionSavePath();
    }

    protected function tearDown(): void
    {
        $this->removeIsolatedSessionSavePath();
    }

    // ── isStarted ─────────────────────────────────────────────────────────────

    public function testIsStartedReturnsFalseWhenNoSessionStarted(): void
    {
        // No session started and no override storage: session_status() decides.
        $session = new SessionManager();

        self::assertFalse($session->isStarted());
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    // ── setHandler ────────────────────────────────────────────────────────────

    /** PHP refuses to swap the save handler while a session is active, and that refusal must surface. */
    #[RunInSeparateProcess]
    public function testSetHandlerThrowsWhenPhpRefusesTheHandlerBecauseASessionIsActive(): void
    {
        session_start();

        $session = new SessionManager();
        $thrown  = null;

        try {
            $session->setHandler(new StrictModeAwareHandler());
        } catch (SessionException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(SessionException::class, $thrown);
        self::assertNull($session->handler(), 'a handler PHP refused must not be kept');
        self::assertSame('files', ini_get('session.save_handler'));
    }

    // ── start ─────────────────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testStartConfiguresAndStartsRealSession(): void
    {
        $session = new SessionManager();
        $config  = SessionConfig::fromArray([]);

        $session->start($config);

        self::assertTrue($session->isStarted());
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    #[RunInSeparateProcess]
    public function testStartEnablesStrictSessionIdMode(): void
    {
        $session = new SessionManager();

        $session->start(SessionConfig::fromArray([]));

        self::assertSame('1', ini_get('session.use_strict_mode'));
    }

    #[RunInSeparateProcess]
    public function testStartRefusesToAdoptAClientSuppliedSessionId(): void
    {
        // PHP defaults use_strict_mode to 0, which adopts any client-supplied id (session fixation).
        $planted = bin2hex(random_bytes(16));
        session_id($planted);

        $session = new SessionManager();
        $session->start(SessionConfig::fromArray([]));

        self::assertNotSame($planted, session_id(), 'an unknown client-supplied id must be discarded');
        self::assertNotSame('', session_id());
    }

    /**
     * PHP skips the use_strict_mode check for a handler without validateId(),
     * so start() warns in debug when the handler cannot honour it.
     */
    #[RunInSeparateProcess]
    public function testStartWarnsInDebugWhenTheHandlerCannotHonourStrictMode(): void
    {
        \Zephyrus\Core\App::setConfiguration(
            \Zephyrus\Core\Config\Configuration::fromArray(['application' => ['debug' => true]]),
        );

        $session = new SessionManager();
        $session->setHandler(new StrictModeBlindHandler());

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $session->start(SessionConfig::fromArray([]));
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('SessionUpdateTimestampHandlerInterface', $warnings[0]);
        self::assertStringContainsString('ADOPT a client-supplied session id', $warnings[0]);
    }

    #[RunInSeparateProcess]
    public function testStartDoesNotWarnForAHandlerThatValidatesIds(): void
    {
        \Zephyrus\Core\App::setConfiguration(
            \Zephyrus\Core\Config\Configuration::fromArray(['application' => ['debug' => true]]),
        );

        $session = new SessionManager();
        $session->setHandler(new StrictModeAwareHandler());

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $session->start(SessionConfig::fromArray([]));
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
    }

    // ── the Secure cookie attribute ───────────────────────────────────────────

    /** The default secure setting is "auto": an HTTPS request gets the Secure attribute without configuration. */
    #[RunInSeparateProcess]
    public function testStartSetsSecureWhenTheRequestArrivedOverHttps(): void
    {
        $session = new SessionManager();

        $session->start(SessionConfig::fromArray([]), requestIsSecure: true);

        self::assertTrue(session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    public function testStartLeavesTheCookieInsecureOnAPlainHttpRequest(): void
    {
        $session = new SessionManager();

        $session->start(SessionConfig::fromArray([]), requestIsSecure: false);

        self::assertFalse(session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    public function testAnExplicitFalseIsHonouredEvenOverHttps(): void
    {
        $session = new SessionManager();

        $session->start(SessionConfig::fromArray(['secure' => false]), requestIsSecure: true);

        self::assertFalse(session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    public function testTheOtherCookieAttributesAreUnchanged(): void
    {
        $session = new SessionManager();

        $session->start(SessionConfig::fromArray([]), requestIsSecure: true);
        $params = session_get_cookie_params();

        self::assertTrue($params['httponly']);
        self::assertSame('Lax', $params['samesite']);
        self::assertSame('/', $params['path']);
        self::assertSame('', $params['domain'], 'the cookie stays host-only');
    }

    /** Through the middleware: the request's own scheme decides the Secure attribute. */
    #[RunInSeparateProcess]
    public function testTheMiddlewareGivesAnHttpsRequestASecureSessionCookie(): void
    {
        $middleware = new \Zephyrus\Session\SessionMiddleware(SessionConfig::fromArray([]));

        $middleware->process(
            new \Zephyrus\Http\Request('GET', 'https://example.com/dashboard'),
            static fn (): \Zephyrus\Http\Response => \Zephyrus\Http\Response::text('ok'),
        );

        self::assertTrue(session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    public function testTheMiddlewareLeavesAPlainHttpRequestInsecure(): void
    {
        $middleware = new \Zephyrus\Session\SessionMiddleware(SessionConfig::fromArray([]));

        $middleware->process(
            new \Zephyrus\Http\Request('GET', 'http://localhost:8080/dashboard'),
            static fn (): \Zephyrus\Http\Response => \Zephyrus\Http\Response::text('ok'),
        );

        self::assertFalse(session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    public function testStartIsIdempotentWhenSessionAlreadyActive(): void
    {
        session_start();

        $session = new SessionManager();
        $config  = SessionConfig::fromArray([]);

        // A second start must silently return without double-starting.
        $session->start($config);

        self::assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    // ── set / get via $_SESSION ────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testSetWritesToSessionSuperglobal(): void
    {
        session_start();

        $session = new SessionManager();
        $session->set('role', 'admin');

        self::assertSame('admin', $_SESSION['role']);
    }

    #[RunInSeparateProcess]
    public function testGetReadsFromSessionSuperglobal(): void
    {
        session_start();
        $_SESSION['locale'] = 'fr';

        $session = new SessionManager();

        self::assertSame('fr', $session->get('locale'));
        self::assertNull($session->get('missing'));
        self::assertSame('en', $session->get('missing', 'en'));
    }

    // ── has via $_SESSION ─────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testHasChecksSessionSuperglobal(): void
    {
        session_start();
        $_SESSION['x'] = 42;

        $session = new SessionManager();

        self::assertTrue($session->has('x'));
        self::assertFalse($session->has('y'));
    }

    // ── remove via $_SESSION ──────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testRemoveDeletesFromSessionSuperglobal(): void
    {
        session_start();
        $_SESSION['token'] = 'abc123';

        $session = new SessionManager();
        $session->remove('token');

        self::assertFalse(array_key_exists('token', $_SESSION));
    }

    // ── all via $_SESSION ─────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testAllReturnsSessionSuperglobal(): void
    {
        session_start();
        $_SESSION = ['a' => 1, 'b' => 2];

        $session = new SessionManager();

        self::assertSame(['a' => 1, 'b' => 2], $session->all());
    }

    // ── start refusal ─────────────────────────────────────────────────────────

    /** start() must throw once output has been sent, before any ini or cookie setting warns with file paths. */
    #[RunInSeparateProcess]
    public function testStartThrowsWithoutAWarningOnceOutputIsSent(): void
    {
        $session = new SessionManager();
        $this->sendOutputToBrowser();

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        $thrown = null;
        try {
            $session->start(SessionConfig::fromArray([]));
        } catch (SessionException $exception) {
            $thrown = $exception;
        } finally {
            restore_error_handler();
        }

        self::assertInstanceOf(SessionException::class, $thrown);
        self::assertSame([], $warnings);
        self::assertSame(PHP_SESSION_NONE, session_status());
        self::assertStringContainsString('output has already been sent', $thrown->getMessage());
        self::assertStringContainsString('cookie', $thrown->getMessage());
        self::assertStringNotContainsString(__FILE__, $thrown->getMessage());
        self::assertStringContainsString('headers', (string) $thrown->phpReason());
        self::assertInstanceOf(\ErrorException::class, $thrown->getPrevious());
    }

    #[RunInSeparateProcess]
    public function testStartMessageNamesAHandlerThatCouldNotOpenOrReadTheSession(): void
    {
        $session = new SessionManager();
        $session->setHandler(new ReadRefusingHandler());

        $thrown = null;
        try {
            $session->start(SessionConfig::fromArray([]));
        } catch (SessionException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(SessionException::class, $thrown);
        self::assertStringContainsString('open or read', $thrown->getMessage());
        self::assertStringContainsString('output', $thrown->getMessage());
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testDestroyEmptiesRealSessionAndClosesIt(): void
    {
        session_start();
        $_SESSION = ['data' => 'here'];

        $session = new SessionManager();
        $session->destroy();

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testDestroyIsNoOpWhenNoSessionActive(): void
    {
        $session = new SessionManager();

        // Must not throw when no session is running.
        $session->destroy();

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    #[RunInSeparateProcess]
    public function testDestroyIsIdempotentOnceTheSessionIsDestroyed(): void
    {
        session_start();

        $session = new SessionManager();
        $session->destroy();
        $session->destroy();

        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    /** destroy() must fail when the stored session survives, since its id stays live. */
    #[RunInSeparateProcess]
    public function testDestroyThrowsWhenTheSessionIsClosedButStillHasAnId(): void
    {
        $handler = new RecordingDestroyHandler();
        $session = new SessionManager();
        $session->setHandler($handler);
        $session->start(SessionConfig::fromArray([]));
        $session->set('user', 7);
        session_write_close();

        $this->expectException(SessionException::class);

        try {
            $session->destroy();
        } finally {
            self::assertSame(0, $handler->destroyCalls, 'the stored session must not be touched');
        }
    }

    // ── regenerate ────────────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testRegenerateRotatesSessionIdWhenActive(): void
    {
        session_start();
        $before = session_id();

        $session = new SessionManager();
        $session->regenerate();

        // Session must still be active after regeneration, under a new id.
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertNotSame('', session_id());
        self::assertNotSame($before, session_id());
    }

    /** Regenerating without an active session must throw, not report a rotation. */
    #[RunInSeparateProcess]
    public function testRegenerateThrowsWhenNoSessionIsActive(): void
    {
        $session = new SessionManager();

        $this->expectException(SessionException::class);

        $session->regenerate();
    }

    /** A false from the handler's destroy() must fail regenerate(), since PHP keeps the old session. */
    #[RunInSeparateProcess]
    public function testRegenerateThrowsWhenTheSaveHandlerRefusesToDestroyTheOldSession(): void
    {
        $session = new SessionManager();
        $session->setHandler(new DestroyRefusingHandler());
        $session->start(SessionConfig::fromArray([]));

        $this->expectException(SessionException::class);

        $session->regenerate(true);
    }

    /** With regenerate(false) the old row is kept and PHP writes through the handler, so a refused write must be named. */
    #[RunInSeparateProcess]
    public function testRegenerateMessageNamesAHandlerThatRefusesToWrite(): void
    {
        $session = new SessionManager();
        $session->setHandler(new WriteRefusingHandler());
        $session->start(SessionConfig::fromArray([]));

        $thrown = null;
        try {
            $session->regenerate(false);
        } catch (SessionException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(SessionException::class, $thrown);
        self::assertStringContainsString('write', $thrown->getMessage());
        self::assertStringContainsString('stored session may have been destroyed', $thrown->getMessage());
        self::assertStringContainsString('destroy', $thrown->getMessage());
        self::assertStringContainsString('output', $thrown->getMessage());
    }

    /** Regenerating after output was sent must throw: the new id could not reach the browser in a cookie. */
    #[RunInSeparateProcess]
    public function testRegenerateThrowsWhenOutputHasAlreadyBeenSent(): void
    {
        $session = new SessionManager();
        $session->start(SessionConfig::fromArray([]));
        $before = session_id();
        $this->sendOutputToBrowser();

        $thrown = null;
        try {
            $session->regenerate();
        } catch (SessionException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(SessionException::class, $thrown);
        self::assertSame($before, session_id(), 'the id must not change when PHP refused');
    }

    /**
     * The PHP warning text stays out of the exception message, since the message reaches logs and pages;
     * it is kept on phpReason() and the chained ErrorException.
     */
    #[RunInSeparateProcess]
    public function testRegenerateKeepsPhpReasonOffTheMessageButExposesIt(): void
    {
        $session = new SessionManager();
        $session->start(SessionConfig::fromArray([]));
        $this->sendOutputToBrowser();

        $thrown = null;
        try {
            $session->regenerate();
        } catch (SessionException $exception) {
            $thrown = $exception;
        }

        self::assertInstanceOf(SessionException::class, $thrown);
        self::assertStringContainsString('headers', (string) $thrown->phpReason());
        self::assertStringNotContainsString('headers', $thrown->getMessage());
        self::assertInstanceOf(\ErrorException::class, $thrown->getPrevious());
        self::assertSame(E_WARNING, $thrown->getPrevious()->getSeverity());
        self::assertStringNotContainsString(__FILE__, $thrown->getMessage());
    }

    // ── destroy refusal ───────────────────────────────────────────────────────

    /** A false from the handler's destroy() must reach the caller, or a logout leaves the stored session usable. */
    #[RunInSeparateProcess]
    public function testDestroyThrowsWhenTheSaveHandlerRefusesToDestroy(): void
    {
        $session = new SessionManager();
        $session->setHandler(new DestroyRefusingHandler());
        $session->start(SessionConfig::fromArray([]));

        $this->expectException(SessionException::class);

        $session->destroy();
    }

    /** Flushes every output buffer so the headers count as sent, then reopens them, since PHPUnit flags tests that close a buffer. */
    private function sendOutputToBrowser(): void
    {
        $levels = ob_get_level();
        ob_start();
        echo 'output';
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        while (ob_get_level() < $levels) {
            ob_start();
        }
    }
}

/** A handler whose destroy() always fails. */
final class DestroyRefusingHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return false; }
    public function gc(int $maxLifetime): int|false { return 0; }
}

/** A plain handler: PHP skips its strict-mode check for this shape. */
final class StrictModeBlindHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $maxLifetime): int|false { return 0; }
}

/** Supplies validateId(), so use_strict_mode is actually honoured. */
final class StrictModeAwareHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $maxLifetime): int|false { return 0; }
    public function validateId(string $id): bool { return false; }
    public function updateTimestamp(string $id, string $data): bool { return true; }
}

/** Records destroy() calls and succeeds, to prove a session was never destroyed. */
final class RecordingDestroyHandler implements \SessionHandlerInterface
{
    public int $destroyCalls = 0;

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }

    public function destroy(string $id): bool
    {
        ++$this->destroyCalls;

        return true;
    }

    public function gc(int $maxLifetime): int|false { return 0; }
}

/** A handler whose write() always fails. */
final class WriteRefusingHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return false; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $maxLifetime): int|false { return 0; }
}

/** A handler whose read() always fails, as when its database is unreachable. */
final class ReadRefusingHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return false; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $maxLifetime): int|false { return 0; }
}
