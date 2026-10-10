<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Formatting;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Formatting\FormatterInput;

final class FormatterInputTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedGroupingSeparators(): iterable
    {
        yield 'space' => [' '];
        yield 'no-break space' => ["\u{00A0}"];
        yield 'narrow no-break space' => ["\u{202F}"];
        yield 'thin space' => ["\u{2009}"];
        yield 'apostrophe' => ["'"];
        yield 'right single quotation mark' => ["\u{2019}"];
        yield 'comma' => [','];
        yield 'dot' => ['.'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedGroupingSeparators(): iterable
    {
        yield 'digit' => ['1'];
        yield 'two dots' => ['..'];
        yield 'arabic-indic digit' => ["\u{0661}"];
        yield 'digit added in Unicode 17' => ["\u{11DE0}"];
        yield 'vulgar fraction one half' => ["\u{00BD}"];
        yield 'circled digit one' => ["\u{2460}"];
        yield 'latin letter' => ['x'];
        yield 'plus sign' => ['+'];
        yield 'hyphen-minus' => ['-'];
        yield 'minus sign' => ["\u{2212}"];
        yield 'plus-minus sign' => ["\u{00B1}"];
        yield 'percent sign' => ['%'];
        yield 'dollar sign' => ['$'];
        yield 'euro sign' => ["\u{20AC}"];
        yield 'modifier letter prime' => ["\u{02B9}"];
        yield 'NUL' => ["\0"];
        yield 'tab' => ["\t"];
        yield 'line feed' => ["\n"];
        yield 'carriage return' => ["\r"];
        yield 'escape' => ["\x1B"];
        yield 'right-to-left override' => ["\u{202E}"];
        yield 'left-to-right isolate' => ["\u{2066}"];
        yield 'zero-width space' => ["\u{200B}"];
        yield 'byte order mark' => ["\u{FEFF}"];
        yield 'line separator' => ["\u{2028}"];
        yield 'paragraph separator' => ["\u{2029}"];
        yield 'hebrew geresh' => ["\u{05F3}"];
        yield 'hebrew maqaf' => ["\u{05BE}"];
        yield 'hebrew letter alef' => ["\u{05D0}"];
        yield 'arabic letter alef' => ["\u{0627}"];
        yield 'combining long stroke overlay' => ["\u{0336}"];
        yield 'combining long solidus overlay' => ["\u{0338}"];
        yield 'combining enclosing circle' => ["\u{20E0}"];
        yield 'combining grapheme joiner' => ["\u{034F}"];
        yield 'mongolian free variation selector' => ["\u{180B}"];
        yield 'variation selector' => ["\u{FE00}"];
        yield 'supplementary variation selector' => ["\u{E0100}"];
        yield 'hangul filler' => ["\u{3164}"];
        yield 'halfwidth hangul filler' => ["\u{FFA0}"];
        yield 'unassigned' => ["\u{2065}"];
        yield 'private use' => ["\u{E000}"];
        yield 'one-dot leader' => ["\u{2024}"];
        yield 'single low-9 quotation mark' => ["\u{201A}"];
        yield 'modifier letter minus sign' => ["\u{02D7}"];
        yield 'heavy plus sign' => ["\u{2795}"];
        yield 'hyphen' => ["\u{2010}"];
        yield 'en dash' => ["\u{2013}"];
        yield 'underscore' => ['_'];
        yield 'comma then space' => [', '];
        yield 'apostrophe then thin space' => ["'\u{2009}"];
        yield 'accepted character then right-to-left letter' => ["'\u{05F3}"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidCurrencyCodes(): iterable
    {
        yield 'zero' => ['0'];
        yield 'digits' => ['100'];
        yield 'markup' => ['<b>'];
        yield 'two letters' => ['US'];
        yield 'four letters' => ['USDX'];
        yield 'inner space' => ['C D'];
        yield 'NUL' => ["CA\0"];
        yield 'right-to-left override' => ["\u{202E}AB"];
        yield 'non-ASCII letter' => ["\u{00C9}U"];
    }

    #[DataProvider('acceptedGroupingSeparators')]
    public function testAcceptsTheListedSeparators(string $separator): void
    {
        self::assertNull(FormatterInput::groupingSeparatorRefusal($separator));
    }

    #[DataProvider('refusedGroupingSeparators')]
    public function testRefusesAnythingOutsideTheList(string $separator): void
    {
        self::assertSame(
            "must be one of , . ' U+2019, a space, U+00A0, U+202F or U+2009, or empty to turn grouping off",
            FormatterInput::groupingSeparatorRefusal($separator),
        );
    }

    public function testAcceptsThreeAsciiLettersAsACurrencyCode(): void
    {
        self::assertTrue(FormatterInput::isCurrencyCode('CAD'));
        self::assertTrue(FormatterInput::isCurrencyCode('eur'));
    }

    #[DataProvider('invalidCurrencyCodes')]
    public function testRefusesACurrencyCodeThatIsNotThreeAsciiLetters(string $code): void
    {
        self::assertFalse(FormatterInput::isCurrencyCode($code));
    }

    public function testRefusesAnEmptyOrPaddedCurrencyCode(): void
    {
        self::assertFalse(FormatterInput::isCurrencyCode(''));
        self::assertFalse(FormatterInput::isCurrencyCode(' CAD'));
        self::assertFalse(FormatterInput::isCurrencyCode("CAD\n"));
    }
}
