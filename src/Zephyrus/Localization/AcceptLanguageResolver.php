<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Picks the locale for a request from an explicit locale, an Accept-Language header, then a default.
 *
 * Order: $requestedLocale (normalized, then progressively shortened: zh-Hant-TW, zh-Hant, zh), the header
 * candidates by q-value (a '*' range selects $defaultLocale), then $defaultLocale, replaced by the first
 * supported locale when unsupported. An empty $supportedLocales accepts every normalized locale.
 */
final class AcceptLanguageResolver
{
    /** Tags longer than this are ignored (RFC 5646 practical maximum). */
    private const MAX_TAG_LENGTH = 35;

    /**
     * @param string   $acceptLanguageHeader Raw header value, may be empty.
     * @param string[] $supportedLocales     Allowlist. Empty means "accept any".
     * @param string   $defaultLocale        Used when nothing matches. An empty value becomes 'en'.
     * @param string   $requestedLocale      Explicit override, may be empty. A '*' is ignored.
     */
    public function resolve(
        string $acceptLanguageHeader,
        array $supportedLocales = [],
        string $defaultLocale = 'en',
        string $requestedLocale = '',
    ): string {
        $supportedLocales = array_values(array_filter(array_map(
            fn (string $locale): string => $this->normalize($locale),
            $supportedLocales,
        ), static fn (string $locale): bool => $locale !== ''));

        $defaultLocale = $this->normalize($defaultLocale);
        if ($defaultLocale === '') {
            $defaultLocale = 'en';
        }

        if ($supportedLocales !== []) {
            $defaultLocale = $this->resolveAcceptedLocale($defaultLocale, $supportedLocales)
                ?? $supportedLocales[0];
        }

        if ($requestedLocale !== '') {
            $normalized = $this->normalize($requestedLocale);

            if ($normalized !== '' && $normalized !== '*') {
                $acceptedRequested = $this->resolveAcceptedLocale($normalized, $supportedLocales);
                if ($acceptedRequested !== null) {
                    return $acceptedRequested;
                }
            }
        }

        foreach ($this->parseHeader($acceptLanguageHeader) as $candidate) {
            if ($candidate === '*') {
                return $defaultLocale;
            }

            $acceptedCandidate = $this->resolveAcceptedLocale($candidate, $supportedLocales);
            if ($acceptedCandidate !== null) {
                return $acceptedCandidate;
            }
        }

        return $defaultLocale;
    }

    /**
     * Parses the header into normalized locales, highest q-value first, dropping q=0 and duplicates.
     *
     * @return string[]
     */
    private function parseHeader(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }

        $entries = [];
        $position = 0;

        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $locale   = $this->normalize(trim($segments[0]));
            $quality  = 1.0;

            foreach (array_slice($segments, 1) as $param) {
                $param = trim($param);
                if (str_starts_with(strtolower($param), 'q=')) {
                    $quality = $this->parseQuality(substr($param, 2));
                    break;
                }
            }

            // q=0 means "not acceptable" (RFC 7231).
            if ($locale !== '' && $quality > 0.0) {
                $entries[] = [$locale, $quality, $position];
            }

            $position++;
        }

        usort($entries, static function (array $a, array $b): int {
            $qualityCompare = $b[1] <=> $a[1];
            if ($qualityCompare !== 0) {
                return $qualityCompare;
            }

            return $a[2] <=> $b[2];
        });

        $ordered = [];
        foreach ($entries as [$locale]) {
            if (in_array($locale, $ordered, true)) {
                continue;
            }

            $ordered[] = $locale;
        }

        return $ordered;
    }

    /**
     * An invalid q-value counts as 1.0; valid values are clamped to [0, 1].
     */
    private function parseQuality(string $value): float
    {
        $quality = trim($value);

        if ($quality === '' || !is_numeric($quality)) {
            return 1.0;
        }

        $parsed = (float) $quality;

        if ($parsed < 0.0) {
            return 0.0;
        }

        if ($parsed > 1.0) {
            return 1.0;
        }

        return $parsed;
    }

    /**
     * Underscores become hyphens, language lowercased, script title-cased, region uppercased (FR-ca gives fr-CA).
     * Returns '' for an empty or over-long tag. A '*' passes through unchanged.
     */
    private function normalize(string $locale): string
    {
        $locale = trim(str_replace('_', '-', $locale));

        if ($locale === '' || strlen($locale) > self::MAX_TAG_LENGTH) {
            return '';
        }

        if ($locale === '*') {
            return '*';
        }

        $parts = array_values(array_filter(explode('-', $locale), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return '';
        }

        $normalized   = [strtolower($parts[0])];
        $subtagCount  = count($parts);

        for ($index = 1; $index < $subtagCount; $index++) {
            $subtag = $parts[$index];

            if (strlen($subtag) === 4 && ctype_alpha($subtag)) {
                $normalized[] = ucfirst(strtolower($subtag));
                continue;
            }

            if ((strlen($subtag) === 2 && ctype_alpha($subtag)) || (strlen($subtag) === 3 && ctype_digit($subtag))) {
                $normalized[] = strtoupper($subtag);
                continue;
            }

            $normalized[] = strtolower($subtag);
        }

        return implode('-', $normalized);
    }

    /**
     * @param string[] $supported
     */
    private function isAccepted(string $locale, array $supported): bool
    {
        return $supported === [] || in_array($locale, $supported, true);
    }

    /**
     * @param string[] $supported
     */
    private function resolveAcceptedLocale(string $locale, array $supported): ?string
    {
        if ($locale === '') {
            return null;
        }

        if ($this->isAccepted($locale, $supported)) {
            return $locale;
        }

        $parts = explode('-', $locale);
        while (count($parts) > 1) {
            array_pop($parts);
            $fallback = implode('-', $parts);

            if ($this->isAccepted($fallback, $supported)) {
                return $fallback;
            }
        }

        return null;
    }
}
