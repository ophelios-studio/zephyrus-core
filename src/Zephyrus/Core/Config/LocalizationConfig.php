<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Formatting\FormatterInput;

/**
 * Immutable localization bootstrap config.
 *
 * YAML keys (snake_case aliases accepted where listed):
 * - locale (defaultLocale, default_locale): translator default locale, lower-cased, so 'fr-CA' is
 *   stored as 'fr-ca'. Default 'en'.
 * - supportedLocales (supported_locales): negotiation allowlist. Trimmed, lower-cased, blanks dropped.
 *   Default [].
 * - localePath (locale_path, jsonLocalePaths, json_locale_paths): one directory of locales.
 *   The legacy array form keeps its last non-empty entry. Default null.
 * - timezone: applied with date_default_timezone_set() by ApplicationBuilder. Default 'UTC'.
 * - currency: default currency code for Formatter::money(), three ASCII letters, trimmed; '' means
 *   none. Default null.
 * - dateFormat (date_format), timeFormat (time_format), datetimeFormat (datetime_format): ICU
 *   pattern or preset for Formatter::date(), time() and datetime(). Defaults 'medium', 'short', 'medium'.
 * - groupingSeparator (grouping_separator): thousands separator. Null keeps the locale default,
 *   '' disables grouping. Otherwise at most 4 bytes of spaces, punctuation or symbols that cannot
 *   reorder digits. Prefer U+00A0 or U+202F: a plain space or U+2019 can reverse the digit groups
 *   when the amount sits inside right-to-left text.
 */
final readonly class LocalizationConfig
{
    /**
     * @param string[] $supportedLocales
     */
    public function __construct(
        public string $locale,
        public array $supportedLocales,
        public ?string $localePath = null,
        public string $timezone = 'UTC',
        public ?string $currency = null,
        public string $dateFormat = 'medium',
        public string $timeFormat = 'short',
        public string $datetimeFormat = 'medium',
        public ?string $groupingSeparator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     * @throws ConfigurationException if locale or timezone is blank, or currency or grouping_separator is invalid.
     */
    public static function fromArray(array $values): self
    {
        $locale = trim((string) ($values['locale'] ?? $values['defaultLocale'] ?? $values['default_locale'] ?? 'en'));
        $supportedLocales = (array) ($values['supportedLocales'] ?? $values['supported_locales'] ?? []);

        $localePath = self::resolveLocalePath($values);

        $timezone = trim((string) ($values['timezone'] ?? 'UTC'));
        $currency = self::resolveCurrency($values['currency'] ?? null);
        $dateFormat = trim((string) ($values['dateFormat'] ?? $values['date_format'] ?? 'medium'));
        $timeFormat = trim((string) ($values['timeFormat'] ?? $values['time_format'] ?? 'short'));
        $datetimeFormat = trim((string) ($values['datetimeFormat'] ?? $values['datetime_format'] ?? 'medium'));
        $groupingSeparator = self::resolveGroupingSeparator($values['groupingSeparator'] ?? $values['grouping_separator'] ?? null);

        if ($locale === '') {
            throw ConfigurationException::invalidValue('localization', 'locale', $locale, 'must be non-empty');
        }

        if ($timezone === '') {
            throw ConfigurationException::invalidValue('localization', 'timezone', $timezone, 'must be non-empty');
        }

        $supportedLocales = array_values(array_filter(array_map(static function (mixed $locale): string {
            return strtolower(trim((string) $locale));
        }, $supportedLocales), static fn (string $locale): bool => $locale !== ''));

        $localePath = ($localePath !== null && $localePath !== '') ? $localePath : null;

        return new self(
            locale: strtolower($locale),
            supportedLocales: $supportedLocales,
            localePath: $localePath,
            timezone: $timezone,
            currency: $currency,
            dateFormat: $dateFormat !== '' ? $dateFormat : 'medium',
            timeFormat: $timeFormat !== '' ? $timeFormat : 'short',
            datetimeFormat: $datetimeFormat !== '' ? $datetimeFormat : 'medium',
            groupingSeparator: $groupingSeparator,
        );
    }

    /**
     * Validate the grouping separator with FormatterInput. The locale-dependent checks run in Formatter.
     *
     * @throws ConfigurationException if the value is not a string, or not '' and refused by FormatterInput.
     */
    private static function resolveGroupingSeparator(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (!is_string($value)) {
            throw ConfigurationException::invalidValue('localization', 'grouping_separator', get_debug_type($value), 'must be a string');
        }

        $display = addcslashes($value, "\x00..\x1F\x7F");
        $refusal = FormatterInput::groupingSeparatorRefusal($value);
        if ($refusal !== null) {
            throw ConfigurationException::invalidValue('localization', 'grouping_separator', $display, $refusal);
        }

        return $value;
    }

    /**
     * Validate the currency code after trimming; '' means none.
     *
     * @throws ConfigurationException if the code is not three ASCII letters.
     */
    private static function resolveCurrency(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $currency = trim((string) $value);
        if ($currency === '') {
            return null;
        }

        if (!FormatterInput::isCurrencyCode($currency)) {
            $display = addcslashes($currency, "\x00..\x1F\x7F..\xFF");
            throw ConfigurationException::invalidValue('localization', 'currency', $display, FormatterInput::CURRENCY_CODE_RULE);
        }

        return $currency;
    }

    /**
     * Resolve the locale path from new or legacy config keys.
     *
     * @param array<string, mixed> $values
     */
    private static function resolveLocalePath(array $values): ?string
    {
        // localePath wins over the legacy keys, even when it is blank.
        if (isset($values['localePath']) || isset($values['locale_path'])) {
            $path = trim((string) ($values['localePath'] ?? $values['locale_path'] ?? ''));
            return $path !== '' ? $path : null;
        }

        $legacyPaths = $values['jsonLocalePaths'] ?? $values['json_locale_paths'] ?? null;
        if ($legacyPaths !== null && is_array($legacyPaths)) {
            $filtered = array_values(array_filter(array_map(static function (mixed $p): string {
                return trim((string) $p);
            }, $legacyPaths), static fn (string $p): bool => $p !== ''));
            return $filtered !== [] ? end($filtered) : null;
        }

        return null;
    }
}
