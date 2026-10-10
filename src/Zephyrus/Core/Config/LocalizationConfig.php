<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Formatting\FormatterInput;

/**
 * Immutable localization bootstrap config.
 *
 * YAML keys (aliases accepted where listed, one spelling per setting; any other key is refused):
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
 *   '' disables grouping. Otherwise one of `,` `.` `'` U+2019, a space, U+00A0, U+202F or U+2009.
 *   In a right-to-left locale, or inside right-to-left text, only `,` `.` U+00A0 and U+202F keep the
 *   groups in order; a space, U+2009, `'` or U+2019 can reverse them.
 */
final readonly class LocalizationConfig
{
    /** Keys that hold the legacy array form of localePath. */
    private const array LEGACY_LOCALE_PATH_KEYS = ['jsonLocalePaths', 'json_locale_paths'];

    /** Accepted keys per property, preferred first; any other key is refused. */
    private const array SPELLINGS = [
        'locale' => ['locale', 'defaultLocale', 'default_locale'],
        'supportedLocales' => ['supportedLocales', 'supported_locales'],
        'localePath' => ['localePath', 'locale_path', ...self::LEGACY_LOCALE_PATH_KEYS],
        'timezone' => ['timezone'],
        'currency' => ['currency'],
        'dateFormat' => ['dateFormat', 'date_format'],
        'timeFormat' => ['timeFormat', 'time_format'],
        'datetimeFormat' => ['datetimeFormat', 'datetime_format'],
        'groupingSeparator' => ['groupingSeparator', 'grouping_separator'],
    ];

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
     * @throws ConfigurationException if a key is unknown, a setting is written in two spellings, locale or timezone
     *         is blank, or currency or grouping_separator is invalid.
     */
    public static function fromArray(array $values): self
    {
        $keys = ConfigKeys::read('localization', $values, self::SPELLINGS);

        $locale = trim((string) ($keys->value('locale') ?? 'en'));
        $supportedLocales = (array) ($keys->value('supportedLocales') ?? []);

        $localePath = self::resolveLocalePath($keys);

        $timezone = trim((string) ($keys->value('timezone') ?? 'UTC'));
        $currency = self::resolveCurrency($keys->value('currency'));
        $dateFormat = trim((string) ($keys->value('dateFormat') ?? 'medium'));
        $timeFormat = trim((string) ($keys->value('timeFormat') ?? 'short'));
        $datetimeFormat = trim((string) ($keys->value('datetimeFormat') ?? 'medium'));
        $groupingSeparator = self::resolveGroupingSeparator($keys->value('groupingSeparator'));

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
            throw ConfigurationException::invalidValue('localization', 'grouping_separator', $value, 'must be a string');
        }

        $refusal = FormatterInput::groupingSeparatorRefusal($value);
        if ($refusal !== null) {
            throw ConfigurationException::invalidValue('localization', 'grouping_separator', $value, $refusal);
        }

        return $value;
    }

    /**
     * Validate the currency code after trimming; '' means none.
     *
     * @throws ConfigurationException if the value is not a string or the code is not three ASCII letters.
     */
    private static function resolveCurrency(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw ConfigurationException::invalidValue('localization', 'currency', $value, 'must be a string');
        }

        $currency = trim($value);
        if ($currency === '') {
            return null;
        }

        if (!FormatterInput::isCurrencyCode($currency)) {
            throw ConfigurationException::invalidValue('localization', 'currency', $currency, FormatterInput::CURRENCY_CODE_RULE);
        }

        return $currency;
    }

    /**
     * Resolve the locale path from new or legacy config keys.
     */
    private static function resolveLocalePath(ConfigKeys $keys): ?string
    {
        if (!in_array($keys->key('localePath'), self::LEGACY_LOCALE_PATH_KEYS, true)) {
            $path = trim((string) ($keys->value('localePath') ?? ''));
            return $path !== '' ? $path : null;
        }

        $legacyPaths = $keys->value('localePath');
        if (is_array($legacyPaths)) {
            $filtered = array_values(array_filter(array_map(static function (mixed $p): string {
                return trim((string) $p);
            }, $legacyPaths), static fn (string $p): bool => $p !== ''));
            return $filtered !== [] ? end($filtered) : null;
        }

        return null;
    }
}
