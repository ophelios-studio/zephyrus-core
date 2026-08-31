<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\SessionConfig;
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
        $planted = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
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

    // ── regenerate ────────────────────────────────────────────────────────────

    #[RunInSeparateProcess]
    public function testRegenerateRotatesSessionIdWhenActive(): void
    {
        session_start();

        $session = new SessionManager();
        $session->regenerate();

        // Session must still be active after regeneration.
        self::assertSame(PHP_SESSION_ACTIVE, session_status());
        self::assertNotEmpty(session_id());
    }

    #[RunInSeparateProcess]
    public function testRegenerateIsNoOpWhenNoSessionActive(): void
    {
        $session = new SessionManager();

        // Must not throw when no session is running.
        $session->regenerate();

        self::assertSame(PHP_SESSION_NONE, session_status());
    }
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
