<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Response;

final class ResponseTest extends TestCase
{
    public function testTextResponseSetsExpectedContentType(): void
    {
        $response = Response::text('ok');

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testJsonResponseEncodesPayloadAndSetsContentType(): void
    {
        $response = Response::json(['ok' => true], 201);

        self::assertSame(201, $response->status);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testWithHeaderReturnsNewInstance(): void
    {
        $response = Response::text('ok');
        $withHeader = $response->withHeader('X-Test', '1');

        self::assertNotSame($response, $withHeader);
        self::assertArrayNotHasKey('X-Test', $response->headers);
        self::assertSame('1', $withHeader->headers['X-Test']);
    }
}
