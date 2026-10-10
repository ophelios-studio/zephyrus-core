<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Exceptions\ZephyrusException;

/**
 * Thrown when configuration loading, parsing, or validation fails.
 *
 * Use the named factory methods. Messages carry a file's basename, never its
 * absolute path, which is available from path(). The parser's message kept by
 * parseFailed() is the one exception.
 */
final class ConfigurationException extends ZephyrusException
{
    /** Absolute path set by the file factories, kept out of the message. Null when none. */
    private ?string $path = null;

    public static function missingRequired(string $section, string $field): self
    {
        return new self(
            sprintf("Configuration section '%s' requires field '%s' but none was provided.", $section, $field),
        );
    }

    /** The value is echoed in the message, so never pass a secret. */
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
     * Appends the parser's message unchanged for its line number, so it may name the absolute path.
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
     * Raised when a `security:` setting asks for a protection that the global middlewares do not enforce as declared.
     *
     * Fails at boot on purpose: the framework must not add middlewares from configuration,
     * or an application that mounts its own would get a second copy of each.
     *
     * @param array<string, string> $unwired setting name => instruction for enforcing it
     */
    public static function unwiredSecurity(array $unwired): self
    {
        $lines = [];
        foreach ($unwired as $setting => $instruction) {
            $lines[] = sprintf('  - %s is not enforced as declared: %s', $setting, $instruction);
        }

        return new self(
            "Configuration declares security settings that this application does not enforce as declared:\n"
            . implode("\n", $lines)
            . "\n\nApply the fix given for each setting, or acknowledge the gap explicitly with "
            . 'ApplicationBuilder::withAcknowledgedSecurityKeys([...]) when the protection is provided '
            . 'elsewhere (a reverse proxy, a wrapping middleware, the web server).',
        );
    }

    /** Raised when ContentSecurityPolicyMiddleware is registered before a SecureHeadersMiddleware that sets its own csp. */
    public static function shadowedContentSecurityPolicy(): self
    {
        return new self(
            'ContentSecurityPolicyMiddleware is registered before SecureHeadersMiddleware, whose csp is set, '
            . 'so the policy registered first is never sent. Register ContentSecurityPolicyMiddleware after '
            . 'SecureHeadersMiddleware, or leave SecureHeadersConfig::csp empty.',
        );
    }
}
