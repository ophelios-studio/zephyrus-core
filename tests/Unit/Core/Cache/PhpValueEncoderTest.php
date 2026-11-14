<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Cache;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Zephyrus\Core\Cache\BootCacheException;
use Zephyrus\Core\Cache\PhpValueEncoder;

final class PhpValueEncoderTest extends TestCase
{
    /**
     * @return array<string, array{mixed}>
     */
    public static function scalarValues(): array
    {
        return [
            'null' => [null],
            'false' => [false],
            'smallest integer' => [PHP_INT_MIN],
            'float without a fraction' => [3.0],
            'negative zero' => [-0.0],
            'infinite float' => [-INF],
            'string with quotes, a backslash and a closing tag' => ["it's \\ ?> \"done\""],
            'string with a NUL byte and a newline' => ["a\0b\nc"],
            'multibyte string' => ['été 東京'],
        ];
    }

    #[DataProvider('scalarValues')]
    public function testAScalarIsRebuiltIdentically(mixed $value): void
    {
        $rebuilt = eval('return ' . PhpValueEncoder::encode($value) . ';');

        self::assertSame(var_export($value, true), var_export($rebuilt, true));
    }

    public function testNestedArraysKeepTheirKeysAndOrder(): void
    {
        $value = ['b' => [2 => 'two', 0 => 'zero'], 'a' => [], '10' => true];

        $rebuilt = eval('return ' . PhpValueEncoder::encode($value) . ';');

        self::assertSame($value, $rebuilt);
    }

    public function testAYamlTagIsRebuiltAsATaggedValueAroundItsEncodedValue(): void
    {
        $value = ['secret' => new TaggedValue('env', 'DB_PASSWORD, local'), 'list' => new TaggedValue('custom', ['x' => new TaggedValue('env', 'X')])];

        $rebuilt = eval('return ' . PhpValueEncoder::encode($value) . ';');

        self::assertEquals($value, $rebuilt);
    }

    public function testAnyOtherObjectIsRefusedWithItsKeyPath(): void
    {
        try {
            PhpValueEncoder::encode(['database' => ['options' => [new \ArrayObject()]]]);
            self::fail('expected a refusal');
        } catch (BootCacheException $e) {
            self::assertSame(
                'Cannot cache the value at database.options.0: ArrayObject is not supported, only null, booleans, numbers, strings, arrays and YAML tags are.',
                $e->getMessage(),
            );
        }
    }

    public function testAnObjectInsideAYamlTagIsRefused(): void
    {
        try {
            PhpValueEncoder::encode(new TaggedValue('custom', new \stdClass()));
            self::fail('expected a refusal');
        } catch (BootCacheException $e) {
            self::assertSame(
                'Cannot cache the value at the top level: stdClass is not supported, only null, booleans, numbers, strings, arrays and YAML tags are.',
                $e->getMessage(),
            );
        }
    }
}
