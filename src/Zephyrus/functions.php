<?php

declare(strict_types=1);

/**
 * Zephyrus global helper functions.
 *
 * These functions provide convenient shorthand access to commonly used
 * framework features. They are autoloaded via composer.json autoload.files.
 */

if (!function_exists('env')) {
    /**
     * Read an environment variable from $_ENV or $_SERVER.
     *
     * @param string $key     The environment variable name.
     * @param mixed  $default Default value when the variable is not set.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        if ($value === null) {
            return $default;
        }

        // Cast common string representations to their native types.
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}
