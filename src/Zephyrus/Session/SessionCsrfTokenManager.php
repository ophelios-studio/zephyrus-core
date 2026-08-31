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
        if ($this->sessionKey === '') {
            throw SessionException::invalidKey($this->sessionKey);
        }
    }

    /**
     * Return the current CSRF token for the session.
     *
     * If no usable token is stored yet a new 64-character hex token is
     * generated (256 bits of entropy from random_bytes(32)) and stored in the
     * session.
     *
     * "Usable" means a non-empty string, not merely a key that exists. The
     * previous version asked array_key_exists() and then cast whatever it
     * found to string, so a stored null or empty string was served as the
     * token and compared equal to an empty submission.
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
     * Return true when $submitted exactly matches the stored CSRF token.
     *
     * Uses hash_equals() for a constant-time comparison.
     *
     * An empty submission and an unusable stored value are both refused before
     * the comparison. Without those two guards hash_equals('', '') answered
     * TRUE, so isTokenValid('') passed whenever the session held an empty or
     * null value under the token key: every wrong value was rejected except
     * the emptiest one.
     *
     * CsrfMiddleware never reaches that state because it refuses an empty
     * submission of its own before delegating, so the framework's own
     * composition was never exploitable and the precondition was not
     * demonstrated. This interface is public API documented for direct use,
     * which is why the guard belongs here as well.
     *
     * Deliberately does NOT call getToken(): a validation attempt must not
     * MINT and store a token as a side effect.
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

    /**
     * The stored token, or null when nothing usable is stored.
     */
    private function storedToken(): ?string
    {
        $stored = $this->session->get($this->sessionKey);

        return is_string($stored) && $stored !== '' ? $stored : null;
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
