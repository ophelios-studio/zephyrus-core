<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Resolves the best locale from an Accept-Language header value, an optional
 * explicit requested locale (URL segment, cookie, etc.), and a whitelist of
 * supported locales.
 *
 * Resolution order:
 *   1. $requestedLocale (normalized; regional fallback to base language).
 *   2. Candidates parsed from $acceptLanguageHeader, ordered by q-value.
 *   3. $defaultLocale as the final fallback.
 *
 * When $supportedLocales is empty every normalized locale is considered valid.
 */
final class AcceptLanguageResolver
{
    /**
     * @param string   $acceptLanguageHeader  Raw Accept-Language header value.
     * @param string[] $supportedLocales       Allowlist. Empty means "accept any".
     * @param string   $defaultLocale          Returned when nothing else matches.
     * @param string   $requestedLocale        Explicit override (URL segment, cookie…).
     */
    public function resolve(
        string $acceptLanguageHeader,
        array $supportedLocales = [],
        string $defaultLocale = 'en',
        string $requestedLocale = '',
    ): string {
        // 1. Explicit requested locale wins if it is accepted.
        if ($requestedLocale !== '') {
            $normalized = $this->normalize($requestedLocale);

            if ($normalized !== '' && $this->isAccepted($normalized, $supportedLocales)) {
                return $normalized;
            }

            // Try the base language as a regional fallback (fr-CA → fr).
            $base = $this->base($normalized);
            if ($base !== $normalized && $this->isAccepted($base, $supportedLocales)) {
                return $base;
            }
        }

        // 2. Walk Accept-Language candidates in descending quality order.
        foreach ($this->parseHeader($acceptLanguageHeader) as $candidate) {
            if ($this->isAccepted($candidate, $supportedLocales)) {
                return $candidate;
            }

            // Regional fallback for each header candidate (fr-CA → fr).
            $base = $this->base($candidate);
            if ($base !== $candidate && $this->isAccepted($base, $supportedLocales)) {
                return $base;
            }
        }

        // 3. Default fallback.
        return $defaultLocale;
    }

    /**
     * Parse an Accept-Language header into an ordered list of normalized locale
     * codes, sorted by q-value descending (highest quality first).
     *
     * @return string[]
     */
    private function parseHeader(string $header): array
    {
        if (trim($header) === '') {
            return [];
        }

        $entries = [];

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

            // RFC7231: q=0 means "not acceptable".
            if ($locale !== '' && $quality > 0.0) {
                $entries[] = [$locale, $quality];
            }
        }

        // Stable descending sort by quality value.
        usort($entries, static fn(array $a, array $b): int => $b[1] <=> $a[1]);

        return array_column($entries, 0);
    }

    private function parseQuality(string $value): float
    {
        $quality = trim($value);

        // Keep default behavior for invalid q-values.
        if ($quality === '' || !is_numeric($quality)) {
            return 1.0;
        }

        return (float) $quality;
    }

    /**
     * Normalize a locale tag: underscores become hyphens, language code is
     * lowercased, optional region code is uppercased (e.g. FR-ca → fr-CA).
     */
    private function normalize(string $locale): string
    {
        $locale = trim(str_replace('_', '-', $locale));

        if ($locale === '') {
            return '';
        }

        $parts      = explode('-', $locale, 2);
        $normalized = strtolower($parts[0]);

        if (isset($parts[1])) {
            $normalized .= '-' . strtoupper($parts[1]);
        }

        return $normalized;
    }

    /**
     * Extract the base language code from a locale tag (fr-CA → fr).
     */
    private function base(string $locale): string
    {
        return explode('-', $locale, 2)[0];
    }

    /**
     * @param string[] $supported
     */
    private function isAccepted(string $locale, array $supported): bool
    {
        return $supported === [] || in_array($locale, $supported, true);
    }
}
