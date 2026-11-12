<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Throwable;
use Tracy\ILogger;
use Tracy\Logger;

/**
 * Writes what Tracy logs to PHP's error log, for a debugger enabled without a log directory.
 *
 * @internal Installed by DebugIntegration::initialize().
 */
final class TracyErrorLogLogger implements ILogger
{
    public function log(mixed $value, string $level = self::INFO): void
    {
        error_log('Zephyrus: debugger ' . $level . ': ' . self::describe($value));
    }

    private static function describe(mixed $value): string
    {
        if (!$value instanceof Throwable) {
            return str_replace(["\r", "\n"], ['\r', '\n'], Logger::formatMessage($value));
        }

        try {
            return (string) $value;
        } catch (Throwable) {
            return $value::class . ': ' . $value->getMessage();
        }
    }
}
