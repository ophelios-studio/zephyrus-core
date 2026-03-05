<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

/**
 * Immutable localization bootstrap config.
 *
 * - defaultLocale: translator default locale token.
 * - supportedLocales: explicit locale allowlist for request negotiation.
 * - jsonLocalePaths: ordered JSON catalog directories (last path wins on key conflict).
 * - jsonExtension: locale file extension (default "json").
 */
final readonly class LocalizationConfig
{
    /**
     * @param string[] $supportedLocales
     * @param string[] $jsonLocalePaths
     */
    public function __construct(
        public string $defaultLocale,
        public array $supportedLocales,
        public array $jsonLocalePaths,
        public string $jsonExtension = 'json',
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $defaultLocale = trim((string) ($values['defaultLocale'] ?? $values['default_locale'] ?? 'en'));
        $supportedLocales = (array) ($values['supportedLocales'] ?? $values['supported_locales'] ?? []);
        $jsonLocalePaths = (array) ($values['jsonLocalePaths'] ?? $values['json_locale_paths'] ?? []);
        $jsonExtension = trim((string) ($values['jsonExtension'] ?? $values['json_extension'] ?? 'json'));

        if ($defaultLocale === '') {
            throw ConfigurationException::invalidValue('localization', 'defaultLocale', $defaultLocale, 'must be non-empty');
        }

        if ($jsonExtension === '') {
            throw ConfigurationException::invalidValue('localization', 'jsonExtension', $jsonExtension, 'must be non-empty');
        }

        $supportedLocales = array_values(array_filter(array_map(static function (mixed $locale): string {
            return strtolower(trim((string) $locale));
        }, $supportedLocales), static fn (string $locale): bool => $locale !== ''));

        $jsonLocalePaths = array_values(array_filter(array_map(static function (mixed $path): string {
            return trim((string) $path);
        }, $jsonLocalePaths), static fn (string $path): bool => $path !== ''));

        return new self(
            defaultLocale: strtolower($defaultLocale),
            supportedLocales: $supportedLocales,
            jsonLocalePaths: $jsonLocalePaths,
            jsonExtension: ltrim(strtolower($jsonExtension), '.'),
        );
    }
}
