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

    public static function invalidCurrencyCode(): self
    {
        return new self(sprintf('Invalid currency code: it %s.', FormatterInput::CURRENCY_CODE_RULE));
    }

    public static function invalidPrecision(string $method, int $precision, int $maximum): self
    {
        return new self(sprintf(
            '%s() precision must be between 0 and %d, got %d.',
            $method,
            $maximum,
            $precision,
        ));
    }

    public static function currencyRequired(string $locale): self
    {
        return new self(sprintf(
            'Currency required: locale %s has no native currency. '
            . 'Pass a currency to money(), or set localization.currency in the configuration '
            . '(defaultCurrency when you build the Formatter yourself).',
            (string) json_encode(
                $locale,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
            ),
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
