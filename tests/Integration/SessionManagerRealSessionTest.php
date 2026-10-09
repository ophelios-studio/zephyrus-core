<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Session\SessionException;
use Zephyrus\Session\SessionManager;

/**
 * Integration tests for SessionManager using real PHP sessions.
 *
 * Each test that touches real session state runs in a dedicated process via
 * #[RunInSeparateProcess] so no session state leaks between tests.
 *
 * Together with SessionManagerTest (override-storage mode), these tests bring
 * SessionManager to 100% line coverage.
 */
final class SessionManagerRealSessionTest extends TestCase
{
    // ── isStarted ─────────────────────────────────────────────────────────────

    public function testIsStartedReturnsFalseWhenNoSessionStarted(): void
    {
        // No session started, no override storage → real session_status() check.
        $session = new SessionManager();

        self::assertFalse($session->isStarted());
        self::assertSame(PHP_SESSION_NONE, session_status());
    }

    // ── setHandler ────────────────────────────────────────────────────────────

    /**
     * PHP refuses to swap the save handler while a session is active. It warns
     * and answers false, and the old code kept the handler anyway, so the app
     * believed sessions went to the database while PHP kept writing them to
     * files.
     */
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
        // PHP defaults use_strict_mode to 0, which adopts and persists whatever
        // ID the client sends. That lets an unauthenticated caller seed session
        // IDs at will, and it is what makes session fixation possible.
        $planted = bin2hex(random_bytes(16));
        session_id($planted);

        $session = new SessionManager();
        $session->start(SessionConfig::fromArray([]));

        self::assertNotSame($planted, session_id(), 'an unknown client-supplied id must be discarded');
        self::assertNotSame('', session_id());
    }

    /**
     * The framework enabling a security-relevant ini flag that silently does
     * nothing is how this was missed the first time: PHP skips its
     * use_strict_mode check entirely for a handler without validateId(), so the
     * setting read as done while a client-supplied id was still adopted.
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

    /**
     * SessionConfig::fromArray([]) used to emit a session cookie with no Secure
     * attribute at all: "PHPSESSID=...; path=/; HttpOnly; SameSite=Lax". The
     * default is "auto" now, so an HTTPS request gets Secure without the
     * deployment having to remember.
     */
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

    /**
     * End to end through the middleware, which is where the request's own
     * scheme becomes the cookie attribute. Request resolved that scheme against
     * the trusted-header allowlist, so a forwarded protocol only counts when
     * the deployment declared the proxy that writes it.
     */
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

    /**
     * A closed session keeps its id while its row stays in the store. Reporting
     * a logout as done then would leave the stored session live.
     */
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

    /**
     * Rotating nothing is not a success: a caller that regenerates after login
     * believes the fixation defence ran, so a missing session must be loud.
     */
    #[RunInSeparateProcess]
    public function testRegenerateThrowsWhenNoSessionIsActive(): void
    {
        $session = new SessionManager();

        $this->expectException(SessionException::class);

        $session->regenerate();
    }

    /**
     * PHP refuses to delete the old session when the save handler's destroy()
     * returns false. The old code discarded that answer, so the caller
     * believed the old id was gone while the handler still held it.
     */
    #[RunInSeparateProcess]
    public function testRegenerateThrowsWhenTheSaveHandlerRefusesToDestroyTheOldSession(): void
    {
        $session = new SessionManager();
        $session->setHandler(new DestroyRefusingHandler());
        $session->start(SessionConfig::fromArray([]));

        $this->expectException(SessionException::class);

        $session->regenerate(true);
    }

    /**
     * With regenerate(false) the old row is kept, so PHP writes through the
     * handler, and a refused write is a cause the message must name.
     */
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
        self::assertStringContainsString('destroy', $thrown->getMessage());
        self::assertStringContainsString('output', $thrown->getMessage());
    }

    /**
     * PHP will not rotate an id once output has reached the browser, because
     * the new id could never be sent in a cookie. The old code reported success
     * anyway, so a login could finish with the pre-login id still live.
     */
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
     * PHP's warning says why it refused, and that text must not reach the
     * message, which travels to logs and pages. It stays on phpReason().
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
        self::assertStringNotContainsString(__FILE__, $thrown->getMessage());
    }

    // ── destroy refusal ───────────────────────────────────────────────────────

    /**
     * A logout that reports success while the stored session survives leaves
     * the attacker's copy of the cookie working. PHP answers false when the
     * handler cannot destroy the data, and that answer must reach the caller.
     */
    #[RunInSeparateProcess]
    public function testDestroyThrowsWhenTheSaveHandlerRefusesToDestroy(): void
    {
        $session = new SessionManager();
        $session->setHandler(new DestroyRefusingHandler());
        $session->start(SessionConfig::fromArray([]));

        $this->expectException(SessionException::class);

        $session->destroy();
    }

    /**
     * PHP's output warning is the only record of why it refused, so the helper
     * flushes every buffer level before PHP counts the headers as sent, then
     * reopens the levels: closing the runner's buffer is flagged as risky.
     */
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

/** A handler whose destroy() always fails, as a database handler can when its delete query fails. */
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

/** A handler whose write() always fails, as a database handler can when its update query fails. */
final class WriteRefusingHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return false; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $maxLifetime): int|false { return 0; }
}
