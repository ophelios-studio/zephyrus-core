<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Response;

final class ResponseTest extends TestCase
{
    public function testTextFactoryBuildsPlainTextResponse(): void
    {
        $response = Response::text('hello');

        self::assertSame(200, $response->status);
        self::assertSame('hello', $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testJsonFactoryEncodesPayloadAndSetsContentTypeHeader(): void
    {
        $response = Response::json(['ok' => true], 201);

        self::assertSame(201, $response->status);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testNoContentFactoryBuilds204WithoutBody(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
    }

    public function testWithHeaderReturnsNewResponseInstance(): void
    {
        $initial = Response::text('ok');
        $updated = $initial->withHeader('X-Trace-Id', 'abc123');

        self::assertNotSame($initial, $updated);
        self::assertArrayNotHasKey('X-Trace-Id', $initial->headers);
        self::assertSame('abc123', $updated->headers['X-Trace-Id']);
    }
}
