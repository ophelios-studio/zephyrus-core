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
}
