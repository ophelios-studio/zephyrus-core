<?php

declare(strict_types=1);

namespace Zephyrus\Formatting;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a formatting operation fails.
 */
final class FormatterException extends ZephyrusRuntimeException
{
    public static function formattingFailed(string $type, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Formatting [%s] failed: %s', $type, $reason),
            previous: $previous,
        );
    }

    public static function invalidGroupingSeparator(string $reason): self
    {
        return new self(sprintf('Invalid grouping separator: it %s.', $reason));
    }

    public static function reservedGroupingSeparator(string $locale): self
    {
        return new self(sprintf(
            'Invalid grouping separator: it is the decimal, monetary decimal or minus sign of locale %s.',
            $locale,
        ));
    }

    /**
     * Raised by the global format() helper when App has no Formatter.
     */
    public static function formatterRequired(string $type): self
    {
        return new self(sprintf(
            'format(\'%s\') needs a Formatter: call App::setFormatter() first.',
            $type,
        ));
    }

    public static function invalidLocale(string $locale): self
    {
        return new self(sprintf('Invalid locale: %s', $locale));
    }

    /**
     * @param list<string> $builtIns
     */
    public static function unknownFormatter(string $name, array $builtIns): self
    {
        return new self(sprintf(
            'Unknown formatter: %s. Use one of: %s, or register a custom formatter.',
            $name,
            implode(', ', $builtIns),
        ));
    }
}
