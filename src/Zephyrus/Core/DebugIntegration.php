<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Tracy\Debugger;

/**
 * Wires Tracy Debugger integration based on application configuration.
 *
 * ## Why this file is careful
 *
 * Tracy has two very different jobs behind one switch. In DEVELOPMENT mode it
 * renders the Bluescreen: a full HTML page carrying the exception, the stack
 * trace WITH argument values, $_SERVER, $_ENV, $_COOKIE and every object
 * reachable from the frames. In PRODUCTION mode it logs and shows nothing.
 *
 * This class used to hardcode Debugger::Development. That decision was made
 * once, at boot, for EVERY client, so the Bluescreen went to whoever managed to
 * trigger a 500 -- an anonymous caller on the public internet included. Tracy's
 * enable() also sets zend.exception_ignore_args to 0, which puts real argument
 * values (an encryption key, an SMTP password, a reset token) into every stack
 * trace the process produces from then on, and forces display_errors off, so
 * anything the application had hardened is overwritten either way.
 *
 * That is not a hypothetical. An operator diagnosing a live incident routinely
 * sets APP_ENV=dev and APP_DEBUG=true on a PRODUCTION tier for a few minutes.
 * During that window the old behaviour handed the tier's live secrets to any
 * client who could reach a 500.
 *
 * So the mode is now Debugger::Detect, and the client has to earn the
 * Bluescreen:
 *
 *   - it is the loopback address (a developer on their own machine), which
 *     Tracy only grants when the request did NOT arrive through a proxy, or
 *   - it presents the `tracy-debug` cookie whose value matches an allowlist
 *     entry given as `secret@ip`, or
 *   - its address is in the allowlist passed to $allowedClients.
 *
 * Every other client gets the production behaviour: logged, not rendered. The
 * debugger is still ENABLED in both cases, so errors still reach the log
 * directory and the notification email; only the rendering audience changed.
 *
 * ## The second half of this fix lives in ApplicationBuilder
 *
 * DebugIntegration decides WHO sees the debugger. ApplicationBuilder decides
 * whether a production-like environment may turn it on at all; see
 * ApplicationBuilder::build(). The two are deliberately independent, so an
 * operator who deliberately acknowledges debug on production still does not
 * broadcast it to the world.
 *
 * Usage (typically automatic via ApplicationBuilder):
 *
 *   DebugIntegration::initialize(debug: true);
 *   DebugIntegration::initialize(debug: true, logDirectory: '/var/log/app');
 *   DebugIntegration::initialize(debug: true, allowedClients: ['203.0.113.4']);
 *   DebugIntegration::initialize(debug: true, allowedClients: 'mysecret@203.0.113.4');
 */
final class DebugIntegration
{
    /**
     * Property and array keys whose value Tracy must never render.
     *
     * Tracy already hides a short default list ('password', 'pass', 'pwd',
     * 'authorization', ...), which covers the RAW config array where the SMTP
     * password sits under the key `password`. It does NOT cover the typed
     * property the framework hydrates next to it: MailerConfig::$smtpPassword
     * is a different key name, so the same secret rendered twice and was masked
     * once. These names close that gap for every surface Tracy dumps: the
     * Bluescreen, the debug bar and Debugger::dump().
     *
     * Matching is case-insensitive; Tracy lowercases both sides.
     *
     * @var list<string>
     */
    public const array SENSITIVE_KEYS = [
        'smtpPassword',
        'encryptionKey',
        'encryption_key',
        'secret',
        'token',
        'apiKey',
        'api_key',
        'privateKey',
        'private_key',
    ];

    /**
     * Initialize Tracy Debugger if debug mode is enabled.
     *
     * When debug is false, this method is a no-op: Tracy is not initialized at
     * all, and in particular none of its ini_set() calls run.
     *
     * @param bool                    $debug          Whether the application is in debug mode.
     * @param string|null             $logDirectory   Directory for Tracy log files. Null uses Tracy's default.
     * @param string|null             $email          Email for error notifications.
     * @param string|string[]|null    $allowedClients Addresses (or `secret@address` entries matched
     *                                                against the `tracy-debug` cookie) permitted to
     *                                                receive the Bluescreen. Null means loopback and
     *                                                the cookie only. Never grant this to a range you
     *                                                do not control: an entry here can read the
     *                                                application's live secrets out of a stack trace.
     */
    public static function initialize(
        bool $debug,
        ?string $logDirectory = null,
        ?string $email = null,
        string|array|null $allowedClients = null,
    ): void {
        if (!$debug) {
            return;
        }

        if (!class_exists(Debugger::class)) {
            return; // @codeCoverageIgnore
        }

        // Debugger::Detect is null, and Tracy reads a string or an array as the
        // allowlist for the very same detection. Passing $allowedClients
        // straight through therefore keeps ONE code path: with or without an
        // allowlist, the client still has to match something.
        Debugger::enable(
            $allowedClients ?? Debugger::Detect,
            $logDirectory,
            $email,
        );

        self::hideFrameworkSecrets();
    }

    /**
     * Teach Tracy the framework's own secret-bearing key names.
     *
     * Both registries are written because they feed different renderers:
     * Debugger::$keysToHide reaches dump() and the debug bar, while the
     * Bluescreen keeps its own list.
     */
    private static function hideFrameworkSecrets(): void
    {
        Debugger::$keysToHide = array_values(array_unique(array_merge(
            Debugger::$keysToHide,
            self::SENSITIVE_KEYS,
        )));

        $blueScreen = Debugger::getBlueScreen();
        $blueScreen->keysToHide = array_values(array_unique(array_merge(
            $blueScreen->keysToHide,
            self::SENSITIVE_KEYS,
        )));
    }
}
