<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Tracy\Debugger;

/**
 * Wires Tracy Debugger integration based on application configuration.
 *
 * When debug mode is enabled, Tracy is initialized in Development mode,
 * providing the debug bar, pretty error pages, and Bluescreen for uncaught
 * exceptions. When debug mode is disabled, Tracy is not initialized at all.
 *
 * This class is called during ApplicationBuilder::build() when a
 * Configuration object is available and application.debug is true.
 *
 * Usage (typically automatic via ApplicationBuilder):
 *
 *   DebugIntegration::initialize(debug: true);
 *   DebugIntegration::initialize(debug: true, logDirectory: '/var/log/app');
 */
final class DebugIntegration
{
    /**
     * Initialize Tracy Debugger if debug mode is enabled.
     *
     * When debug is false, this method is a no-op.
     *
     * @param bool        $debug         Whether the application is in debug mode.
     * @param string|null $logDirectory  Directory for Tracy log files. Null uses Tracy's default.
     * @param string|null $email         Email for error notifications (production mode only, not used in dev).
     */
    public static function initialize(bool $debug, ?string $logDirectory = null, ?string $email = null): void
    {
        if (!$debug) {
            return;
        }

        if (!class_exists(Debugger::class)) {
            return; // @codeCoverageIgnore
        }

        Debugger::enable(
            Debugger::Development,
            $logDirectory,
            $email,
        );
    }
}
