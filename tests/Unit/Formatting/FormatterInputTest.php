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
        yield 'apostrophe then thin space' => ["'\u{2009}"];
        yield 'thin space then apostrophe' => ["\u{2009}'"];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedGroupingSeparators(): iterable
    {
        yield 'digit' => ['1'];
        yield 'arabic-indic digit' => ["\u{0661}"];
        yield 'digit added in Unicode 17' => ["\u{11DE0}"];
        yield 'vulgar fraction one half' => ["\u{00BD}"];
        yield 'circled digit one' => ["\u{2460}"];
        yield 'latin letter' => ['x'];
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
        yield 'accepted character then right-to-left letter' => ["'\u{05F3}"];
    }

    #[DataProvider('acceptedGroupingSeparators')]
    public function testAcceptsSpacesAndNeutralPunctuation(string $separator): void
    {
        self::assertNull(FormatterInput::groupingSeparatorRefusal($separator));
    }

    public function testAcceptsADotOrACommaWhateverTheLocale(): void
    {
        self::assertNull(FormatterInput::groupingSeparatorRefusal('.'));
        self::assertNull(FormatterInput::groupingSeparatorRefusal(','));
    }

    #[DataProvider('refusedGroupingSeparators')]
    public function testRefusesACharacterThatCanHideOrReorderDigits(string $separator): void
    {
        $reason = FormatterInput::groupingSeparatorRefusal($separator);

        self::assertNotNull($reason);
        self::assertStringContainsString('right-to-left', $reason);
        self::assertStringContainsString('U+05D0', $reason);
    }

    public function testRefusesAnEmptySeparator(): void
    {
        self::assertSame('must not be empty', FormatterInput::groupingSeparatorRefusal(''));
    }

    public function testRefusesASeparatorOverFourBytes(): void
    {
        self::assertSame('must be at most 4 bytes', FormatterInput::groupingSeparatorRefusal("\u{2009}''"));
        self::assertSame('must be at most 4 bytes', FormatterInput::groupingSeparatorRefusal(str_repeat(' ', 100_000)));
    }

    public function testRefusesInvalidUtf8(): void
    {
        self::assertSame('must be valid UTF-8', FormatterInput::groupingSeparatorRefusal("\xC3\x28"));
        self::assertSame('must be valid UTF-8', FormatterInput::groupingSeparatorRefusal("\xED\xA0\x80"));
    }
}
