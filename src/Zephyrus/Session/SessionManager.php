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
 * ## Override mode simulates an id, and rotates it
 *
 * It used to do neither: start(), setHandler() and regenerate() were silent
 * no-ops while isStarted() reported true, so a consumer test asserting "login
 * rotates the session id" passed against an implementation that rotated
 * nothing. That is the same shape as the ini-flag bug this class already fixed
 * once, where a security-relevant setting read as done and did nothing.
 *
 * Override mode now carries an id of its own: id() returns it, regenerate()
 * replaces it while keeping the data, destroy() clears both, and setHandler()
 * records the handler for handler() to expose without registering it with PHP.
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
 * ## Concurrency note
 *
 * SessionManager itself adds no locking: two concurrent requests sharing a
 * session id are serialized (or not) by whatever save handler is registered.
 * PHP's built-in `files` handler serializes them with flock, and
 * DatabaseSessionHandler takes a PostgreSQL advisory lock for the same reason.
 * A handler that does neither loses one of the two writes, because PHP hands a
 * save handler the WHOLE payload rather than a delta.
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
     * The id override mode pretends to have. Empty when there is none, which
     * is the state destroy() leaves behind.
     */
    private string $simulatedId = '';

    /**
     * @param array<string, mixed>|null $overrideStorage
     *   When non-null, all session data is read from / written to this array.
     *   start(), regenerate(), and destroy() become no-ops (or lightweight
     *   equivalents). Intended for unit tests only.
     */
    public function __construct(?array $overrideStorage = null)
    {
        $this->overrideStorage = $overrideStorage;

        if ($overrideStorage !== null) {
            $this->simulatedId = self::mintSimulatedId();
        }
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
        $this->handler = $handler;

        if ($this->overrideStorage !== null) {
            // Recorded but not registered: override mode never opens a real
            // PHP session, so there is nothing for PHP to call. Recording it
            // is what lets a consumer test assert the wiring it just built.
            return;
        }

        session_set_save_handler($handler, true);
    }

    /** The handler registered through setHandler(), or null when none was. */
    public function handler(): ?\SessionHandlerInterface
    {
        return $this->handler;
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
     * choosing, one stored record per request.
     *
     * It removes a STEP from session fixation rather than making it possible.
     * An attacker does not have to invent an id: SessionMiddleware starts the
     * session eagerly, so even a request matching no route mints and persists a
     * server-blessed id that can be fetched and then planted. What actually
     * defeats fixation is ROTATING the id at every privilege change (login,
     * second factor, logout, password change) through regenerate(), because a
     * planted id then never survives into an authenticated session. Refusing an
     * unknown id is defence in depth on top of that.
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
    public function start(SessionConfig $config, ?bool $requestIsSecure = null): void
    {
        if ($this->overrideStorage !== null) {
            if ($this->simulatedId === '') {
                $this->simulatedId = self::mintSimulatedId();
            }

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
            'secure'   => $config->resolveSecure($requestIsSecure ?? self::serverReportsHttps()),
            'httponly' => $config->httpOnly,
            'samesite' => $config->sameSite,
        ]);

        session_start();
    }

    /**
     * Fallback for the `secure: auto` setting when the caller passed no answer.
     *
     * Reads $_SERVER['HTTPS'] and NOTHING ELSE. A forwarded-protocol header is
     * deliberately ignored here, because SessionManager holds no trusted-proxy
     * allowlist and reading one without it is how a caller gets to choose the
     * answer. SessionMiddleware passes the value Request already resolved,
     * which does consult the allowlist, so the normal path is not limited to
     * this check.
     */
    private static function serverReportsHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
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
            // Rotates the simulated id and keeps the data, which is what
            // session_regenerate_id() does. Returning without rotating made a
            // consumer test asserting "login rotates the session id" pass
            // against an implementation that rotated nothing.
            $this->simulatedId = self::mintSimulatedId();

            return;
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
            $this->simulatedId = '';
            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    /**
     * The current session id: the real one, or the simulated one in override
     * mode. Empty string when no session is running.
     */
    public function id(): string
    {
        if ($this->overrideStorage !== null) {
            return $this->simulatedId;
        }

        return session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
    }

    /** Shaped like a PHP session id so a consumer assertion on format holds. */
    private static function mintSimulatedId(): string
    {
        return bin2hex(random_bytes(16));
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
