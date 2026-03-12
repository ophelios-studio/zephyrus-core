<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\RequestBody;

final class RequestBodyTest extends TestCase
{
    #[Test]
    public function getReturnsValueByKey(): void
    {
        $body = new RequestBody(['name' => 'Alice', 'age' => 30]);

        self::assertSame('Alice', $body->get('name'));
        self::assertSame(30, $body->get('age'));
    }

    #[Test]
    public function getReturnDefaultForMissingKey(): void
    {
        $body = new RequestBody(['name' => 'Alice']);

        self::assertNull($body->get('missing'));
        self::assertSame('default', $body->get('missing', 'default'));
    }

    #[Test]
    public function hasReturnsTrueForExistingKey(): void
    {
        $body = new RequestBody(['email' => 'alice@example.com']);

        self::assertTrue($body->has('email'));
        self::assertFalse($body->has('phone'));
    }

    #[Test]
    public function hasReturnsTrueForNullValues(): void
    {
        $body = new RequestBody(['key' => null]);

        self::assertTrue($body->has('key'));
    }

    #[Test]
    public function allReturnsEntireArray(): void
    {
        $data = ['a' => 1, 'b' => 2];
        $body = new RequestBody($data);

        self::assertSame($data, $body->all());
    }

    #[Test]
    public function isEmptyForEmptyBody(): void
    {
        $empty = new RequestBody();

        self::assertTrue($empty->isEmpty());
        self::assertSame([], $empty->all());
    }

    #[Test]
    public function isEmptyReturnsFalseWithData(): void
    {
        $body = new RequestBody(['key' => 'value']);

        self::assertFalse($body->isEmpty());
    }

    #[Test]
    public function rawReturnsRawBodyString(): void
    {
        $raw = '{"name":"Alice"}';
        $body = new RequestBody(['name' => 'Alice'], $raw);

        self::assertSame($raw, $body->raw());
    }

    #[Test]
    public function rawDefaultsToEmptyString(): void
    {
        $body = new RequestBody(['name' => 'Alice']);

        self::assertSame('', $body->raw());
    }
}
