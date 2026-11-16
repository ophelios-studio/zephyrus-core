<?php

declare(strict_types=1);

namespace Zephyrus\Localization;

/**
 * Shape check for locale tags that may reach a file path or a catalog lookup.
 *
 * The underscore is accepted next to the hyphen because catalog directories may be named like fr_CA.
 */
final class LocaleTag
{
    private const string PATTERN = '/^[A-Za-z]{2,3}([-_][A-Za-z0-9]{2,8})*$/D';

    /**
     * Whether $tag is a 2-3 letter language followed by alphanumeric subtags of 2 to 8 characters.
     */
    public static function isWellFormed(string $tag): bool
    {
        return preg_match(self::PATTERN, $tag) === 1;
    }

    /**
     * BCP 47 casing: underscores become hyphens, language lowercase, script title case, region uppercase. '' when nothing remains.
     *
     * @internal
     */
    public static function canonical(string $tag): string
    {
        $parts = array_values(array_filter(explode('-', str_replace('_', '-', $tag)), static fn (string $part): bool => $part !== ''));
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
}
