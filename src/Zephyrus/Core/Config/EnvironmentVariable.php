<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Exceptions\MessageValue;

/**
 * Reads configuration values from $_ENV and the process environment.
 */
final class EnvironmentVariable
{
    /**
     * Exact names, grouped by the source that can set them per request.
     *
     * @var array<string, list<string>>
     */
    private const REFUSED_NAMES = [
        'the web server or PHP' => [
            'AUTH_TYPE', 'CONTENT_LENGTH', 'CONTENT_TYPE', 'GATEWAY_INTERFACE', 'PATH_INFO',
            'PATH_TRANSLATED', 'QUERY_STRING', 'REMOTE_ADDR', 'REMOTE_HOST', 'REMOTE_IDENT',
            'REMOTE_PORT', 'REMOTE_USER', 'REQUEST_METHOD', 'SCRIPT_NAME', 'SERVER_NAME',
            'SERVER_PORT', 'SERVER_ADDR', 'SERVER_PROTOCOL', 'SERVER_SOFTWARE', 'REQUEST_URI',
            'DOCUMENT_URI', 'DOCUMENT_ROOT', 'SCRIPT_FILENAME', 'SCRIPT_URI', 'SCRIPT_URL',
            'CONTEXT_PREFIX', 'CONTEXT_DOCUMENT_ROOT', 'REQUEST_SCHEME', 'HTTPS', 'PHP_AUTH_USER',
            'PHP_AUTH_PW', 'PHP_AUTH_DIGEST', 'PHP_SELF', 'ARGC', 'ARGV',
        ],
        'Apache' => ['SERVER_SIGNATURE'],
        'Apache mod_http2' => ['HTTP2', 'H2PUSH'],
    ];

    /**
     * Prefixes, mapped to the source that can set names starting with them per request.
     *
     * @var array<string, string>
     */
    private const REFUSED_PREFIXES = [
        'HTTP_' => 'the client request headers',
        'REDIRECT_' => 'Apache internal redirects',
        'ORIG_' => 'php-fpm',
        'SSL_' => 'Apache mod_ssl',
        'H2_' => 'Apache mod_http2',
    ];

    /**
     * Reads a configuration value from $_ENV, then from the process environment.
     *
     * Names containing a NUL byte or "=" are refused. So are names starting with HTTP_, REDIRECT_,
     * ORIG_, SSL_ or H2_, and exact request names such as QUERY_STRING, REMOTE_ADDR,
     * PHP_AUTH_PW, SERVER_SIGNATURE or HTTP2. Matching ignores case. A server may pass
     * other request-derived names.
     *
     * @throws \InvalidArgumentException
     */
    public static function read(string $name): ?string
    {
        if (strpbrk($name, "\0=") !== false) {
            throw new \InvalidArgumentException(sprintf(
                '%s: names must not contain a NUL byte or "=".',
                MessageValue::quote($name),
            ));
        }

        $upper = strtoupper($name);

        foreach (self::REFUSED_NAMES as $source => $names) {
            if (in_array($upper, $names, true)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s: can be set per request by %s, so it is never read as configuration; rename the variable.',
                    MessageValue::quote($name),
                    $source,
                ));
            }
        }

        foreach (self::REFUSED_PREFIXES as $prefix => $source) {
            if (str_starts_with($upper, $prefix)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s: names starting with %s can be set per request by %s, so they are never read as configuration; rename the variable.',
                    MessageValue::quote($name),
                    $prefix,
                    $source,
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
