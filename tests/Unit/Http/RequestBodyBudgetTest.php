<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\MaxBodySizeMiddleware;

final class RequestBodyBudgetTest extends TestCase
{
    private const JSON = ['CONTENT_TYPE' => 'application/json'];

    /**
     * @param array<string, string> $headers
     */
    private static function post(string $raw, int $max, array $headers = self::JSON, ?array $post = null): Request
    {
        return Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload'] + $headers,
            get: [],
            post: $post ?? [],
            cookie: [],
            files: [],
            rawBody: $raw,
            maxBodySize: $max,
        );
    }

    private static function middlewareStatus(Request $request, int $max): int
    {
        return (new MaxBodySizeMiddleware($max))
            ->process($request, static fn (): Response => Response::text('HANDLED'))
            ->status;
    }

    public function testADeclaredLengthOverTheLimitLeavesTheRawBodyEmpty(): void
    {
        $request = self::post('{"a":1}', 4, self::JSON + ['CONTENT_LENGTH' => '7']);

        self::assertSame('', $request->body()->raw());
        self::assertSame(413, self::middlewareStatus($request, 4));
    }

    public function testAnOverLimitBodyWithoutADeclaredLengthIsTruncatedAndMarkedMalformed(): void
    {
        $request = self::post('{"a":1}', 4);

        self::assertSame('{"a":', $request->body()->raw());
        self::assertSame([], $request->body()->all());
        self::assertTrue($request->body()->isMalformed());
        self::assertSame(413, self::middlewareStatus($request, 4));
    }

    public function testAnOverLimitFormBodyIsNotAnalysed(): void
    {
        $request = self::post('a=1&b=2', 4, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], ['a' => '1', 'b' => '2']);

        self::assertSame([], $request->body()->all());
        self::assertTrue($request->body()->isMalformed());
    }

    public function testABodyExactlyAtTheLimitIsReadAndAnalysed(): void
    {
        $request = self::post('{"a":1}', 7);

        self::assertSame('{"a":1}', $request->body()->raw());
        self::assertSame(['a' => 1], $request->body()->all());
        self::assertFalse($request->body()->isMalformed());
        self::assertSame(200, self::middlewareStatus($request, 7));
    }

    public function testAZeroLimitReadsALargeBodyInFull(): void
    {
        $raw = '{"a":"' . str_repeat('x', 100_000) . '"}';
        $request = self::post($raw, 0);

        self::assertSame($raw, $request->body()->raw());
        self::assertFalse($request->body()->isMalformed());
    }

    public function testANegativeLimitIsRefused(): void
    {
        try {
            self::post('{}', -1);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame('Maximum body size must be 0 (unlimited) or a positive byte count.', $e->getMessage());
        }
    }

    public function testALyingContentLengthDoesNotHideAnOverLimitBody(): void
    {
        $request = self::post('{"a":1}', 4, self::JSON + ['CONTENT_LENGTH' => '3']);

        self::assertSame('{"a":', $request->body()->raw());
        self::assertSame(413, self::middlewareStatus($request, 4));
    }

    public function testADeclaredLengthEqualToTheLimitIsReadAndAnalysed(): void
    {
        $request = self::post('{"a":1}', 7, self::JSON + ['CONTENT_LENGTH' => '7']);

        self::assertSame(['a' => 1], $request->body()->all());
        self::assertSame(200, self::middlewareStatus($request, 7));
    }

    public function testADeclaredLengthWithSurroundingSpacesOverTheLimitIsNeverRead(): void
    {
        $request = self::post('{"a":1}', 4, self::JSON + ['CONTENT_LENGTH' => ' 20 ']);

        self::assertSame('', $request->body()->raw());
        self::assertSame(413, self::middlewareStatus($request, 4));
    }

    public function testTheLargestIntegerLimitReadsABodyInsteadOfFailing(): void
    {
        $request = self::post('{"a":1}', PHP_INT_MAX);

        self::assertSame(['a' => 1], $request->body()->all());
        self::assertSame(200, self::middlewareStatus($request, PHP_INT_MAX));
    }

    public function testTheRealInputStreamIsReadOnlyOneBlockPastTheLimit(): void
    {
        FakePhpInputStream::install();

        try {
            $request = Request::fromGlobals(
                server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload'] + self::JSON,
                get: [],
                post: [],
                cookie: [],
                files: [],
                maxBodySize: 100,
            );
        } finally {
            FakePhpInputStream::restore();
        }

        self::assertSame(101, strlen($request->body()->raw()));
        self::assertTrue($request->body()->isMalformed());
        self::assertLessThanOrEqual(100 + 8192, FakePhpInputStream::$served);
    }

    public function testTheRealInputStreamIsNotOpenedWhenTheDeclaredLengthIsOverTheLimit(): void
    {
        FakePhpInputStream::install();

        try {
            $request = Request::fromGlobals(
                server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload', 'CONTENT_LENGTH' => '10485760'] + self::JSON,
                get: [],
                post: [],
                cookie: [],
                files: [],
                maxBodySize: 100,
            );
        } finally {
            FakePhpInputStream::restore();
        }

        self::assertSame(0, FakePhpInputStream::$opened);
        self::assertSame('', $request->body()->raw());
    }
}
