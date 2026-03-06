<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Zephyrus\Security\CsrfTokenManagerInterface;
use Zephyrus\Session\SessionCsrfTokenManager;
use Zephyrus\Session\SessionException;
use Zephyrus\Session\SessionManager;

final class SessionCsrfTokenManagerTest extends TestCase
{
    // ── factory helpers ───────────────────────────────────────────────────────

    private function makeManager(?string $sessionKey = null): SessionCsrfTokenManager
    {
        $session = new SessionManager([]);
        $args    = [$session];

        if ($sessionKey !== null) {
            $args[] = $sessionKey;
        }

        return new SessionCsrfTokenManager(...$args);
    }

    private function makeManagerWithSession(SessionManager $session, ?string $key = null): SessionCsrfTokenManager
    {
        return $key !== null
            ? new SessionCsrfTokenManager($session, $key)
            : new SessionCsrfTokenManager($session);
    }

    // ── implements interface ──────────────────────────────────────────────────

    public function testImplementsCsrfTokenManagerInterface(): void
    {
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $this->makeManager());
    }

    // ── getToken ──────────────────────────────────────────────────────────────

    public function testGetTokenReturnsNonEmptyString(): void
    {
        $manager = $this->makeManager();

        self::assertNotEmpty($manager->getToken());
    }

    public function testGetTokenReturns64CharHexString(): void
    {
        $manager = $this->makeManager();
        $token   = $manager->getToken();

        // 32 bytes → 64 hex characters
        self::assertSame(64, strlen($token));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testGetTokenReturnsSameValueOnSubsequentCalls(): void
    {
        $manager = $this->makeManager();
        $first   = $manager->getToken();
        $second  = $manager->getToken();

        self::assertSame($first, $second);
    }

    // ── isTokenValid ──────────────────────────────────────────────────────────

    public function testIsTokenValidReturnsTrueForCorrectToken(): void
    {
        $manager = $this->makeManager();
        $token   = $manager->getToken();

        self::assertTrue($manager->isTokenValid($token));
    }

    public function testIsTokenValidReturnsFalseForWrongToken(): void
    {
        $manager = $this->makeManager();
        $manager->getToken(); // Ensure token is generated.

        self::assertFalse($manager->isTokenValid('wrong-token'));
    }

    public function testIsTokenValidReturnsFalseForEmptyString(): void
    {
        $manager = $this->makeManager();
        $manager->getToken();

        self::assertFalse($manager->isTokenValid(''));
    }

    public function testIsTokenValidReturnsFalseWhenNoTokenGenerated(): void
    {
        // New manager with empty session — no token stored yet.
        $manager = $this->makeManager();

        // isTokenValid() before getToken() — nothing stored.
        self::assertFalse($manager->isTokenValid('anything'));
    }

    public function testIsTokenValidReturnsFalseForPartialTokenMatch(): void
    {
        $manager = $this->makeManager();
        $token   = $manager->getToken();

        self::assertFalse($manager->isTokenValid(substr($token, 0, 32)));
    }

    // ── regenerate ────────────────────────────────────────────────────────────

    public function testRegenerateDiscardsPreviousToken(): void
    {
        $manager = $this->makeManager();
        $first   = $manager->getToken();

        $manager->regenerate();
        $second = $manager->getToken();

        // New token should be different (probability of collision is negligible).
        self::assertNotSame($first, $second);
    }

    public function testRegenerateInvalidatesPreviousToken(): void
    {
        $manager = $this->makeManager();
        $old     = $manager->getToken();

        $manager->regenerate();

        // The old token must no longer be valid.
        self::assertFalse($manager->isTokenValid($old));
    }

    public function testNewTokenIsValidAfterRegenerate(): void
    {
        $manager = $this->makeManager();
        $manager->getToken(); // Generate first token.
        $manager->regenerate();

        $newToken = $manager->getToken();

        self::assertTrue($manager->isTokenValid($newToken));
    }

    // ── custom session key ────────────────────────────────────────────────────

    public function testCustomSessionKeyStoresTokenUnderThatKey(): void
    {
        $session = new SessionManager([]);
        $manager = $this->makeManagerWithSession($session, 'my_csrf');

        $token = $manager->getToken();

        // Token must be accessible under the custom key in the session.
        self::assertSame($token, $session->get('my_csrf'));
    }

    public function testDefaultSessionKeyIsUnderscoredCsrfToken(): void
    {
        $session = new SessionManager([]);
        $manager = $this->makeManagerWithSession($session);

        $token = $manager->getToken();

        self::assertSame($token, $session->get('_csrf_token'));
    }

    public function testTwoManagersWithDifferentKeysDoNotInterfere(): void
    {
        $session  = new SessionManager([]);
        $manager1 = new SessionCsrfTokenManager($session, 'csrf_a');
        $manager2 = new SessionCsrfTokenManager($session, 'csrf_b');

        $token1 = $manager1->getToken();
        $token2 = $manager2->getToken();

        // Each manager should see only its own token.
        self::assertTrue($manager1->isTokenValid($token1));
        self::assertTrue($manager2->isTokenValid($token2));

        // Cross-validation must fail.
        self::assertFalse($manager1->isTokenValid($token2));
        self::assertFalse($manager2->isTokenValid($token1));
    }

    // ── session persistence ───────────────────────────────────────────────────

    public function testTokenPersistedInSessionIsReusedByAnotherManagerInstance(): void
    {
        $session  = new SessionManager([]);
        $manager1 = new SessionCsrfTokenManager($session);
        $token    = $manager1->getToken();

        // Simulate a second request that reuses the same session object.
        $manager2 = new SessionCsrfTokenManager($session);

        self::assertSame($token, $manager2->getToken());
        self::assertTrue($manager2->isTokenValid($token));
    }

    // ── uses existing session token ───────────────────────────────────────────

    public function testGetTokenUsesPreExistingSessionValue(): void
    {
        // Pre-seed the session with a known token (simulates a reloaded session).
        $session = new SessionManager(['_csrf_token' => 'pre-seeded-token-abc123']);
        $manager = new SessionCsrfTokenManager($session);

        self::assertSame('pre-seeded-token-abc123', $manager->getToken());
    }

    public function testEmptyCustomSessionKeyThrowsSessionException(): void
    {
        $session = new SessionManager([]);

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Session key must be a non-empty string.');

        new SessionCsrfTokenManager($session, '');
    }
}
