<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use InvalidArgumentException;
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
        self::assertSame('text/plain; charset=utf-8', $response->headers['content-type']);
    }

    public function testHtmlFactoryBuildsHtmlResponse(): void
    {
        $response = Response::html('<h1>Hello</h1>', 202);

        self::assertSame(202, $response->status);
        self::assertSame('<h1>Hello</h1>', $response->body);
        self::assertSame('text/html; charset=utf-8', $response->headers['content-type']);
    }

    public function testJsonFactoryEncodesPayloadAndSetsContentTypeHeader(): void
    {
        $response = Response::json(['ok' => true], 201);

        self::assertSame(201, $response->status);
        self::assertSame('{"ok":true}', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
    }

    public function testJsonFactoryAcceptsScalarPayloads(): void
    {
        $response = Response::json('ok');

        self::assertSame('"ok"', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
    }

    public function testJsonFactoryAcceptsObjectPayloads(): void
    {
        $response = Response::json((object) ['id' => 7]);

        self::assertSame('{"id":7}', $response->body);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
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
        self::assertSame('/login', $response->headers['location']);
        self::assertSame('', $response->body);
    }

    public function testRedirectFactoryAllows301PermanentRedirect(): void
    {
        $response = Response::redirect('/new-home', 301);

        self::assertSame(301, $response->status);
        self::assertSame('/new-home', $response->headers['location']);
    }

    public function testRedirectFactoryAllows303SeeOther(): void
    {
        $response = Response::redirect('/dashboard', 303);

        self::assertSame(303, $response->status);
        self::assertSame('/dashboard', $response->headers['location']);
    }

    public function testRedirectFactoryAllows307TemporaryRedirect(): void
    {
        $response = Response::redirect('/retry', 307);

        self::assertSame(307, $response->status);
        self::assertSame('/retry', $response->headers['location']);
    }

    public function testRedirectFactoryAllows308PermanentRedirectPreservingMethod(): void
    {
        $response = Response::redirect('/new-api', 308);

        self::assertSame(308, $response->status);
        self::assertSame('/new-api', $response->headers['location']);
    }

    public function testRedirectFactoryAcceptsAbsoluteUrl(): void
    {
        $response = Response::redirect('https://example.com/path?q=1');

        self::assertSame(302, $response->status);
        self::assertSame('https://example.com/path?q=1', $response->headers['location']);
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
        self::assertArrayNotHasKey('x-trace-id', $initial->headers);
        self::assertSame('abc123', $updated->headers['x-trace-id']);
    }

    public function testWithHeaderPreservesExistingHeadersAndBody(): void
    {
        $initial = Response::json(['ok' => true]);
        $updated = $initial->withHeader('X-Request-Id', 'r1');

        self::assertSame($initial->body, $updated->body);
        self::assertSame($initial->status, $updated->status);
        self::assertSame('application/json; charset=utf-8', $updated->headers['content-type']);
        self::assertSame('r1', $updated->headers['x-request-id']);
    }

    public function testWithHeadersMergesMultipleHeadersIntoNewInstance(): void
    {
        $initial = Response::text('ok')->withHeader('X-Request-Id', 'req-1');
        $updated = $initial->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Trace-Id' => 'trace-1',
        ]);

        self::assertNotSame($initial, $updated);
        self::assertArrayNotHasKey('cache-control', $initial->headers);
        self::assertSame('req-1', $updated->headers['x-request-id']);
        self::assertSame('no-store', $updated->headers['cache-control']);
        self::assertSame('trace-1', $updated->headers['x-trace-id']);
    }

    public function testWithHeadersOverwritesMatchingHeaderNames(): void
    {
        $initial = Response::json(['ok' => true]);
        $updated = $initial->withHeaders([
            'Content-Type' => 'application/problem+json; charset=utf-8',
        ]);

        self::assertSame(
            'application/problem+json; charset=utf-8',
            $updated->headers['content-type'],
        );
    }

    public function testWithoutHeaderReturnsNewResponseWithoutSpecifiedHeader(): void
    {
        $initial = Response::json(['ok' => true])->withHeader('X-Trace-Id', 'abc123');
        $updated = $initial->withoutHeader('X-Trace-Id');

        self::assertNotSame($initial, $updated);
        self::assertArrayHasKey('x-trace-id', $initial->headers);
        self::assertArrayNotHasKey('x-trace-id', $updated->headers);
        self::assertArrayHasKey('content-type', $updated->headers);
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
    // send(): body output (captured via output buffering, no headers needed)
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
    // toHeaderLines(): pure header-line builder (testable without SAPI)
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
            ['content-type: application/json; charset=utf-8'],
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
                'content-type: application/json; charset=utf-8',
                'x-request-id: abc-123',
                'x-powered-by: Zephyrus',
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
            ['allow: GET, POST', 'content-type: text/plain; charset=utf-8'],
            $response->toHeaderLines(),
        );
    }

    // -------------------------------------------------------------------------
    // send(): status code emission
    //
    // headers_list() is not visible under the CLI SAPI; headers are checked via toHeaderLines().
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
        self::assertSame(['allow: GET, POST'], $response->toHeaderLines());
    }

    /**
     * A request target must stay on this site: anything a browser could read as
     * another origin, or a header break, falls back.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function localRedirectTargets(): iterable
    {
        yield 'plain path' => ['/account', '/account'];
        yield 'path with query and fragment' => ['/a?b=c#d', '/a?b=c#d'];
        yield 'root' => ['/', '/'];
        yield 'encoded CRLF is still a local path' => ['/a%0d%0aSet-Cookie', '/a%0d%0aSet-Cookie'];
        yield 'non-ASCII path' => ['/café', '/café'];
        yield 'protocol-relative URL' => ['//evil.example', '/'];
        yield 'slash then backslash' => ['/\evil.example', '/'];
        yield 'double backslash' => ['\\\\evil', '/'];
        yield 'absolute URL' => ['https://evil.example', '/'];
        yield 'javascript scheme' => ['javascript:alert(1)', '/'];
        yield 'tab inside the path' => ["/a\tb", '/'];
        yield 'CRLF inside the path' => ["/a\r\nSet-Cookie: x=1", '/'];
        yield 'NUL byte' => ["/a\0b", '/'];
        yield 'backslash inside the path' => ['/a\\b', '/'];
        yield 'empty string' => ['', '/'];
        yield 'relative path without a slash' => ['evil.example', '/'];
        yield 'leading space' => [' /account', '/'];
    }

    #[DataProvider('localRedirectTargets')]
    public function testLocalRedirectKeepsOnlyLocalTargets(string $target, string $expected): void
    {
        $response = Response::localRedirect($target);

        self::assertSame(302, $response->status);
        self::assertSame($expected, $response->headers['location']);
    }

    /**
     * A request parameter can arrive as an array (?next[]=x), so a non-string
     * target must fall back rather than raise a TypeError on every such request.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringLocalRedirectTargets(): iterable
    {
        yield 'array' => [['/account']];
        yield 'empty array' => [[]];
        yield 'integer' => [42];
        yield 'null' => [null];
        yield 'boolean' => [true];
    }

    #[DataProvider('nonStringLocalRedirectTargets')]
    public function testLocalRedirectFallsBackForANonStringTarget(mixed $target): void
    {
        $response = Response::localRedirect($target);

        self::assertSame('/', $response->headers['location']);
    }

    public function testLocalRedirectUsesAFallbackThatIsNotTheRoot(): void
    {
        $response = Response::localRedirect('//evil.example', '/home', 303);

        self::assertSame(303, $response->status);
        self::assertSame('/home', $response->headers['location']);
    }

    public function testLocalRedirectRefusesAFallbackThatIsNotLocal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Response::localRedirect('/account', 'https://evil.example');
    }

    public function testHasHeaderIsFalseOnAFreshResponse(): void
    {
        self::assertFalse(Response::text('ok')->hasHeader('X-Frame-Options'));
    }

    public function testHasHeaderIsCaseInsensitiveAfterWithHeader(): void
    {
        $response = Response::text('ok')->withHeader('Referrer-Policy', 'no-referrer');

        self::assertTrue($response->hasHeader('referrer-policy'));
        self::assertTrue($response->hasHeader('REFERRER-POLICY'));
    }

    public function testHasHeaderFindsANameSetByTheConstructorInAnyCase(): void
    {
        $response = new Response(headers: ['X-Frame-Options' => 'DENY']);

        self::assertTrue($response->hasHeader('x-frame-options'));
    }

    public function testHasHeaderIsAPresenceCheckSoAnEmptyValueCounts(): void
    {
        $response = Response::text('ok')->withHeader('X-Frame-Options', '');

        self::assertTrue($response->hasHeader('X-Frame-Options'));
    }

    public function testGetHeaderReturnsTheStoredValueInAnyCase(): void
    {
        $response = Response::text('ok')->withHeader('Referrer-Policy', 'no-referrer');

        self::assertSame('no-referrer', $response->getHeader('REFERRER-POLICY'));
    }

    public function testGetHeaderReturnsNullWhenTheHeaderIsAbsent(): void
    {
        self::assertNull(Response::text('ok')->getHeader('Referrer-Policy'));
    }

    public function testHasHeaderIsFalseForAnEmptyNameAndADifferentName(): void
    {
        $response = Response::text('ok')->withHeader('X-Frame-Options', 'DENY');

        self::assertFalse($response->hasHeader(''));
        self::assertFalse($response->hasHeader('X-Frame'));
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function headerValuesForNonBlankCheck(): iterable
    {
        yield 'absent' => [null, false];
        yield 'empty' => ['', false];
        yield 'spaces' => ['   ', false];
        yield 'tab and newline' => ["\t\n", false];
        yield 'value with a NUL byte' => ["\0x", true];
        yield 'value' => ['DENY', true];
        yield 'zero' => ['0', true];
    }

    public function testConstructorNormalisesHeaderNamesToLowercase(): void
    {
        $response = new Response(headers: ['Content-Type' => 'text/plain', 'X-Test' => '']);

        self::assertSame(['content-type' => 'text/plain', 'x-test' => ''], $response->headers);
    }

    public function testConstructorHeaderIsReplacedNotDuplicatedByAMutator(): void
    {
        $response = (new Response(headers: ['X-Test' => '   ']))->withHeader('x-test', 'value');

        self::assertSame(['x-test' => 'value'], $response->headers);
        self::assertSame(['x-test: value'], array_values(array_filter(
            $response->toHeaderLines(),
            static fn (string $line): bool => stripos($line, 'x-test:') === 0,
        )));
    }

    public function testWithoutHeaderRemovesAHeaderSetThroughTheConstructorWithMixedCase(): void
    {
        $response = (new Response(headers: ['X-Test' => 'value']))->withoutHeader('X-TEST');

        self::assertFalse($response->hasHeader('x-test'));
        self::assertSame([], $response->headers);
    }

    #[DataProvider('headerValuesForNonBlankCheck')]
    public function testHasNonBlankHeaderIgnoresBlankValuesWhateverTheNameCase(?string $value, bool $expected): void
    {
        $response = $value === null ? new Response() : new Response(headers: ['X-Test' => $value]);

        self::assertSame($expected, $response->hasNonBlankHeader('x-TEST'));
    }
}
