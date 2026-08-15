<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Core\App;
use Zephyrus\Core\Config\SessionConfig;

/**
 * Thin, testable wrapper around PHP's native session functions.
 *
 * The constructor accepts an optional *override storage* array. When supplied,
 * all read/write operations target that array instead of $_SESSION, and
 * session_start() / session_destroy() / session_regenerate_id() are never
 * called. This makes the class trivially testable without spawning real PHP
 * sessions.
 *
 * In production, omit the override and call start() once at bootstrap:
 *
 *   $session = new SessionManager();
 *   $session->start($config->session);
 *
 * ## Flash values
 *
 * flash() reads and immediately removes the value in the same request — handy
 * for one-time status messages across a redirect.
 *
 * ## Thread-safety note
 *
 * PHP sessions are per-process and are not thread-safe across concurrent
 * requests that share the same session ID. That is a PHP platform concern, not
 * a framework concern; SessionManager does not add locking primitives on top.
 */
final class SessionManager
{
    /**
     * Injected backing store (used during tests to skip real PHP sessions).
     *
     * @var array<string, mixed>|null
     */
    private ?array $overrideStorage;

    /** The handler registered through setHandler(), when one was. */
    private ?\SessionHandlerInterface $handler = null;

    /**
     * @param array<string, mixed>|null $overrideStorage
     *   When non-null, all session data is read from / written to this array.
     *   start(), regenerate(), and destroy() become no-ops (or lightweight
     *   equivalents). Intended for unit tests only.
     */
    public function __construct(?array $overrideStorage = null)
    {
        $this->overrideStorage = $overrideStorage;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Register a custom session save handler.
     *
     * Must be called **before** start() so that PHP uses the handler when
     * opening the session. No-op in override-storage (test) mode.
     */
    public function setHandler(\SessionHandlerInterface $handler): void
    {
        if ($this->overrideStorage !== null) {
            return;
        }

        $this->handler = $handler;

        session_set_save_handler($handler, true);
    }

    /**
     * Configure PHP session parameters and start the session.
     *
     * Safe to call multiple times — returns immediately if a session is already
     * active. No-op when an override storage is active (test mode).
     *
     * Strict mode is forced on. PHP defaults session.use_strict_mode to 0,
     * which makes it ADOPT any session ID the client sends and persist a record
     * under it. That lets an unauthenticated caller seed session IDs of its own
     * choosing, one stored record per request, and it is the enabling condition
     * for session fixation: an attacker plants a known ID, gets the victim to
     * use it, and the ID survives login.
     *
     * ## The flag alone is NOT enough with a custom save handler
     *
     * PHP only consults use_strict_mode when the save handler supplies
     * validateId(), i.e. when it implements SessionUpdateTimestampHandlerInterface.
     * For a handler that does not, the flag is INERT and the client's id is
     * adopted verbatim. Measured:
     *
     *   strict mode on, plain handler       -> session_id() = attackerchosenid123
     *   strict mode on, validateId handler  -> session_id() = 43e880c2447c...
     *
     * PHP's built-in `files` handler implements the check internally, so a
     * default-configured application is covered by the flag alone. A custom
     * handler is not. DatabaseSessionHandler implements the interface for
     * exactly this reason; any other handler must do the same or this setting
     * buys it nothing. When debug is on, start() warns about a handler that
     * cannot honour it.
     */
    public function start(SessionConfig $config): void
    {
        if ($this->overrideStorage !== null) {
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        $this->warnIfHandlerCannotHonourStrictMode();
        session_name($config->name);
        session_set_cookie_params([
            'lifetime' => $config->lifetime,
            'path'     => $config->cookiePath,
            'secure'   => $config->secure,
            'httponly' => $config->httpOnly,
            'samesite' => $config->sameSite,
        ]);

        session_start();
    }

    /**
     * Warn, in debug only, when the registered save handler cannot honour the
     * strict-mode flag we just set.
     *
     * The framework enabling a security-relevant ini setting that silently does
     * nothing is precisely the failure this guards: it reads as done. Only
     * reachable for a handler registered through setHandler(); a handler passed
     * straight to session_set_save_handler() is invisible here.
     */
    private function warnIfHandlerCannotHonourStrictMode(): void
    {
        if ($this->handler === null || $this->handler instanceof \SessionUpdateTimestampHandlerInterface) {
            return;
        }

        $configuration = App::getConfiguration();
        if ($configuration === null || !$configuration->application->debug) {
            return;
        }

        trigger_error(
            sprintf(
                'Session save handler %s does not implement SessionUpdateTimestampHandlerInterface, so PHP '
                . 'skips its session.use_strict_mode check and will ADOPT a client-supplied session id. '
                . 'Implement validateId() to reject an id that does not already exist.',
                $this->handler::class,
            ),
            E_USER_WARNING,
        );
    }

    /**
     * Regenerate the session ID (e.g. after login to prevent fixation attacks).
     *
     * @param bool $deleteOld Delete the old session data file when true (default).
     */
    public function regenerate(bool $deleteOld = true): void
    {
        if ($this->overrideStorage !== null) {
            return; // In-memory store has no ID to rotate.
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOld);
        }
    }

    /**
     * Destroy the session and clear all stored data.
     *
     * When an override storage is active, the array is cleared in-place.
     */
    public function destroy(): void
    {
        if ($this->overrideStorage !== null) {
            $this->overrideStorage = [];
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    /**
     * Return true when a real PHP session is currently active.
     *
     * Always returns true in override-storage (test) mode.
     */
    public function isStarted(): bool
    {
        if ($this->overrideStorage !== null) {
            return true;
        }

        return session_status() === PHP_SESSION_ACTIVE;
    }

    // -------------------------------------------------------------------------
    // Data access
    // -------------------------------------------------------------------------

    /**
     * Retrieve a value from the session, or $default when the key is absent.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertValidKey($key);

        $storage = $this->overrideStorage ?? $_SESSION ?? [];

        return array_key_exists($key, $storage) ? $storage[$key] : $default;
    }

    /**
     * Store a value in the session.
     */
    public function set(string $key, mixed $value): void
    {
        $this->assertValidKey($key);

        if ($this->overrideStorage !== null) {
            $this->overrideStorage[$key] = $value;
            return;
        }

        $_SESSION[$key] = $value;
    }

    /**
     * Return true when the session contains the given key.
     */
    public function has(string $key): bool
    {
        $this->assertValidKey($key);

        $storage = $this->overrideStorage ?? $_SESSION ?? [];

        return array_key_exists($key, $storage);
    }

    /**
     * Remove a key from the session. No-op when the key does not exist.
     */
    public function remove(string $key): void
    {
        $this->assertValidKey($key);

        if ($this->overrideStorage !== null) {
            unset($this->overrideStorage[$key]);
            return;
        }

        unset($_SESSION[$key]);
    }

    /**
     * Return all session data as an associative array.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->overrideStorage ?? $_SESSION ?? [];
    }

    /**
     * Read a value and immediately remove it from the session.
     *
     * Useful for one-time status / error messages passed across a redirect.
     */
    public function flash(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    private function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw SessionException::invalidKey($key);
        }
    }
}
