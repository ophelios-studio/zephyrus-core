<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Security\CsrfTokenManagerInterface;

/**
 * Session-backed implementation of CsrfTokenManagerInterface (synchronizer token pattern).
 *
 * The token is generated lazily by getToken() and reused for the lifetime of the session.
 * Call regenerate() together with SessionManager::regenerate() after a login or privilege change.
 * A refused token is forged, or the session is gone, or another sign-in rotated it; check whether the
 * person is still signed in to tell the last two apart.
 *
 *   $csrfManager = new SessionCsrfTokenManager($sessionManager);
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new CsrfMiddleware($csrfManager))
 *       ->build();
 */
final class SessionCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly string $sessionKey = '_csrf_token',
    ) {
        if ($this->sessionKey === '') {
            throw SessionException::invalidKey($this->sessionKey);
        }
    }

    /**
     * Return the session's CSRF token, generating and storing a 64-character hex token when none is usable.
     *
     * @throws SessionException when a new token must be stored and no session is active.
     */
    public function getToken(): string
    {
        $stored = $this->storedToken();

        if ($stored !== null) {
            return $stored;
        }

        $token = bin2hex(random_bytes(32));
        $this->session->set($this->sessionKey, $token);

        return $token;
    }

    /**
     * Return true when $submitted exactly matches the stored CSRF token, in constant time.
     *
     * An empty submission and an unusable stored value are refused before the comparison,
     * since hash_equals('', '') is true. Does not mint a token.
     */
    public function isTokenValid(#[\SensitiveParameter] string $submitted): bool
    {
        if ($submitted === '') {
            return false;
        }

        $stored = $this->storedToken();

        if ($stored === null) {
            return false;
        }

        return hash_equals($stored, $submitted);
    }

    /** The stored token, or null when nothing usable is stored. */
    private function storedToken(): ?string
    {
        $stored = $this->session->get($this->sessionKey);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * Discard the current token so the next getToken() call generates a fresh one.
     *
     * @throws SessionException when a token is stored and no session is active.
     */
    public function regenerate(): void
    {
        $this->session->remove($this->sessionKey);
    }
}
