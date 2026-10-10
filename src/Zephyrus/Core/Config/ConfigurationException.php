<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Exceptions\MessageValue;
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
    private const string PLAIN_FIELD_PATTERN = '/\A[A-Za-z0-9._\-\[\]]{1,64}\z/';

    private const string INVALID_VALUE_FORMAT = "Configuration section '%s' field %s has invalid value %s: %s.";

    private const string VALUE_PLACEHOLDER = '[value]';

    /** Absolute path set by the file factories, kept out of the message. Null when none. */
    private ?string $path = null;

    private ?string $section = null;

    private ?string $field = null;

    private ?string $reason = null;

    private ?string $messageWithoutValue = null;

    public static function missingRequired(string $section, string $field): self
    {
        $exception = new self(sprintf(
            "Configuration section '%s' requires field %s but none was provided.",
            $section,
            self::shownField($field),
        ));
        $exception->section = $section;
        $exception->field = $field;

        return $exception;
    }

    /**
     * A field the framework no longer reads, followed by the explanation of what to do instead. The explanation
     * is developer text and goes into the message as given, unescaped.
     */
    public static function removedField(string $section, string $field, string $explanation): self
    {
        $exception = new self(sprintf(
            "Configuration section '%s' field %s has been REMOVED from Zephyrus and is no longer honoured. %s",
            $section,
            self::shownField($field),
            $explanation,
        ));
        $exception->section = $section;
        $exception->field = $field;

        return $exception;
    }

    /**
     * A key the section does not read, with the closest accepted spelling, or the accepted keys when no spelling
     * is close. The key's value stays out of the message.
     *
     * @param list<string> $acceptedKeys
     */
    public static function unknownKey(string $section, string $field, ?string $suggestion, array $acceptedKeys): self
    {
        $exception = new self(sprintf(
            "Configuration section '%s' field %s is an unknown key: %s",
            $section,
            self::shownField($field),
            $suggestion === null
                ? 'the accepted keys are ' . implode(', ', $acceptedKeys) . '.'
                : 'did you mean ' . MessageValue::quote($suggestion) . '?',
        ));
        $exception->section = $section;
        $exception->field = $field;

        return $exception;
    }

    /**
     * A value of the wrong type, refused without showing it, followed by the requirement it fails, such as
     * 'must be a mapping'.
     */
    public static function invalidType(string $section, string $field, string $requirement): self
    {
        $exception = new self(sprintf(
            "Configuration section '%s' field %s %s.",
            $section,
            self::shownField($field),
            $requirement,
        ));
        $exception->section = $section;
        $exception->field = $field;
        $exception->reason = $requirement;

        return $exception;
    }

    /**
     * Two spellings of one setting written in the same section; field() is the first.
     */
    public static function conflictingKeys(string $section, string $first, string $second): self
    {
        $exception = new self(sprintf(
            "Configuration section '%s' sets both %s and %s: keep one.",
            $section,
            self::shownField($first),
            self::shownField($second),
        ));
        $exception->section = $section;
        $exception->field = $first;

        return $exception;
    }

    /**
     * The value is shown through MessageValue::describe(), so never pass a secret. It is stored in no property
     * and hidden from this call's trace frame; a caller's frame still holds it unless that parameter is marked
     * #[\SensitiveParameter]. A field longer than 64 bytes or holding anything but ASCII letters, digits and
     * . _ - [ ] is shown through MessageValue::quote().
     */
    public static function invalidValue(
        string $section,
        string $field,
        #[\SensitiveParameter] mixed $value,
        string $reason,
    ): self {
        $shownField = self::shownField($field);
        $exception = new self(
            sprintf(self::INVALID_VALUE_FORMAT, $section, $shownField, MessageValue::describe($value), $reason),
        );
        $exception->section = $section;
        $exception->field = $field;
        $exception->reason = $reason;
        $exception->messageWithoutValue = sprintf(
            self::INVALID_VALUE_FORMAT,
            $section,
            $shownField,
            self::VALUE_PLACEHOLDER,
            $reason,
        );

        return $exception;
    }

    /**
     * The section named by invalidValue(), invalidType(), missingRequired(), removedField(), unknownKey() or
     * conflictingKeys(), or null for any other refusal: its configuration key (such as 'database' or
     * 'security.headers'), or the class name of the ConfigSection whose getter refused a value.
     */
    public function section(): ?string
    {
        return $this->section;
    }

    /**
     * The field named by invalidValue(), invalidType(), missingRequired(), removedField(), unknownKey() or
     * conflictingKeys(), as passed, or null.
     */
    public function field(): ?string
    {
        return $this->field;
    }

    /**
     * The reason given to invalidValue(), or the requirement given to invalidType(), or null.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * The invalidValue() message with the value replaced by [value], or null for any other refusal.
     */
    public function messageWithoutValue(): ?string
    {
        return $this->messageWithoutValue;
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

    private static function shownField(string $field): string
    {
        return preg_match(self::PLAIN_FIELD_PATTERN, $field) === 1 ? "'" . $field . "'" : MessageValue::quote($field);
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
