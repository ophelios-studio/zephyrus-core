<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
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

    // ── regenerate (no-op in override mode) ───────────────────────────────────

    public function testRegenerateIsNoOpInOverrideMode(): void
    {
        $session = $this->makeSession(['key' => 'value']);

        // Must not throw; data must be preserved.
        $session->regenerate();

        self::assertSame('value', $session->get('key'));
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
}
