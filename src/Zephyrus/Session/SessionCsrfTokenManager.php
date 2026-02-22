<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Security\CsrfTokenManagerInterface;

/**
 * Session-backed implementation of CsrfTokenManagerInterface.
 *
 * Tokens are stored in the session under a configurable key (default:
 * "_csrf_token"). A new cryptographically-random token is generated lazily
 * the first time getToken() is called, and is then reused for the lifetime
 * of the session. This follows the "Synchronizer Token" pattern described by
 * OWASP.
 *
 * Token validation uses hash_equals() for a constant-time comparison to
 * prevent timing-based oracle attacks.
 *
 * ## Regenerating after authentication events
 *
 * Call regenerate() when a user logs in or their privilege level changes. This
 * rotates the CSRF token so that any token captured by an attacker before the
 * privilege change becomes invalid:
 *
 *   $csrfManager->regenerate();
 *   $sessionManager->regenerate(); // also rotate the session ID
 *
 * ## Usage with CsrfMiddleware
 *
 *   $csrfManager = new SessionCsrfTokenManager($sessionManager);
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new CsrfMiddleware($csrfManager))
 *       ->build();
 *
 *   // Embed in templates:
 *   <input type="hidden" name="_csrf_token" value="<?= $csrfManager->getToken() ?>">
 */
final class SessionCsrfTokenManager implements CsrfTokenManagerInterface
{
    public function __construct(
        private readonly SessionManager $session,
        private readonly string $sessionKey = '_csrf_token',
    ) {
    }

    /**
     * Return the current CSRF token for the session.
     *
     * If no token exists yet a new 64-character hex token is generated
     * (256 bits of entropy from random_bytes(32)) and stored in the session.
     */
    public function getToken(): string
    {
        if (!$this->session->has($this->sessionKey)) {
            $this->session->set($this->sessionKey, bin2hex(random_bytes(32)));
        }

        return (string) $this->session->get($this->sessionKey);
    }

    /**
     * Return true when $submitted exactly matches the stored CSRF token.
     *
     * Uses hash_equals() for a constant-time comparison.
     */
    public function isTokenValid(string $submitted): bool
    {
        if (!$this->session->has($this->sessionKey)) {
            return false;
        }

        return hash_equals($this->getToken(), $submitted);
    }

    /**
     * Discard the current token so the next getToken() call generates a fresh one.
     *
     * Call this after authentication events (login, privilege escalation) to
     * invalidate any previously issued tokens.
     */
    public function regenerate(): void
    {
        $this->session->remove($this->sessionKey);
    }
}
