<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Http\SseEvent;

/**
 * Three ways outside input used to reach a wire format unvalidated: a header
 * NAME, an SSE field, and the method-override parameter.
 */
final class HttpHardeningTest extends TestCase
{
    // =====================================================================
    // Response header names
    // =====================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidHeaderNameProvider(): array
    {
        return [
            'a whole header line' => ["Content-Length"  . ": 0\r\nX-Injected"],
            'crlf' => ["X-A\r\nX-B"],
            'lone newline' => ["X-A\nX-B"],
            'colon' => ['X-A: v'],
            'space' => ['X A'],
            'empty' => [''],
            'nul' => ["X-A\0"],
        ];
    }

    /**
     * withHeader() validated nothing, so a name taken from a request reached
     * the SAPI verbatim: a route echoing a path segment into a header name
     * emitted "content-length: v".
     */
    #[DataProvider('invalidHeaderNameProvider')]
    public function testWithHeaderRefusesANameOutsideTheTokenCharset(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::text('body')->withHeader($name, 'v');
    }

    #[DataProvider('invalidHeaderNameProvider')]
    public function testWithHeadersRefusesTheSameNames(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        Response::text('body')->withHeaders([$name => 'v']);
    }

    public function testOrdinaryHeaderNamesAreUnchanged(): void
    {
        $response = Response::text('body')
            ->withHeader('X-Trace-Id', 'abc')
            ->withHeaders(['Cache-Control' => 'no-store', 'X_Custom' => '1']);

        self::assertSame('abc', $response->headers['x-trace-id']);
        self::assertSame('no-store', $response->headers['cache-control']);
        self::assertSame('1', $response->headers['x_custom']);
    }

    public function testContentTooLargeHasAReasonPhrase(): void
    {
        // MaxBodySizeMiddleware answers 413, which had no entry in the phrase
        // map and would have shipped "HTTP/1.1 413 Unknown Status".
        self::assertSame('Content Too Large', Response::json([], 413)->statusPhrase());
    }

    // =====================================================================
    // SSE field injection
    // =====================================================================

    /**
     * @return array<string, array{0: ?string, 1: ?string}>
     */
    public static function injectedSseFieldProvider(): array
    {
        return [
            'newline in id' => ["1\nevent: admin", null],
            'crlf in id' => ["1\r\ndata: forged", null],
            'lone cr in id' => ["1\rdata: forged", null],
            'newline in event' => [null, "tick\ndata: forged"],
            'nul in event' => [null, "tick\0"],
        ];
    }

    /**
     * format() split `data` on newlines but interpolated `id` and `event` raw.
     * In text/event-stream a newline ENDS a field, so either one could forge
     * whole fields on the wire.
     */
    #[DataProvider('injectedSseFieldProvider')]
    public function testASseFieldCannotCarryALineTerminator(?string $id, ?string $event): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SseEvent(data: 'payload', event: $event, id: $id);
    }

    public function testOrdinarySseEventsAreUnchanged(): void
    {
        $event = new SseEvent(data: "line one\nline two", event: 'tick', id: '7', retry: 3000);

        self::assertSame(
            "retry: 3000\nid: 7\nevent: tick\ndata: line one\ndata: line two\n\n",
            $event->format(),
        );
    }

    public function testCarriageReturnsInDataBecomeSeparateDataLines(): void
    {
        // A lone CR terminates a line in event-stream too, so it must not be
        // emitted inside a data field.
        $event = new SseEvent(data: "a\r\nb\rc");

        self::assertSame("data: a\ndata: b\ndata: c\n\n", $event->format());
    }

    // =====================================================================
    // The method-override parameter
    // =====================================================================

    public function testANonScalarMethodOverrideDoesNotRaiseAWarning(): void
    {
        // "_method[]=PUT" reached a (string) cast and raised "Array to string
        // conversion" from inside fromGlobals(), which in the reference
        // bootstrap runs before the kernel's error handling exists.
        $request = Request::fromGlobals(
            server: [
                'REQUEST_URI' => '/things',
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'app.test',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            get: [],
            post: ['_method' => ['PUT']],
            cookie: [],
            files: [],
            rawBody: '',
        );

        self::assertSame('POST', $request->method);
    }

    public function testAScalarMethodOverrideStillWorks(): void
    {
        $request = Request::fromGlobals(
            server: [
                'REQUEST_URI' => '/things',
                'REQUEST_METHOD' => 'POST',
                'HTTP_HOST' => 'app.test',
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            ],
            get: [],
            post: ['_method' => 'put'],
            cookie: [],
            files: [],
            rawBody: '',
        );

        self::assertSame('PUT', $request->method);
    }
}
