<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\EmitterInterface;
use Zephyrus\Http\Response;
use Zephyrus\Http\SapiEmitter;

final class SapiEmitterTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Contract
    // -------------------------------------------------------------------------

    public function testSapiEmitterImplementsEmitterInterface(): void
    {
        self::assertInstanceOf(EmitterInterface::class, new SapiEmitter());
    }

    // -------------------------------------------------------------------------
    // Body output (captured via output buffering)
    // -------------------------------------------------------------------------

    public function testEmitWritesTextBodyToOutputStream(): void
    {
        ob_start();
        (new SapiEmitter())->emit(Response::text('Hello, World!'));
        $output = (string) ob_get_clean();

        self::assertSame('Hello, World!', $output);
    }

    public function testEmitWritesJsonBodyToOutputStream(): void
    {
        ob_start();
        (new SapiEmitter())->emit(Response::json(['ok' => true]));
        $output = (string) ob_get_clean();

        self::assertSame('{"ok":true}', $output);
    }

    public function testEmitProducesNoOutputForEmptyBody(): void
    {
        ob_start();
        (new SapiEmitter())->emit(Response::noContent());
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function testEmitProducesNoOutputForExplicitlyEmptyBody(): void
    {
        ob_start();
        (new SapiEmitter())->emit(new Response(body: '', status: 200));
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function testEmitPreservesMultilineBody(): void
    {
        $body = "line one\nline two\nline three";

        ob_start();
        (new SapiEmitter())->emit(Response::text($body));
        $output = (string) ob_get_clean();

        self::assertSame($body, $output);
    }

    public function testEmitPreservesBinaryBody(): void
    {
        $body = "\x00\x01\x02\xFF";

        ob_start();
        (new SapiEmitter())->emit(new Response(body: $body, status: 200));
        $output = (string) ob_get_clean();

        self::assertSame($body, $output);
    }

    // -------------------------------------------------------------------------
    // Chunked streaming
    // -------------------------------------------------------------------------

    public function testEmitStreamsBodyInChunksWithDefaultChunkSize(): void
    {
        // 20 KiB body — larger than the 8 KiB default chunk.
        $body = str_repeat('A', 20 * 1024);

        ob_start();
        (new SapiEmitter())->emit(Response::text($body));
        $output = (string) ob_get_clean();

        self::assertSame($body, $output);
        self::assertSame(20 * 1024, strlen($output));
    }

    public function testEmitStreamsBodyWithCustomChunkSize(): void
    {
        // 25-byte body emitted with a 10-byte chunk size → 3 full chunks + 1 tail.
        $body = str_repeat('Z', 25);

        ob_start();
        (new SapiEmitter(chunkSize: 10))->emit(Response::text($body));
        $output = (string) ob_get_clean();

        self::assertSame($body, $output);
    }

    public function testEmitStreamsBodyThatFitsExactlyInOneChunk(): void
    {
        $body = str_repeat('B', 8192);

        ob_start();
        (new SapiEmitter())->emit(Response::text($body));
        $output = (string) ob_get_clean();

        self::assertSame($body, $output);
    }

    public function testEmitStreamsBodyShorterThanChunkSize(): void
    {
        $body = 'short';

        ob_start();
        (new SapiEmitter(chunkSize: 1024))->emit(Response::text($body));
        $output = (string) ob_get_clean();

        self::assertSame($body, $output);
    }

    public function testEmitStreamsBodyWithSingleByteChunks(): void
    {
        $body = 'abc';

        ob_start();
        (new SapiEmitter(chunkSize: 1))->emit(Response::text($body));
        $output = (string) ob_get_clean();

        self::assertSame('abc', $output);
    }

    // -------------------------------------------------------------------------
    // Status code (CLI SAPI supports http_response_code() tracking)
    // -------------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testEmitSendsDefaultStatusCode(): void
    {
        ob_start();
        (new SapiEmitter())->emit(Response::text('ok'));
        ob_end_clean();

        self::assertSame(200, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testEmitSendsCreatedStatusCode(): void
    {
        ob_start();
        (new SapiEmitter())->emit(Response::json(['id' => 1], 201));
        ob_end_clean();

        self::assertSame(201, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testEmitSendsNotFoundStatusCode(): void
    {
        ob_start();
        (new SapiEmitter())->emit(new Response(body: 'Not Found', status: 404));
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testEmitSendsNoContentStatusCode(): void
    {
        ob_start();
        (new SapiEmitter())->emit(Response::noContent());
        ob_end_clean();

        self::assertSame(204, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testEmitSendsMethodNotAllowedStatusCode(): void
    {
        $response = (new Response(body: 'Method Not Allowed', status: 405))
            ->withHeader('Allow', 'GET, POST');

        ob_start();
        (new SapiEmitter())->emit($response);
        ob_end_clean();

        self::assertSame(405, http_response_code());
        // Header values verified via toHeaderLines() — SAPI header list
        // is only inspectable in FPM/CGI contexts, not CLI.
        self::assertSame(['Allow: GET, POST'], $response->toHeaderLines());
    }

    // -------------------------------------------------------------------------
    // Header lines (pure; no SAPI interaction required)
    // -------------------------------------------------------------------------

    public function testEmitUsesCorrectStatusLineFormat(): void
    {
        $response = Response::text('ok');

        // Verify via the pure accessor — SAPI header_list() is FPM/CGI only.
        self::assertSame('HTTP/1.1 200 OK', $response->toStatusLine());
    }

    public function testEmitHeaderLinesContainCorrectContentTypeForText(): void
    {
        $response = Response::text('hello');

        self::assertSame(
            ['Content-Type: text/plain; charset=utf-8'],
            $response->toHeaderLines(),
        );
    }

    public function testEmitHeaderLinesContainCorrectContentTypeForJson(): void
    {
        $response = Response::json(['a' => 1]);

        self::assertSame(
            ['Content-Type: application/json; charset=utf-8'],
            $response->toHeaderLines(),
        );
    }
}
