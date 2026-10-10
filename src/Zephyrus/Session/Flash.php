<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use stdClass;
use Zephyrus\Core\App;

/**
 * Typed flash messages built on top of SessionManager, for POST, redirect, GET flows.
 *
 * Messages are cleared by the first readAll() that follows them.
 *
 *   Flash::success('Profile updated.');          // before the redirect
 *   $flash = Flash::readAll();                   // after the redirect
 *   // $flash->success === ['Profile updated.'], and error, warning and info likewise
 *
 * Flash messages are untrusted output: they often carry user input and are rendered on a later page.
 * Escape them when rendering; the Latte engine does so automatically, the PhpEngine does not.
 */
final class Flash
{
    private const KEY_SUCCESS = '_flash_success';
    private const KEY_ERROR = '_flash_error';
    private const KEY_WARNING = '_flash_warning';
    private const KEY_INFO = '_flash_info';

    /**
     * @param string|string[] $message
     *
     * @throws SessionException when a manager is registered and no session is active.
     */
    public static function success(string|array $message): void
    {
        self::append(self::KEY_SUCCESS, $message);
    }

    /**
     * @param string|string[] $message
     *
     * @throws SessionException when a manager is registered and no session is active.
     */
    public static function error(string|array $message): void
    {
        self::append(self::KEY_ERROR, $message);
    }

    /**
     * @param string|string[] $message
     *
     * @throws SessionException when a manager is registered and no session is active.
     */
    public static function warning(string|array $message): void
    {
        self::append(self::KEY_WARNING, $message);
    }

    /**
     * @param string|string[] $message
     *
     * @throws SessionException when a manager is registered and no session is active.
     */
    public static function info(string|array $message): void
    {
        self::append(self::KEY_INFO, $message);
    }

    /**
     * Read all flash messages and clear them from the session.
     *
     * Returns an object with the array properties success, error, warning and info.
     *
     * @throws SessionException when a manager is registered, no session is active and a message is stored.
     */
    public static function readAll(): stdClass
    {
        $session = App::getSession();
        $result = new stdClass();

        if ($session === null) {
            $result->success = [];
            $result->error = [];
            $result->warning = [];
            $result->info = [];
            return $result;
        }

        $result->success = $session->flash(self::KEY_SUCCESS, []);
        $result->error = $session->flash(self::KEY_ERROR, []);
        $result->warning = $session->flash(self::KEY_WARNING, []);
        $result->info = $session->flash(self::KEY_INFO, []);

        return $result;
    }

    /**
     * Clear all flash messages without reading them.
     *
     * @throws SessionException when a manager is registered, no session is active and a message is stored.
     */
    public static function clearAll(): void
    {
        $session = App::getSession();
        if ($session === null) {
            return;
        }

        $session->remove(self::KEY_SUCCESS);
        $session->remove(self::KEY_ERROR);
        $session->remove(self::KEY_WARNING);
        $session->remove(self::KEY_INFO);
    }

    /**
     * @param string|string[] $message
     */
    private static function append(string $key, string|array $message): void
    {
        $session = App::getSession();
        if ($session === null) {
            return;
        }

        $existing = $session->get($key, []);
        $messages = is_array($message) ? $message : [$message];
        $session->set($key, array_merge($existing, $messages));
    }
}
