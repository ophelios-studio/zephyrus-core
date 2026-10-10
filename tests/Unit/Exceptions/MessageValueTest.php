<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Zephyrus\Exceptions\MessageValue;

final class MessageValueTest extends TestCase
{
    public function testQuotesPlainTextUnchanged(): void
    {
        self::assertSame('"users.show"', MessageValue::quote('users.show'));
    }

    public function testQuotesEmptyAndZeroStrings(): void
    {
        self::assertSame('""', MessageValue::quote(''));
        self::assertSame('"0"', MessageValue::quote('0'));
    }

    public function testEscapesQuotesAndBackslashesButNotSlashes(): void
    {
        self::assertSame('"a\"b\\\\c/d"', MessageValue::quote('a"b\c/d'));
    }

    public function testWritesC0ControlsAsJsonDoes(): void
    {
        self::assertSame('"\n\t\r\u0000\u001b"', MessageValue::quote("\n\t\r\0\x1b"));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function controlAndFormatCharacters(): iterable
    {
        yield 'DEL' => ["\x7f", '\u007f'];
        yield 'U+0080' => ["\u{0080}", '\u0080'];
        yield 'NEL' => ["\u{0085}", '\u0085'];
        yield 'U+009F' => ["\u{009F}", '\u009f'];
        yield 'soft hyphen' => ["\u{00AD}", '\u00ad'];
        yield 'Arabic letter mark' => ["\u{061C}", '\u061c'];
        yield 'zero width space' => ["\u{200B}", '\u200b'];
        yield 'zero width joiner' => ["\u{200D}", '\u200d'];
        yield 'right-to-left mark' => ["\u{200F}", '\u200f'];
        yield 'line separator' => ["\u{2028}", '\u2028'];
        yield 'paragraph separator' => ["\u{2029}", '\u2029'];
        yield 'right-to-left override' => ["\u{202E}", '\u202e'];
        yield 'first strong isolate' => ["\u{2068}", '\u2068'];
        yield 'byte order mark' => ["\u{FEFF}", '\ufeff'];
        yield 'tag letter A' => ["\u{E0041}", '\udb40\udc41'];
    }

    #[DataProvider('controlAndFormatCharacters')]
    public function testEscapesControlAndFormatCharacters(string $character, string $escape): void
    {
        self::assertSame('"a' . $escape . 'b"', MessageValue::quote('a' . $character . 'b'));
    }

    public function testKeepsAccentsSymbolsAndOtherScriptsReadable(): void
    {
        $value = "café Ünïcode 日本語 €100 \u{00A0}\u{202F}🙂";

        self::assertSame('"' . $value . '"', MessageValue::quote($value, 100));
    }

    public function testReplacesInvalidUtf8WithTheReplacementCharacter(): void
    {
        self::assertSame("\"a\u{FFFD}b\u{FFFD}\"", MessageValue::quote("a\xffb\xc3"));
    }

    public function testKeepsAValueOfExactlyTheLimit(): void
    {
        $value = str_repeat('a', 64);

        self::assertSame('"' . $value . '"', MessageValue::quote($value));
    }

    public function testCutsALongerValueAndGivesItsLength(): void
    {
        self::assertSame('"' . str_repeat('a', 64) . '..." (65 bytes)', MessageValue::quote(str_repeat('a', 65)));
    }

    public function testCutsBeforeACharacterTheLimitWouldSplit(): void
    {
        $value = str_repeat('a', 63) . 'é' . 'zz';

        self::assertSame('"' . str_repeat('a', 63) . '..." (67 bytes)', MessageValue::quote($value));
    }

    public function testKeepsTheEndAfterACharacterTheLimitWouldSplit(): void
    {
        $value = 'zz' . 'é' . str_repeat('a', 63);

        self::assertSame('"...' . str_repeat('a', 63) . '" (67 bytes)', MessageValue::quote($value, keepEnd: true));
    }

    public function testKeepsAWholeMultibyteCharacterAtTheEnd(): void
    {
        $value = str_repeat('a', 10) . str_repeat('é', 32);

        self::assertSame('"...' . str_repeat('é', 32) . '" (74 bytes)', MessageValue::quote($value, keepEnd: true));
    }

    public function testEscapesTheKeptPartOfACutValue(): void
    {
        self::assertSame(
            '"' . str_repeat('\u001b', 64) . '..." (1000000 bytes)',
            MessageValue::quote(str_repeat("\x1b", 1_000_000)),
        );
    }

    public function testCutsAtTheGivenLimit(): void
    {
        self::assertSame('"abc..." (6 bytes)', MessageValue::quote('abcdef', 3));
        self::assertSame('"...def" (6 bytes)', MessageValue::quote('abcdef', 3, true));
        self::assertSame('"..." (6 bytes)', MessageValue::quote('abcdef', 0));
    }

    public function testCutsInvalidUtf8WithoutLosingTheLength(): void
    {
        self::assertSame(
            '"' . str_repeat("\u{FFFD}", 64) . '..." (70 bytes)',
            MessageValue::quote(str_repeat("\xff", 70)),
        );
    }

    public function testTheQuotedValueDecodesBackToTheOriginalAsJson(): void
    {
        $value = "a\x7f\u{0085}\u{202E}\"\\b\n\t\0\u{2028}\u{E0041}/é";

        self::assertSame($value, json_decode(MessageValue::quote($value), flags: JSON_THROW_ON_ERROR));
    }

    public function testTheQuotedValuePastedIntoYamlLoadsAsTheOriginal(): void
    {
        $value = "a\x7f\u{0085}\u{202E}\"\\b\n\t\0\u{2028}/é";

        self::assertSame(['key' => $value], Yaml::parse('key: ' . MessageValue::quote($value)));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function describedValues(): iterable
    {
        yield 'string' => ['abc', '"abc"'];
        yield 'empty string' => ['', '""'];
        yield 'int' => [42, '42'];
        yield 'negative int' => [-1, '-1'];
        yield 'float' => [1.5, '1.5'];
        yield 'whole float' => [1.0, '1.0'];
        yield 'infinite float' => [INF, 'INF'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'null' => [null, 'null'];
        yield 'array' => [['a'], 'array'];
        yield 'object' => [new \stdClass(), 'stdClass'];
    }

    #[DataProvider('describedValues')]
    public function testDescribesEachType(mixed $value, string $expected): void
    {
        self::assertSame($expected, MessageValue::describe($value));
    }

    public function testDescribeCutsALongString(): void
    {
        self::assertSame('"' . str_repeat('a', 64) . '..." (70 bytes)', MessageValue::describe(str_repeat('a', 70)));
    }

    public function testQuoteListDescribesEachValue(): void
    {
        self::assertSame('"a\u001b", 1, null, array', MessageValue::quoteList(["a\x1b", 1, null, []]));
    }

    public function testQuoteListAcceptsAnyIterable(): void
    {
        $values = (static function (): \Generator {
            yield 'x' => 'one';
            yield 'y' => false;
        })();

        self::assertSame('"one", false', MessageValue::quoteList($values));
        self::assertSame('', MessageValue::quoteList([]));
    }

    public function testEscapeControlsLeavesQuotesAndBackslashes(): void
    {
        self::assertSame(
            'Route "GET /x" cannot skip "X\u001b\u202e\u0085\u007f": not a Zephyrus\Http\MiddlewareInterface',
            MessageValue::escapeControls("Route \"GET /x\" cannot skip \"X\x1b\u{202E}\u{0085}\x7f\": not a Zephyrus\\Http\\MiddlewareInterface"),
        );
    }

    public function testEscapeControlsReplacesInvalidUtf8(): void
    {
        self::assertSame("a\u{FFFD}\\n", MessageValue::escapeControls("a\xff\n"));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function controlCharacters(): iterable
    {
        yield 'line feed' => ["\n"];
        yield 'carriage return' => ["\r"];
        yield 'tab' => ["\t"];
        yield 'backspace' => ["\x08"];
        yield 'form feed' => ["\x0c"];
        yield 'start of heading' => ["\x01"];
        yield 'escape' => ["\x1b"];
        yield 'delete' => ["\x7f"];
        yield 'next line' => ["\u{0085}"];
        yield 'right-to-left override' => ["\u{202E}"];
        yield 'line separator' => ["\u{2028}"];
        yield 'tag letter' => ["\u{E0041}"];
    }

    #[DataProvider('controlCharacters')]
    public function testEscapeControlsWritesEachControlAsQuoteDoes(string $character): void
    {
        self::assertSame(substr(MessageValue::quote($character), 1, -1), MessageValue::escapeControls($character));
    }

    public function testEscapeControlsKeepsABackslashBeforeAnEscapedCharacter(): void
    {
        self::assertSame('C:\dir\u202e "x\" \\\\', MessageValue::escapeControls("C:\\dir\u{202E} \"x\\\" \\\\"));
    }

    public function testTheControlEscapeFailsClosedWhenTheRegexRefusesItsInput(): void
    {
        $escapeControlCharacters = new \ReflectionMethod(MessageValue::class, 'escapeControlCharacters');

        self::assertSame('a\377\033', $escapeControlCharacters->invoke(null, "a\xff\x1b"));
    }
}
