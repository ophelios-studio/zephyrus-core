<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\PostgresArrayParser;

final class PostgresArrayParserTest extends TestCase
{
    /**
     * Every case is the text form pdo_pgsql returns for an array column, paired
     * with the value it must decode to. A malformed literal must come back
     * unchanged rather than silently truncated.
     *
     * @return iterable<string, array{string, mixed}>
     */
    public static function arrayLiterals(): iterable
    {
        yield 'plain elements' => ['{a,b}', ['a', 'b']];
        yield 'empty array' => ['{}', []];
        yield 'single empty string' => ['{""}', ['']];
        yield 'quoted comma stays in one element' => ['{"a,b"}', ['a,b']];
        yield 'quoted address with comma' => ['{"Rue du Pont, app. 4","Montréal"}', ['Rue du Pont, app. 4', 'Montréal']];
        yield 'quoted multibyte keeps its quotes out' => ['{"Firmin Témoin"}', ['Firmin Témoin']];
        yield 'multibyte unquoted' => ['{Zoë,Ångström}', ['Zoë', 'Ångström']];
        yield 'escaped double quote' => ['{"a\"b"}', ['a"b']];
        yield 'escaped backslash' => ['{"a\\\\b"}', ['a\\b']];
        yield 'brace inside quotes' => ['{"{x}"}', ['{x}']];
        yield 'unquoted NULL' => ['{NULL}', [null]];
        yield 'unquoted NULL is case-insensitive' => ['{null}', [null]];
        yield 'quoted NULL is the string' => ['{"NULL"}', ['NULL']];
        yield 'mixed NULL and quoted NULL' => ['{a,NULL,"NULL"}', ['a', null, 'NULL']];
        yield 'whitespace around unquoted elements is trimmed' => ['{ a , b }', ['a', 'b']];
        yield 'whitespace inside quotes is kept' => ['{"  x  "}', ['  x  ']];
        yield 'escaped trailing space is kept' => ['{a\\ }', ['a ']];
        yield 'escaped comma is part of the element' => ['{a\\,b}', ['a,b']];
        yield 'nul byte inside quotes' => ["{\"a\0b\"}", ["a\0b"]];
        yield 'nested arrays' => ['{{1,2},{3,4}}', [['1', '2'], ['3', '4']]];
        yield 'nested quoted commas' => ['{{"x,y"},{z}}', [['x,y'], ['z']]];
        yield 'custom lower bound decoration' => ['[0:1]={a,b}', ['a', 'b']];
        yield 'unterminated array is returned raw' => ['{a,b', '{a,b'];
        yield 'unterminated quote is returned raw' => ['{"a,b}', '{"a,b}'];
        yield 'empty element is returned raw' => ['{a,,b}', '{a,,b}'];
        yield 'trailing text is returned raw' => ['{a}x', '{a}x'];
        yield 'not an array is returned raw' => ['plain', 'plain'];
    }

    #[DataProvider('arrayLiterals')]
    public function testALiteralParsesToItsElements(string $literal, mixed $expected): void
    {
        self::assertSame($expected, PostgresArrayParser::parse($literal));
    }

    public function testALargeLiteralKeepsEveryElement(): void
    {
        $elements = [];
        for ($i = 0; $i < 5000; $i++) {
            $elements[] = '"item,' . $i . '"';
        }

        $array = PostgresArrayParser::parse('{' . implode(',', $elements) . '}');

        self::assertIsArray($array);
        self::assertCount(5000, $array);
        self::assertSame('item,4999', $array[4999]);
    }

    public function testAnEmptyStringIsReturnedRaw(): void
    {
        self::assertSame('', PostgresArrayParser::parse(''));
    }

    public function testADeeplyNestedLiteralParses(): void
    {
        self::assertSame([[[['x']]]], PostgresArrayParser::parse('{{{{x}}}}'));
    }
}
