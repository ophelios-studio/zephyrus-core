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
}
