<?php

declare(strict_types=1);

namespace Zephyrus\Formatting;

/**
 * Locale-independent checks on the grouping separator and currency code, shared by Formatter and
 * LocalizationConfig so both refuse the same values with the same reason.
 *
 * A grouping separator is one of `,` `.` `'` U+2019, a space, U+00A0, U+202F or U+2009, or '' to turn
 * grouping off. In a right-to-left locale, or inside right-to-left text, only `,` `.` U+00A0 and U+202F
 * keep the groups in order; a space, U+2009, `'` or U+2019 can reverse them.
 *
 * @internal
 */
final class FormatterInput
{
    public const string CURRENCY_CODE_RULE = 'must be three ASCII letters, for example CAD';

    private const string SEPARATOR_RULE = "must be one of , . ' U+2019, a space, U+00A0, U+202F or U+2009, "
        . 'or empty to turn grouping off';

    private const array GROUPING_SEPARATORS = [',', '.', "'", "\u{2019}", ' ', "\u{00A0}", "\u{202F}", "\u{2009}"];

    /**
     * Returns why the grouping separator is refused, or null when it is accepted. Callers handle '' before asking.
     */
    public static function groupingSeparatorRefusal(string $separator): ?string
    {
        return in_array($separator, self::GROUPING_SEPARATORS, true) ? null : self::SEPARATOR_RULE;
    }

    /**
     * Tells whether the code is three ASCII letters (the shape of an ISO 4217 code).
     */
    public static function isCurrencyCode(string $code): bool
    {
        return preg_match('/^[A-Za-z]{3}$/D', $code) === 1;
    }
}
