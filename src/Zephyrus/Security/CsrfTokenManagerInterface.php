<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Contract for CSRF token storage and validation.
 *
 * Generates, stores and verifies the synchronizer token. SessionCsrfTokenManager
 * (Zephyrus\Session) is the session-backed default; any store works as long as:
 *
 *   - getToken()      returns the same token for the duration of a user's session.
 *   - isTokenValid()  returns true only when $submitted matches the stored token,
 *                     compared in constant time (hash_equals()).
 *
 * Minimal in-memory implementation for tests:
 *
 *   $manager = new class('secret-fixed-token') implements CsrfTokenManagerInterface {
 *       public function __construct(private string $token) {}
 *       public function getToken(): string { return $this->token; }
 *       public function isTokenValid(string $submitted): bool {
 *           return hash_equals($this->token, $submitted);
 *       }
 *   };
 */
interface CsrfTokenManagerInterface
{
    /**
     * Return the current CSRF token for the active session / context.
     *
     * The token SHOULD be a cryptographically random string of sufficient
     * entropy (at least 128 bits, e.g. bin2hex(random_bytes(32))).
     */
    public function getToken(): string;

    /**
     * Return true if $submitted is a valid CSRF token.
     *
     * MUST use a constant-time comparison to prevent timing attacks.
     */
    public function isTokenValid(#[\SensitiveParameter] string $submitted): bool;
}
