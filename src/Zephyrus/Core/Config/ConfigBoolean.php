<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Http\IpRange;

/**
 * Strict reader for boolean configuration values.
 *
 * Accepts PHP booleans, the integers 0 and 1, and the strings true/false, 1/0,
 * on/off and yes/no (any case, surrounding whitespace ignored). Anything else,
 * including an empty string, is refused rather than read as false.
 *
 * @internal
 */
final class ConfigBoolean
{
    private const array TRUE_WORDS = ['true', '1', 'on', 'yes'];
    private const array FALSE_WORDS = ['false', '0', 'off', 'no'];

    /**
     * @throws ConfigurationException when the value is not a recognisable boolean.
     */
    public static function parse(string $section, string $key, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === 0) {
            return $value === 1;
        }

        if (is_string($value)) {
            $word = strtolower(trim($value));

            if (in_array($word, self::TRUE_WORDS, true)) {
                return true;
            }

            if (in_array($word, self::FALSE_WORDS, true)) {
                return false;
            }
        }

        throw ConfigurationException::invalidValue(
            $section,
            $key,
            self::shown($value),
            'is not a boolean; use true/false, 1/0, on/off or yes/no',
        );
    }

    private static function shown(mixed $value): string
    {
        if (is_string($value)) {
            return IpRange::shownEntry($value);
        }

        return is_scalar($value) ? var_export($value, true) : get_debug_type($value);
    }
}
