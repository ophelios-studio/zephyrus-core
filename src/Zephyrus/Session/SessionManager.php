<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Core\App;
use Zephyrus\Core\Config\SessionConfig;

/**
 * Thin, testable wrapper around PHP's native session functions.
 *
 * The constructor accepts an optional override storage array. When supplied, data
 * operations target that array instead of $_SESSION, the id is simulated, and no
 * real PHP session is started. Intended for tests.
 *
 * In production, omit the override and call start() once at bootstrap:
 *
 *   $session = new SessionManager();
 *   $session->start($config->session);
 *
 * SessionManager adds no locking: concurrent requests sharing a session id are
 * serialized by the registered save handler. PHP's files handler uses flock, and
 * DatabaseSessionHandler takes a PostgreSQL advisory lock.
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

    /** The id override mode pretends to have. Empty when there is none. */
    private string $simulatedId = '';

    /**
     * @param array<string, mixed>|null $overrideStorage When non-null, all session data is read from and
     *   written to this array, and no real PHP session is used.
     */
    public function __construct(?array $overrideStorage = null)
    {
        $this->overrideStorage = $overrideStorage;

        if ($overrideStorage !== null) {
            $this->simulatedId = self::mintSimulatedId();
        }
    }

    /**
     * Register a custom session save handler, before start().
     *
     * Throws SessionException when PHP refuses the registration, and then keeps no handler.
     * In override mode the handler is recorded but not registered.
     *
     * @throws SessionException
     */
    public function setHandler(\SessionHandlerInterface $handler): void
    {
        if ($this->overrideStorage !== null) {
            $this->handler = $handler;

            return;
        }

        if (!self::quietly(static fn (): bool => session_set_save_handler($handler, true), $phpWarning)) {
            throw SessionException::saveHandlerRefused($phpWarning);
        }

        $this->handler = $handler;
    }

    /** The handler registered through setHandler(), or null when none was. */
    public function handler(): ?\SessionHandlerInterface
    {
        return $this->handler;
    }

    /**
     * Configure PHP session parameters and start the session.
     *
     * Returns immediately when a session is already active, and does nothing in override mode.
     *
     * Forces session.use_strict_mode on, because PHP otherwise adopts any id the client sends. This only
     * removes one step of session fixation: rotate the id on each privilege change with regenerate().
     * The flag is inert unless the save handler implements SessionUpdateTimestampHandlerInterface (validateId()),
     * and a configured idleTimeout is enforced on read only by DatabaseSessionHandler. In debug mode, start()
     * warns about either gap.
     *
     * @throws SessionException when output has already been sent or PHP refuses to start the session.
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

        if (headers_sent($file, $line)) {
            throw SessionException::outputAlreadySent(new \ErrorException(
                sprintf('Session cannot be started after headers have already been sent (sent from %s on line %d)', $file, $line),
                0,
                E_WARNING,
                $file,
                $line,
            ));
        }

        ini_set('session.use_strict_mode', '1');

        if ($config->idleTimeout !== null) {
            ini_set('session.gc_maxlifetime', (string) $config->idleTimeout);
        }

        $this->warnIfHandlerCannotHonourStrictMode();
        $this->warnIfIdleTimeoutIsNotEnforced($config);
        session_name($config->name);
        session_set_cookie_params([
            'lifetime' => $config->lifetime,
            'path'     => $config->cookiePath,
            'secure'   => $config->resolveSecure($requestIsSecure ?? self::serverReportsHttps()),
            'httponly' => $config->httpOnly,
            'samesite' => $config->sameSite,
        ]);

        if (!self::quietly(static fn (): bool => session_start(), $phpWarning)) {
            throw SessionException::startRefused($phpWarning);
        }
    }

    /**
     * Fallback for the `secure: auto` setting when the caller passed no answer.
     *
     * Reads $_SERVER['HTTPS'] only: without a trusted-proxy allowlist, a forwarded-protocol header
     * must not decide it. SessionMiddleware passes the answer Request already resolved.
     */
    private static function serverReportsHttps(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';

        return is_string($https) && $https !== '' && strtolower($https) !== 'off';
    }

    /** Warn, in debug only, when the idle timeout can only act as a GC age. */
    private function warnIfIdleTimeoutIsNotEnforced(SessionConfig $config): void
    {
        if ($config->idleTimeout === null || $this->handler !== null || ini_get('session.save_handler') !== 'files') {
            return;
        }

        $configuration = App::getConfiguration();
        if ($configuration === null || !$configuration->application->debug) {
            return;
        }

        trigger_error(
            'The session idle timeout is only a garbage-collection age with the files save handler: nothing '
            . 'checks how long a session sat idle when it is read, so it is resumed. Register '
            . 'DatabaseSessionHandler through setHandler() to enforce it, or remove idleTimeout and set '
            . 'session.gc_maxlifetime if a garbage-collection age is all you need.',
            E_USER_WARNING,
        );
    }

    /** Warn, in debug only, when the registered save handler cannot honour the strict-mode flag. */
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
     * Regenerate the session id, e.g. after login to prevent fixation.
     *
     * @param bool $deleteOld Delete the old session data when true (default).
     *
     * @throws SessionException when no session is active or PHP refuses the rotation.
     */
    public function regenerate(bool $deleteOld = true): void
    {
        if ($this->overrideStorage !== null) {
            $this->simulatedId = self::mintSimulatedId();

            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw SessionException::noActiveSession('regenerate the session id');
        }

        if (!self::quietly(static fn (): bool => session_regenerate_id($deleteOld), $phpWarning)) {
            throw SessionException::regenerationRefused($phpWarning);
        }
    }

    /**
     * Destroy the session and clear all stored data.
     *
     * Does nothing when no session exists.
     *
     * @throws SessionException when PHP or the save handler refuses, so a logout never reports success
     *   while the stored session survives, or when the session is closed but still has an id.
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

            if (!self::quietly(static fn (): bool => session_destroy(), $phpWarning)) {
                throw SessionException::destructionRefused($phpWarning);
            }

            return;
        }

        if (session_id() !== '') {
            throw SessionException::notActiveForDestruction();
        }
    }

    /**
     * The current session id, or the simulated one in override mode. Empty string when no session is running.
     */
    public function id(): string
    {
        if ($this->overrideStorage !== null) {
            return $this->simulatedId;
        }

        return session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
    }

    /** A hex id in the shape of a PHP session id. */
    private static function mintSimulatedId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Return true when a real PHP session is currently active. Always true in override mode.
     */
    public function isStarted(): bool
    {
        if ($this->overrideStorage !== null) {
            return true;
        }

        return session_status() === PHP_SESSION_ACTIVE;
    }

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
     * Read a value and immediately remove it from the session, for one-time messages across a redirect.
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

    /**
     * Run a session_*() call with PHP's warning swallowed, so its boolean answer is the only signal.
     *
     * The warning carries absolute server paths, so it is returned through $phpWarning for logging, never displayed.
     *
     * @param callable(): bool $call
     */
    private static function quietly(callable $call, ?\ErrorException &$phpWarning): bool
    {
        $phpWarning = null;
        set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$phpWarning): bool {
            $phpWarning = new \ErrorException($message, 0, $severity, $file, $line);

            return true;
        });

        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }
}
