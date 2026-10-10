<?php

declare(strict_types=1);

namespace Zephyrus\Formatting;

use IntlChar;

/**
 * Locale-independent checks on the grouping separator, shared by Formatter and LocalizationConfig so
 * both refuse the same values with the same reason.
 *
 * @internal
 */
final class FormatterInput
{
    private const int SEPARATOR_MAX_BYTES = 4;

    private const string SEPARATOR_CHARACTER_RULE = 'must contain only spaces, punctuation or symbols, not a letter, '
        . 'a number, or an invisible, combining or right-to-left character such as U+200B, U+0336 or U+05D0';

    // Bidi classes that stay put between digits on a left-to-right line: CS, ES, ET, ON, WS.
    private const array SEPARATOR_DIRECTIONS = [
        IntlChar::CHAR_DIRECTION_COMMON_NUMBER_SEPARATOR,
        IntlChar::CHAR_DIRECTION_EUROPEAN_NUMBER_SEPARATOR,
        IntlChar::CHAR_DIRECTION_EUROPEAN_NUMBER_TERMINATOR,
        IntlChar::CHAR_DIRECTION_OTHER_NEUTRAL,
        IntlChar::CHAR_DIRECTION_WHITE_SPACE_NEUTRAL,
    ];

    private const array SEPARATOR_CATEGORIES = [
        IntlChar::CHAR_CATEGORY_SPACE_SEPARATOR,
        IntlChar::CHAR_CATEGORY_DASH_PUNCTUATION,
        IntlChar::CHAR_CATEGORY_START_PUNCTUATION,
        IntlChar::CHAR_CATEGORY_END_PUNCTUATION,
        IntlChar::CHAR_CATEGORY_CONNECTOR_PUNCTUATION,
        IntlChar::CHAR_CATEGORY_OTHER_PUNCTUATION,
        IntlChar::CHAR_CATEGORY_INITIAL_PUNCTUATION,
        IntlChar::CHAR_CATEGORY_FINAL_PUNCTUATION,
        IntlChar::CHAR_CATEGORY_MATH_SYMBOL,
        IntlChar::CHAR_CATEGORY_CURRENCY_SYMBOL,
        IntlChar::CHAR_CATEGORY_MODIFIER_SYMBOL,
        IntlChar::CHAR_CATEGORY_OTHER_SYMBOL,
    ];

    /**
     * Returns why the grouping separator is refused, or null when it is accepted.
     */
    public static function groupingSeparatorRefusal(string $separator): ?string
    {
        if ($separator === '') {
            return 'must not be empty';
        }

        if (strlen($separator) > self::SEPARATOR_MAX_BYTES) {
            return 'must be at most 4 bytes';
        }

        if (preg_match('//u', $separator) !== 1) {
            return 'must be valid UTF-8';
        }

        foreach (mb_str_split($separator, 1, 'UTF-8') as $character) {
            if (!self::isSeparatorCharacter($character)) {
                return self::SEPARATOR_CHARACTER_RULE;
            }
        }

        return null;
    }

    private static function isSeparatorCharacter(string $character): bool
    {
        return in_array(IntlChar::charDirection($character), self::SEPARATOR_DIRECTIONS, true)
            && in_array(IntlChar::charType($character), self::SEPARATOR_CATEGORIES, true)
            && IntlChar::hasBinaryProperty($character, IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT) === false;
    }
}
