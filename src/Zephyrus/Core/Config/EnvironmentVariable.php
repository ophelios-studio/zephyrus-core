<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Reads configuration values from $_ENV and the process environment.
 */
final class EnvironmentVariable
{
    private const REFUSED_PREFIXES = ['HTTP_', 'REDIRECT_'];

    /** @var list<string> */
    private const CGI_META_VARIABLES = [
        'AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'GATEWAY_INTERFACE', 'PATH_INFO',
        'PATH_TRANSLATED', 'QUERY_STRING', 'REMOTE_ADDR', 'REMOTE_HOST', 'REMOTE_IDENT',
        'REMOTE_USER', 'REQUEST_METHOD', 'SCRIPT_NAME', 'SERVER_NAME', 'SERVER_PORT',
        'SERVER_PROTOCOL', 'SERVER_SOFTWARE', 'REQUEST_URI', 'DOCUMENT_URI', 'DOCUMENT_ROOT',
        'SCRIPT_FILENAME', 'REQUEST_SCHEME', 'HTTPS', 'PHP_AUTH_USER', 'PHP_AUTH_PW',
        'PHP_AUTH_DIGEST',
    ];

    /**
     * Refuses request-carrying names with an InvalidArgumentException.
     *
     * Names starting with HTTP_ or REDIRECT_, and CGI meta-variables such as QUERY_STRING,
     * hold client data under CGI and php-fpm. They are matched case-insensitively.
     *
     * @throws \InvalidArgumentException
     */
    public static function read(string $name): ?string
    {
        $upper = strtoupper($name);

        if (in_array($upper, self::CGI_META_VARIABLES, true)) {
            throw new \InvalidArgumentException(sprintf(
                '%s: CGI meta-variables carry request data and are never read as configuration; rename the variable.',
                $name,
            ));
        }

        foreach (self::REFUSED_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
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
