<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Exceptions\ZephyrusException;

/**
 * Thrown when configuration loading, parsing, or validation fails.
 *
 * Use the named factory methods for consistent, contextual messages.
 *
 * ## The absolute server path is CONTEXT, never part of the sentence
 *
 * Every factory that takes a path used to interpolate the whole thing. The
 * message is the part that TRAVELS: a log line, an alert email, a Tracy panel,
 * sometimes a 500 page. Worse than most, configuration loading runs at BOOT,
 * before the kernel's error handling exists, so these messages are among the
 * likeliest in the whole framework to land raw in front of somebody. They
 * disclosed the deployment's filesystem layout to every one of those readers
 * for nothing, because the only actor who can fix a broken config file is a
 * developer who already has the repository.
 *
 * The file NAME stays, because that is the diagnostic. The path moved to
 * path(), so a caller that genuinely needs it asks instead of receiving it by
 * default. Same shape as RenderException::templateNotFound() and
 * LocalizationException.
 *
 * The ONE deliberate exception is the half of parseFailed() inherited from the
 * parser. See that factory.
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
        return self::withPath(sprintf('Configuration file not found: %s', basename($path)), $path);
    }

    public static function loadFailed(string $path, ?\Throwable $previous = null): self
    {
        return self::withPath(
            sprintf('Configuration file failed to load: %s', basename($path)),
            $path,
            $previous,
        );
    }

    /**
     * ## Two halves, ruled differently, on purpose
     *
     * OUR half is a basename, because interpolating the full path is us
     * formatting a filesystem fact ourselves. It is on path() instead.
     *
     * The PARSER's half is appended verbatim and is KEPT, the same category
     * ruled KEEP for RenderException::renderFailed(): it is a preserved
     * upstream diagnostic, and it carries the line number a developer actually
     * needs to fix the file.
     *
     * ## The consequence, stated plainly
     *
     * Symfony's ParseException names the ABSOLUTE file in some of its messages
     * and not others, which was measured rather than assumed: a tab-indentation
     * error yields 'A YAML file cannot contain tabs as indentation in
     * "/srv/app/config/app.yml" at line 2', while a malformed-inline error
     * yields only 'Malformed inline YAML string at line 3'. So this message CAN
     * STILL DISCLOSE A PATH in practice, through the inherited half, on some
     * inputs. That is a knowing trade, not an oversight. Do not "finish" this
     * by stripping the parser message:
     * that destroys the diagnostic and buys nothing our half has not already
     * bought. If the disclosure ever has to go, the answer is to reformat the
     * parser's text while keeping its line number, which is a separate decision.
     */
    public static function parseFailed(string $path, ?\Throwable $previous = null): self
    {
        $message = sprintf('Failed to parse configuration file [%s]', basename($path));
        if ($previous !== null) {
            $message .= ': ' . $previous->getMessage();
        }

        return self::withPath($message, $path, $previous);
    }

    public static function invalidFormat(string $path, string $reason = 'must return an array'): self
    {
        return self::withPath(sprintf('Configuration file %s: %s', basename($path), $reason), $path);
    }

    /**
     * The absolute path this exception is about, or null when it carries none.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    private static function withPath(string $message, string $path, ?\Throwable $previous = null): self
    {
        $exception = new self($message, previous: $previous);
        $exception->path = $path;

        return $exception;
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
