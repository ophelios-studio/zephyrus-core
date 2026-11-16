<?php

declare(strict_types=1);

namespace Zephyrus\Exceptions;

/**
 * Checks that a developer-supplied regular expression compiles, whatever the application's error handler does.
 *
 * @internal
 */
final class RegexCompilation
{
    /**
     * Returns PHP's reason when the pattern does not compile, null when it does.
     */
    public static function error(string $regex): ?string
    {
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $prefix = 'preg_match(): ';
            $warning = str_starts_with($message, $prefix) ? substr($message, strlen($prefix)) : $message;

            return true;
        });

        try {
            $matched = preg_match($regex, '');
        } finally {
            restore_error_handler();
        }

        if ($matched !== false) {
            return null;
        }

        return $warning ?? preg_last_error_msg();
    }

    /**
     * Same as error() without the trailing " at offset N", for a pattern the caller
     * wrapped (the offset points into the wrapper).
     */
    public static function errorWithoutOffset(string $regex): ?string
    {
        $error = self::error($regex);

        return $error === null ? null : (preg_replace('/ at offset \d+$/', '', $error) ?? $error);
    }
}
