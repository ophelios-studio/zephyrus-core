<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Response;

final class ResponseTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Factory methods
    // -------------------------------------------------------------------------

    public function testTextFactoryBuildsPlainTextResponse(): void
    {
        $response = Response::text('hello');

        self::assertSame(200, $response->status);
        self::assertSame('hello', $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testHtmlFactoryBuildsHtmlResponse(): void
    {
        $response = Response::html('<h1>Hello</h1>', 202);

        self::assertSame(202, $response->status);
        self::assertSame('<h1>Hello</h1>', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testJsonFactoryEncodesPayloadAndSetsContentTypeHeader(): void
    {
        $response = Response::json(['ok' => true], 201);

        self::assertSame(201, $response->status);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testJsonFactoryAcceptsScalarPayloads(): void
    {
        $response = Response::json('ok');

        self::assertSame('"ok"', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testJsonFactoryAcceptsObjectPayloads(): void
    {
        $response = Response::json((object) ['id' => 7]);

        self::assertSame('{"id":7}', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
    }

    public function testNoContentFactoryBuilds204WithoutBody(): void
    {
        $response = Response::noContent();

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
    }

    public function testRedirectFactoryDefaultsTo302(): void
    {
        $response = Response::redirect('/login');

        self::assertSame(302, $response->status);
        self::assertSame('/login', $response->headers['Location']);
        self::assertSame('', $response->body);
    }

    public function testRedirectFactoryAllows301PermanentRedirect(): void
    {
        $response = Response::redirect('/new-home', 301);

        self::assertSame(301, $response->status);
        self::assertSame('/new-home', $response->headers['Location']);
    }

    public function testRedirectFactoryAllows303SeeOther(): void
    {
        $response = Response::redirect('/dashboard', 303);

        self::assertSame(303, $response->status);
        self::assertSame('/dashboard', $response->headers['Location']);
    }

    public function testRedirectFactoryAllows307TemporaryRedirect(): void
    {
        $response = Response::redirect('/retry', 307);

        self::assertSame(307, $response->status);
        self::assertSame('/retry', $response->headers['Location']);
    }

    public function testRedirectFactoryAllows308PermanentRedirectPreservingMethod(): void
    {
        $response = Response::redirect('/new-api', 308);

        self::assertSame(308, $response->status);
        self::assertSame('/new-api', $response->headers['Location']);
    }

    public function testRedirectFactoryAcceptsAbsoluteUrl(): void
    {
        $response = Response::redirect('https://example.com/path?q=1');

        self::assertSame(302, $response->status);
        self::assertSame('https://example.com/path?q=1', $response->headers['Location']);
        self::assertSame('', $response->body);
    }

    // -------------------------------------------------------------------------
    // Immutable mutation helpers
    // -------------------------------------------------------------------------

    public function testWithHeaderReturnsNewResponseInstance(): void
    {
        $initial = Response::text('ok');
        $updated = $initial->withHeader('X-Trace-Id', 'abc123');

        self::assertNotSame($initial, $updated);
        self::assertArrayNotHasKey('X-Trace-Id', $initial->headers);
        self::assertSame('abc123', $updated->headers['X-Trace-Id']);
    }

    public function testWithHeaderPreservesExistingHeadersAndBody(): void
    {
        $initial = Response::json(['ok' => true]);
        $updated = $initial->withHeader('X-Request-Id', 'r1');

        self::assertSame($initial->body, $updated->body);
        self::assertSame($initial->status, $updated->status);
        self::assertSame('application/json; charset=utf-8', $updated->headers['Content-Type']);
        self::assertSame('r1', $updated->headers['X-Request-Id']);
    }

    public function testWithHeadersMergesMultipleHeadersIntoNewInstance(): void
    {
        $initial = Response::text('ok')->withHeader('X-Request-Id', 'req-1');
        $updated = $initial->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Trace-Id' => 'trace-1',
        ]);

        self::assertNotSame($initial, $updated);
        self::assertArrayNotHasKey('Cache-Control', $initial->headers);
        self::assertSame('req-1', $updated->headers['X-Request-Id']);
        self::assertSame('no-store', $updated->headers['Cache-Control']);
        self::assertSame('trace-1', $updated->headers['X-Trace-Id']);
    }

    public function testWithHeadersOverwritesMatchingHeaderNames(): void
    {
        $initial = Response::json(['ok' => true]);
        $updated = $initial->withHeaders([
            'Content-Type' => 'application/problem+json; charset=utf-8',
        ]);

        self::assertSame(
            'application/problem+json; charset=utf-8',
            $updated->headers['Content-Type'],
        );
    }

    public function testWithoutHeaderReturnsNewResponseWithoutSpecifiedHeader(): void
    {
        $initial = Response::json(['ok' => true])->withHeader('X-Trace-Id', 'abc123');
        $updated = $initial->withoutHeader('X-Trace-Id');

        self::assertNotSame($initial, $updated);
        self::assertArrayHasKey('X-Trace-Id', $initial->headers);
        self::assertArrayNotHasKey('X-Trace-Id', $updated->headers);
        self::assertArrayHasKey('Content-Type', $updated->headers);
    }

    public function testWithoutHeaderNoOpsWhenHeaderDoesNotExist(): void
    {
        $initial = Response::text('ok');
        $updated = $initial->withoutHeader('X-Missing');

        self::assertSame($initial->headers, $updated->headers);
    }

    public function testWithStatusReturnsNewResponseWithUpdatedStatus(): void
    {
        $initial = Response::text('created', 200);
        $updated = $initial->withStatus(201);

        self::assertNotSame($initial, $updated);
        self::assertSame(200, $initial->status);
        self::assertSame(201, $updated->status);
        self::assertSame($initial->body, $updated->body);
        self::assertSame($initial->headers, $updated->headers);
    }

    // -------------------------------------------------------------------------
    // statusPhrase()
    // -------------------------------------------------------------------------

    /** @return array<string, array{int, string}> */
    public static function provideCommonStatusCodes(): array
    {
        return [
            '200 OK'                    => [200, 'OK'],
            '201 Created'               => [201, 'Created'],
            '202 Accepted'              => [202, 'Accepted'],
            '204 No Content'            => [204, 'No Content'],
            '301 Moved Permanently'     => [301, 'Moved Permanently'],
            '302 Found'                 => [302, 'Found'],
            '304 Not Modified'          => [304, 'Not Modified'],
            '400 Bad Request'           => [400, 'Bad Request'],
            '401 Unauthorized'          => [401, 'Unauthorized'],
            '403 Forbidden'             => [403, 'Forbidden'],
            '404 Not Found'             => [404, 'Not Found'],
            '405 Method Not Allowed'    => [405, 'Method Not Allowed'],
            '409 Conflict'              => [409, 'Conflict'],
            '422 Unprocessable Content' => [422, 'Unprocessable Content'],
            '429 Too Many Requests'     => [429, 'Too Many Requests'],
            '500 Internal Server Error' => [500, 'Internal Server Error'],
            '502 Bad Gateway'           => [502, 'Bad Gateway'],
            '503 Service Unavailable'   => [503, 'Service Unavailable'],
        ];
    }

    #[DataProvider('provideCommonStatusCodes')]
    public function testStatusPhraseReturnsCorrectReasonPhrase(int $code, string $expected): void
    {
        $response = new Response(status: $code);

        self::assertSame($expected, $response->statusPhrase());
    }

    public function testStatusPhraseForUnknownCodeReturnsUnknownStatus(): void
    {
        $response = new Response(status: 999);

        self::assertSame('Unknown Status', $response->statusPhrase());
    }

    public function testStatusPhraseForUnregisteredCustomCodeReturnsUnknownStatus(): void
    {
        $response = new Response(status: 218);

        self::assertSame('Unknown Status', $response->statusPhrase());
    }

    // -------------------------------------------------------------------------
    // toStatusLine()
    // -------------------------------------------------------------------------

    public function testToStatusLineFormatsHttpVersionCodeAndPhrase(): void
    {
        $response = Response::text('ok');

        self::assertSame('HTTP/1.1 200 OK', $response->toStatusLine());
    }

    public function testToStatusLineFor201Created(): void
    {
        $response = Response::json(['id' => 1], 201);

        self::assertSame('HTTP/1.1 201 Created', $response->toStatusLine());
    }

    public function testToStatusLineFor404NotFound(): void
    {
        $response = new Response(status: 404);

        self::assertSame('HTTP/1.1 404 Not Found', $response->toStatusLine());
    }

    public function testToStatusLineFor500InternalServerError(): void
    {
        $response = new Response(status: 500);

        self::assertSame('HTTP/1.1 500 Internal Server Error', $response->toStatusLine());
    }

    public function testToStatusLineForUnknownCodeIncludesUnknownStatusPhrase(): void
    {
        $response = new Response(status: 999);

        self::assertSame('HTTP/1.1 999 Unknown Status', $response->toStatusLine());
    }

    // -------------------------------------------------------------------------
    // send() — body output (captured via output buffering, no headers needed)
    // -------------------------------------------------------------------------

    public function testSendWritesBodyToOutputStream(): void
    {
        $response = Response::text('Hello, World!');

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        self::assertSame('Hello, World!', $output);
    }

    public function testSendWritesJsonBodyToOutputStream(): void
    {
        $response = Response::json(['status' => 'ok']);

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        self::assertSame('{"status":"ok"}', $output);
    }

    public function testSendProducesEmptyOutputForNoContentResponse(): void
    {
        $response = Response::noContent();

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function testSendWritesEmptyBodyWhenBodyIsEmpty(): void
    {
        $response = new Response(body: '', status: 200);

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        self::assertSame('', $output);
    }

    public function testSendPreservesMultilineBody(): void
    {
        $body = "line one\nline two\nline three";
        $response = Response::text($body);

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        self::assertSame($body, $output);
    }

    public function testSendPreservesBinaryBody(): void
    {
        $body = "\x00\x01\x02\xFF";
        $response = new Response(body: $body, status: 200);

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        self::assertSame($body, $output);
    }

    // -------------------------------------------------------------------------
    // toHeaderLines() — pure header-line builder (testable without SAPI)
    // -------------------------------------------------------------------------

    public function testToHeaderLinesReturnsEmptyArrayWhenNoHeaders(): void
    {
        $response = Response::noContent();

        self::assertSame([], $response->toHeaderLines());
    }

    public function testToHeaderLinesFormatsNameColonValueStrings(): void
    {
        $response = Response::json(['ok' => true]);

        self::assertSame(
            ['Content-Type: application/json; charset=utf-8'],
            $response->toHeaderLines(),
        );
    }

    public function testToHeaderLinesIncludesAllHeaders(): void
    {
        $response = Response::json(['ok' => true])
            ->withHeader('X-Request-Id', 'abc-123')
            ->withHeader('X-Powered-By', 'Zephyrus');

        self::assertSame(
            [
                'Content-Type: application/json; charset=utf-8',
                'X-Request-Id: abc-123',
                'X-Powered-By: Zephyrus',
            ],
            $response->toHeaderLines(),
        );
    }

    public function testToHeaderLinesPreservesInsertionOrder(): void
    {
        $response = (new Response(status: 405))
            ->withHeader('Allow', 'GET, POST')
            ->withHeader('Content-Type', 'text/plain; charset=utf-8');

        self::assertSame(
            ['Allow: GET, POST', 'Content-Type: text/plain; charset=utf-8'],
            $response->toHeaderLines(),
        );
    }

    // -------------------------------------------------------------------------
    // send() — status code emission (isolated per-process via http_response_code)
    //
    // Note: PHP CLI SAPI does not surface headers through headers_list(); header
    // content is verified via toHeaderLines() tests above.  Status-code tracking
    // via http_response_code() does work in CLI, so we use that for the SAPI
    // integration signal.
    // -------------------------------------------------------------------------

    #[RunInSeparateProcess]
    public function testSendEmitsStatusCodeToSapi(): void
    {
        $response = Response::text('ok');

        ob_start();
        $response->send();
        ob_end_clean();

        self::assertSame(200, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testSendEmitsCustomStatusCodeToSapi(): void
    {
        $response = Response::json(['id' => 42], 201);

        ob_start();
        $response->send();
        ob_end_clean();

        self::assertSame(201, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testSendEmits404StatusCodeToSapi(): void
    {
        $response = new Response(body: 'Not Found', status: 404);

        ob_start();
        $response->send();
        ob_end_clean();

        self::assertSame(404, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testSendEmits204StatusCodeForNoContent(): void
    {
        $response = Response::noContent();

        ob_start();
        $response->send();
        ob_end_clean();

        self::assertSame(204, http_response_code());
    }

    #[RunInSeparateProcess]
    public function testSendEmits405StatusCodeWithAllowHeaderData(): void
    {
        $response = (new Response(body: 'Method Not Allowed', status: 405))
            ->withHeader('Allow', 'GET, POST');

        ob_start();
        $response->send();
        ob_end_clean();

        self::assertSame(405, http_response_code());
        // Header content is validated via toHeaderLines(); SAPI header list is
        // only available in CGI/FPM contexts, not PHP CLI.
        self::assertSame(['Allow: GET, POST'], $response->toHeaderLines());
    }
}
