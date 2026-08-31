<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Exceptions\ZephyrusException;

/**
 * Thrown when configuration loading, parsing, or validation fails.
 *
 * Use the named factory methods for consistent, contextual messages.
 */
final class ConfigurationException extends ZephyrusException
{
    public static function missingRequired(string $section, string $field): self
    {
        return new self(
            sprintf("Configuration section '%s' requires field '%s' but none was provided.", $section, $field),
        );
    }

    public static function invalidValue(string $section, string $field, mixed $value, string $reason): self
    {
        return new self(
            sprintf(
                "Configuration section '%s' field '%s' has invalid value '%s': %s.",
                $section,
                $field,
                $value,
                $reason,
            ),
        );
    }

    public static function fileNotFound(string $path): self
    {
        return new self(sprintf('Configuration file not found: %s', $path));
    }

    public static function loadFailed(string $path, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Configuration file failed to load: %s', $path),
            previous: $previous,
        );
    }

    public static function parseFailed(string $path, ?\Throwable $previous = null): self
    {
        $message = sprintf('Failed to parse configuration file [%s]', $path);
        if ($previous !== null) {
            $message .= ': ' . $previous->getMessage();
        }
        return new self($message, previous: $previous);
    }

    public static function invalidFormat(string $path, string $reason = 'must return an array'): self
    {
        return new self(sprintf('Configuration file %s: %s', $path, $reason));
    }

    public static function invalidPath(string $reason): self
    {
        return new self($reason);
    }

    /**
     * Thrown when a `security:` setting asks for a protection and nothing in
     * the assembled kernel provides it.
     *
     * The whole block used to be inert: forceHttps, csrfEnabled, allowedHosts
     * and maxBodySize were parsed, validated, echoed by toArray() and connected
     * to nothing, so a configuration declaring all four protections ON served a
     * plain-HTTP, forged-Host, tokenless 5 MiB POST. Failing at boot is the
     * honest answer, because the framework must not start wiring middlewares
     * off a config file: an application that already registers its own would
     * get a second copy of each.
     *
     * @param array<string, class-string> $unwired setting name => the middleware that consumes it
     */
    public static function unwiredSecurity(array $unwired): self
    {
        $lines = [];
        foreach ($unwired as $setting => $middleware) {
            $lines[] = sprintf('  - %s is not enforced: register %s', $setting, $middleware);
        }

        return new self(
            "Configuration declares security settings that nothing in this application enforces:\n"
            . implode("\n", $lines)
            . "\n\nRegister the middleware(s) on the builder, or acknowledge the gap explicitly with "
            . 'ApplicationBuilder::withAcknowledgedSecurityKeys([...]) when the protection is provided '
            . 'elsewhere (a reverse proxy, a wrapping middleware, the web server).',
        );
    }
}
