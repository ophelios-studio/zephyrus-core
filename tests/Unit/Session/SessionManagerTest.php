<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Zephyrus\Session\SessionException;
use Zephyrus\Session\SessionManager;

/**
 * All tests use the override-storage constructor so no real PHP session is
 * started, making the suite fast and isolation-free.
 */
final class SessionManagerTest extends TestCase
{
    // ── factory helper ────────────────────────────────────────────────────────

    private function makeSession(array $initial = []): SessionManager
    {
        return new SessionManager($initial);
    }

    // ── isStarted ─────────────────────────────────────────────────────────────

    public function testIsStartedReturnsTrueWithOverrideStorage(): void
    {
        $session = $this->makeSession();

        self::assertTrue($session->isStarted());
    }

    // ── set / get ─────────────────────────────────────────────────────────────

    public function testSetAndGetReturnsStoredValue(): void
    {
        $session = $this->makeSession();
        $session->set('user', 'alice');

        self::assertSame('alice', $session->get('user'));
    }

    public function testGetReturnsDefaultWhenKeyAbsent(): void
    {
        $session = $this->makeSession();

        self::assertNull($session->get('missing'));
        self::assertSame('default', $session->get('missing', 'default'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $session = $this->makeSession(['counter' => 1]);
        $session->set('counter', 99);

        self::assertSame(99, $session->get('counter'));
    }

    public function testInitialStorageValuesAreAccessible(): void
    {
        $session = $this->makeSession(['role' => 'admin', 'locale' => 'en']);

        self::assertSame('admin', $session->get('role'));
        self::assertSame('en', $session->get('locale'));
    }

    // ── has ───────────────────────────────────────────────────────────────────

    public function testHasReturnsTrueForExistingKey(): void
    {
        $session = $this->makeSession(['x' => 42]);

        self::assertTrue($session->has('x'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $session = $this->makeSession();

        self::assertFalse($session->has('x'));
    }

    public function testHasReturnsTrueForNullValue(): void
    {
        $session = $this->makeSession();
        $session->set('nullable', null);

        // Key exists even though the value is null.
        self::assertTrue($session->has('nullable'));
    }

    // ── remove ────────────────────────────────────────────────────────────────

    public function testRemoveDeletesExistingKey(): void
    {
        $session = $this->makeSession(['token' => 'abc']);
        $session->remove('token');

        self::assertFalse($session->has('token'));
    }

    public function testRemoveIsNoOpForMissingKey(): void
    {
        $session = $this->makeSession();

        // Must not throw.
        $session->remove('non_existent');

        self::assertFalse($session->has('non_existent'));
    }

    // ── all ───────────────────────────────────────────────────────────────────

    public function testAllReturnsEmptyArrayWhenNoData(): void
    {
        $session = $this->makeSession();

        self::assertSame([], $session->all());
    }

    public function testAllReturnsAllStoredData(): void
    {
        $session = $this->makeSession(['a' => 1, 'b' => 2]);
        $session->set('c', 3);

        self::assertSame(['a' => 1, 'b' => 2, 'c' => 3], $session->all());
    }

    // ── flash ─────────────────────────────────────────────────────────────────

    public function testFlashReturnsValueAndRemovesKey(): void
    {
        $session = $this->makeSession(['notice' => 'Saved!']);

        $value = $session->flash('notice');

        self::assertSame('Saved!', $value);
        self::assertFalse($session->has('notice'));
    }

    public function testFlashReturnsDefaultWhenKeyMissing(): void
    {
        $session = $this->makeSession();

        self::assertNull($session->flash('missing'));
        self::assertSame('fallback', $session->flash('missing', 'fallback'));
    }

    public function testFlashDoesNotAffectOtherKeys(): void
    {
        $session = $this->makeSession(['notice' => 'ok', 'user' => 'alice']);
        $session->flash('notice');

        self::assertSame('alice', $session->get('user'));
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function testDestroyEmptiesOverrideStorage(): void
    {
        $session = $this->makeSession(['a' => 1, 'b' => 2]);
        $session->destroy();

        self::assertSame([], $session->all());
        self::assertTrue($session->isStarted()); // Still "started" (override mode)
    }

    // ── regenerate rotates the simulated id in override mode ──────────────────

    /**
     * Replaces testRegenerateIsNoOpInOverrideMode, which pinned the no-op as
     * intended behaviour.
     *
     * Override mode reported isStarted() = true while start(), setHandler() and
     * regenerate() all did nothing, so a consumer test asserting "login rotates
     * the session id" passed against an implementation that rotated nothing.
     * That is the same shape as the ini-flag bug this repo already fixed once:
     * a security-relevant step reads as done and is not.
     */
    public function testRegenerateRotatesTheSimulatedIdInOverrideModeAndKeepsTheData(): void
    {
        $session = $this->makeSession(['key' => 'value']);
        $before  = $session->id();

        $session->regenerate();

        self::assertNotSame($before, $session->id(), 'the id must actually rotate');
        self::assertNotSame('', $session->id());
        self::assertSame('value', $session->get('key'), 'regeneration keeps the data, like session_regenerate_id()');
    }

    public function testOverrideModeExposesASimulatedSessionId(): void
    {
        $session = $this->makeSession();

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $session->id());
    }

    public function testTwoOverrideSessionsDoNotShareAnId(): void
    {
        self::assertNotSame($this->makeSession()->id(), $this->makeSession()->id());
    }

    public function testDestroyClearsTheSimulatedIdInOverrideMode(): void
    {
        $session = $this->makeSession(['a' => 1]);
        $session->destroy();

        self::assertSame('', $session->id());
    }

    public function testStartMintsASimulatedIdAgainAfterDestroy(): void
    {
        $session = $this->makeSession();
        $session->destroy();

        $session->start(\Zephyrus\Core\Config\SessionConfig::fromArray([]));

        self::assertNotSame('', $session->id());
    }

    /**
     * Recorded, never registered: override mode opens no real PHP session, so
     * there is nothing for PHP to call. Recording it is what lets a consumer
     * test assert the wiring it just built.
     */
    public function testSetHandlerRecordsTheHandlerInOverrideModeWithoutRegisteringIt(): void
    {
        $session = $this->makeSession();
        $handler = new OverrideModeHandler();

        $statusBefore = session_status();
        $session->setHandler($handler);

        self::assertSame($handler, $session->handler());
        self::assertSame($statusBefore, session_status(), 'no real session may have been opened');
    }

    // ── start (no-op in override mode) ────────────────────────────────────────

    public function testStartIsNoOpInOverrideMode(): void
    {
        $session = $this->makeSession();

        // Provide a default config without triggering session_start().
        $config = \Zephyrus\Core\Config\SessionConfig::fromArray([]);
        $session->start($config);

        // Must survive without error.
        self::assertTrue($session->isStarted());
    }

    // ── type variety ──────────────────────────────────────────────────────────

    public function testStoresAndReturnsDifferentTypes(): void
    {
        $session = $this->makeSession();
        $session->set('int',   42);
        $session->set('float', 3.14);
        $session->set('bool',  true);
        $session->set('array', [1, 2, 3]);
        $session->set('null',  null);

        self::assertSame(42,        $session->get('int'));
        self::assertSame(3.14,      $session->get('float'));
        self::assertTrue($session->get('bool'));
        self::assertSame([1, 2, 3], $session->get('array'));
        self::assertNull($session->get('null'));
    }

    public function testEmptySessionKeyThrowsConsistentSessionException(): void
    {
        $session = $this->makeSession();

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Session key must be a non-empty string.');

        $session->set('', 'value');
    }
}

/** Minimal handler used to prove setHandler() records without registering. */
final class OverrideModeHandler implements \SessionHandlerInterface
{
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    public function read(string $id): string|false { return ''; }
    public function write(string $id, string $data): bool { return true; }
    public function destroy(string $id): bool { return true; }
    public function gc(int $maxLifetime): int|false { return 0; }
}
