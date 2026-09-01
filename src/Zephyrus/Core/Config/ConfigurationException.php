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
    /**
     * The absolute path this exception is about, when it was given one.
     *
     * Deliberately NOT in getMessage() for the factories that populate it. Null
     * rather than '' when there is no path, so "none recorded" stays
     * distinguishable from "it was empty".
     */
    private ?string $path = null;

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

    /**
     * ## The absolute server path is CONTEXT, never part of the sentence
     *
     * This used to interpolate the full path. The message is the part that
     * TRAVELS: a log line, an alert email, a Tracy panel, sometimes a 500 page.
     * Worse than most, configuration loading runs at BOOT, before the kernel's
     * error handling exists, so this message is among the likeliest in the
     * framework to land raw in front of somebody, and it disclosed the
     * deployment's filesystem layout for nothing.
     *
     * The file NAME stays, because that is the diagnostic. The path moved to
     * path(). Same shape as RenderException::templateNotFound() and
     * LocalizationException.
     *
     * ## NOT YET GIVEN THIS TREATMENT
     *
     * fileNotFound(), loadFailed() and parseFailed() above still interpolate
     * the full path, and parseFailed() additionally inherits the YAML parser's
     * message, which names the file itself. They are listed here so nobody
     * reads this class as finished; changing them is a separate ruling.
     */
    public static function invalidFormat(string $path, string $reason = 'must return an array'): self
    {
        $exception = new self(sprintf('Configuration file %s: %s', basename($path), $reason));
        $exception->path = $path;

        return $exception;
    }

    /**
     * The absolute path this exception is about, or null when it carries none.
     */
    public function path(): ?string
    {
        return $this->path;
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
