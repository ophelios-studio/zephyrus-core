<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use stdClass;
use Zephyrus\Core\App;

/**
 * Typed flash message facade built on top of SessionManager.
 *
 * Flash messages survive exactly one readAll() call — they are cleared
 * immediately after being read. This makes them ideal for status messages
 * passed across a POST→redirect→GET flow.
 *
 * Usage:
 *
 *   // In a controller action (before redirect):
 *   Flash::success('Profile updated.');
 *   Flash::error(['Name is required.', 'Email is invalid.']);
 *
 *   // In the template (after redirect):
 *   $flash = Flash::readAll();
 *   // $flash->success === ['Profile updated.']
 *   // $flash->error   === ['Name is required.', 'Email is invalid.']
 *   // $flash->warning  === []
 *   // $flash->info     === []
 *
 * Messages are stored in the session under `_flash_{type}` keys.
 */
final class Flash
{
    private const KEY_SUCCESS = '_flash_success';
    private const KEY_ERROR = '_flash_error';
    private const KEY_WARNING = '_flash_warning';
    private const KEY_INFO = '_flash_info';

    /**
     * Add one or more success messages.
     *
     * @param string|string[] $message
     */
    public static function success(string|array $message): void
    {
        self::append(self::KEY_SUCCESS, $message);
    }

    /**
     * Add one or more error messages.
     *
     * @param string|string[] $message
     */
    public static function error(string|array $message): void
    {
        self::append(self::KEY_ERROR, $message);
    }

    /**
     * Add one or more warning messages.
     *
     * @param string|string[] $message
     */
    public static function warning(string|array $message): void
    {
        self::append(self::KEY_WARNING, $message);
    }

    /**
     * Add one or more informational messages.
     *
     * @param string|string[] $message
     */
    public static function info(string|array $message): void
    {
        self::append(self::KEY_INFO, $message);
    }

    /**
     * Read all flash messages and clear them from the session.
     *
     * Returns an object with four array properties: success, error,
     * warning, info. Each is an array of strings (empty if no messages
     * of that type were set).
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
