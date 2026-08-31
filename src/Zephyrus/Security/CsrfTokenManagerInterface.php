<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Contract for CSRF token storage and validation.
 *
 * Implementations are responsible for generating, persisting, and verifying
 * the synchronizer token. The canonical implementation uses the session (see
 * SessionCsrfTokenManager in the Session module), but any backing store works
 * as long as:
 *
 *   - getToken()      returns the same token for the duration of a user's session.
 *   - isTokenValid()  returns true only when $submitted matches the stored token
 *                     via a timing-safe comparison.
 *
 * A timing-safe comparison MUST be used to prevent timing-based oracle attacks.
 * PHP's hash_equals() satisfies this requirement and is available since 5.6.
 *
 * Example minimal in-memory implementation (useful for tests):
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
