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

    private const MULTIPART = ['CONTENT_TYPE' => 'multipart/form-data; boundary=form-boundary'];

    private const UPLOADED_FILE = ['name' => 'scan.pdf', 'type' => 'application/pdf', 'tmp_name' => 'php-upload', 'error' => UPLOAD_ERR_OK];

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

    /**
     * A multipart body sent without a Content-Length: PHP parses it itself and leaves php://input empty.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     */
    private static function chunkedMultipart(array $post, array $files = [], int $max = Request::MAX_BODY_SIZE_DEFAULT): Request
    {
        return Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload'] + self::MULTIPART,
            get: [],
            post: $post,
            cookie: [],
            files: $files,
            rawBody: '',
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
        self::assertTrue($request->body()->isTruncated());
        self::assertSame(413, self::middlewareStatus($request, 4));
    }

    public function testAnOverLimitBodyWithoutADeclaredLengthIsTruncatedAndMarkedMalformed(): void
    {
        $request = self::post('{"a":1}', 4);

        self::assertSame('{"a":', $request->body()->raw());
        self::assertSame([], $request->body()->all());
        self::assertTrue($request->body()->isTruncated());
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
        self::assertFalse($request->body()->isTruncated());
        self::assertSame(7, $request->body()->readLimit());
        self::assertSame(200, self::middlewareStatus($request, 7));
    }

    public function testAZeroLimitReadsALargeBodyInFull(): void
    {
        $raw = '{"a":"' . str_repeat('x', 3_000_000) . '"}';
        $request = self::post($raw, 0);

        self::assertSame($raw, $request->body()->raw());
        self::assertFalse($request->body()->isMalformed());
        self::assertFalse($request->body()->isTruncated());
        self::assertSame(0, $request->body()->readLimit());
    }

    public function testWithoutALimitArgumentABodyOverTwoMegabytesIsCutOneBytePastThem(): void
    {
        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/upload', 'CONTENT_TYPE' => 'application/octet-stream'],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: str_repeat('a', 3_145_728),
        );

        self::assertSame(2_097_153, strlen($request->body()->raw()));
        self::assertSame(2_097_152, $request->body()->readLimit());
        self::assertTrue($request->body()->isTruncated());
    }

    public function testWithoutALimitArgumentADeclaredLengthOverTwoMegabytesIsNeverRead(): void
    {
        FakePhpInputStream::install();

        try {
            $request = Request::fromGlobals(
                server: ['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/upload', 'CONTENT_LENGTH' => '314572800'],
                get: [],
                post: [],
                cookie: [],
                files: [],
            );
        } finally {
            FakePhpInputStream::restore();
        }

        self::assertSame(0, FakePhpInputStream::$opened);
        self::assertSame('', $request->body()->raw());
        self::assertTrue($request->body()->isTruncated());
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

    public function testAChunkedMultipartWhoseFieldsGoOverTwoMegabytesIsTruncatedAndReadsAsEmpty(): void
    {
        $body = self::chunkedMultipart(['first' => str_repeat('a', 3_145_728), 'second' => str_repeat('b', 3_145_728)])->body();

        self::assertSame([true, []], [$body->isTruncated(), $body->all()]);
    }

    public function testAChunkedMultipartOfThreeFilesOverTwoMegabytesIsTruncated(): void
    {
        $file = ['size' => 1_992_294] + self::UPLOADED_FILE;

        $body = self::chunkedMultipart(['title' => 'Scans'], ['first' => $file, 'second' => $file, 'third' => $file])->body();

        self::assertSame([true, []], [$body->isTruncated(), $body->all()]);
    }

    public function testAChunkedMultipartOfOneMegabyteIsParsed(): void
    {
        $body = self::chunkedMultipart(['notes' => str_repeat('a', 1_048_576)])->body();

        self::assertSame([false, 1_048_576], [$body->isTruncated(), strlen($body->get('notes'))]);
    }

    public function testAChunkedMultipartMeasuredExactlyAtTheLimitIsParsed(): void
    {
        $body = self::chunkedMultipart(['notes' => '123456789'], max: 14)->body();

        self::assertSame([false, ['notes' => '123456789']], [$body->isTruncated(), $body->all()]);
    }

    public function testAChunkedMultipartMeasuredOneBytePastTheLimitIsTruncated(): void
    {
        $body = self::chunkedMultipart(['notes' => '1234567890'], max: 14)->body();

        self::assertTrue($body->isTruncated());
    }

    public function testAZeroLimitParsesAChunkedMultipartOfAnySize(): void
    {
        $file = ['size' => 3_145_728] + self::UPLOADED_FILE;

        $body = self::chunkedMultipart(['title' => 'Scan'], ['scan' => $file], max: 0)->body();

        self::assertSame([false, ['title' => 'Scan']], [$body->isTruncated(), $body->all()]);
    }

    public function testAMultipartDeclaringALengthUnderTheLimitIsParsed(): void
    {
        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/upload', 'CONTENT_LENGTH' => '1048900'] + self::MULTIPART,
            get: [],
            post: ['notes' => str_repeat('a', 1_048_576)],
            cookie: [],
            files: [],
            rawBody: '',
        );

        self::assertFalse($request->body()->isTruncated());
    }
}
