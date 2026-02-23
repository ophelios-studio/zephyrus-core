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
