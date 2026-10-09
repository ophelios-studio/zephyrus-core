<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Reads configuration values from $_ENV and the process environment.
 */
final class EnvironmentVariable
{
    /**
     * Names starting with HTTP_ or REDIRECT_ are refused with an InvalidArgumentException.
     *
     * Under plain CGI the process environment is built from request headers, so
     * such a name can carry client data. Refusing it loudly beats a silent default.
     *
     * @throws \InvalidArgumentException
     */
    public static function read(string $name): ?string
    {
        foreach (['HTTP_', 'REDIRECT_'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s: names starting with %s carry request data under CGI and are never read as configuration; rename the variable.',
                    $name,
                    $prefix,
                ));
            }
        }

        if (array_key_exists($name, $_ENV)) {
            $value = $_ENV[$name];

            return is_scalar($value) ? (string) $value : null;
        }

        // Without local_only, mod_php returns the per-request header table.
        $fromProcess = getenv($name, local_only: true);

        return $fromProcess === false ? null : $fromProcess;
    }
}
