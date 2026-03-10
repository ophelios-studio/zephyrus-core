<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable localization bootstrap config.
 *
 * - locale: translator default locale token (e.g. 'en', 'fr-CA').
 * - supportedLocales: explicit locale allowlist for request negotiation.
 * - localePath: single directory containing locale subdirectories or files (optional).
 * - timezone: application timezone, applied via date_default_timezone_set() (default 'UTC').
 * - currency: default currency code for Formatter::money() (nullable).
 * - dateFormat: default ICU pattern or preset for Formatter::date() (default 'medium').
 * - timeFormat: default ICU pattern or preset for Formatter::time() (default 'short').
 * - datetimeFormat: default ICU pattern or preset for Formatter::datetime() (default 'medium').
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
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        // Support both new ('locale') and legacy ('defaultLocale', 'default_locale') keys
        $locale = trim((string) ($values['locale'] ?? $values['defaultLocale'] ?? $values['default_locale'] ?? 'en'));
        $supportedLocales = (array) ($values['supportedLocales'] ?? $values['supported_locales'] ?? []);

        // Support both new ('localePath') and legacy ('jsonLocalePaths', 'json_locale_paths') keys.
        // Legacy accepted an array; we take the last non-empty path from it.
        $localePath = self::resolveLocalePath($values);

        $timezone = trim((string) ($values['timezone'] ?? 'UTC'));
        $currency = isset($values['currency']) ? trim((string) $values['currency']) : null;
        $dateFormat = trim((string) ($values['dateFormat'] ?? $values['date_format'] ?? 'medium'));
        $timeFormat = trim((string) ($values['timeFormat'] ?? $values['time_format'] ?? 'short'));
        $datetimeFormat = trim((string) ($values['datetimeFormat'] ?? $values['datetime_format'] ?? 'medium'));

        if ($locale === '') {
            throw ConfigurationException::invalidValue('localization', 'locale', $locale, 'must be non-empty');
        }

        if ($timezone === '') {
            throw ConfigurationException::invalidValue('localization', 'timezone', $timezone, 'must be non-empty');
        }

        if ($currency === '') {
            $currency = null;
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
        );
    }

    /**
     * Resolve the locale path from new or legacy config keys.
     *
     * @param array<string, mixed> $values
     */
    private static function resolveLocalePath(array $values): ?string
    {
        // New key takes precedence
        if (isset($values['localePath']) || isset($values['locale_path'])) {
            $path = trim((string) ($values['localePath'] ?? $values['locale_path'] ?? ''));
            return $path !== '' ? $path : null;
        }

        // Legacy: jsonLocalePaths / json_locale_paths (array) — take last non-empty
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
