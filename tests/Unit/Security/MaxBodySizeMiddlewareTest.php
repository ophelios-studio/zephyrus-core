<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\BodyTooLarge;
use Zephyrus\Security\MaxBodySizeMiddleware;
use Zephyrus\Upload\FileUpload;

final class MaxBodySizeMiddlewareTest extends TestCase
{
    public function testMaxBytesIsExposed(): void
    {
        self::assertSame(1_024, (new MaxBodySizeMiddleware(1_024))->maxBytes());
        self::assertSame(0, (new MaxBodySizeMiddleware(0))->maxBytes());
    }

    public function testMaxBodySizeMiddlewareMeasuresTheRawBodyWhenNoLengthIsDeclared(): void
    {
        $middleware = new MaxBodySizeMiddleware(4);
        $request = Request::fromArray('POST', '/upload', rawBody: 'abcdefgh');

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
    }

    public function testMaxBodySizeMiddlewareMeasuresTheRawBodyWhenTheDeclaredLengthIsSmaller(): void
    {
        $middleware = new MaxBodySizeMiddleware(4);
        $request = Request::fromArray('POST', '/upload', headers: ['Content-Length' => '3'], rawBody: 'abcdefgh');

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
    }

    public function testMaxBodySizeMiddlewareMeasuresTheDeclaredLengthWhenItIsLargerThanANonEmptyRawBody(): void
    {
        $middleware = new MaxBodySizeMiddleware(4);
        $request = Request::fromArray('POST', '/upload', headers: ['Content-Length' => '9'], rawBody: 'abc');

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
    }

    public function testMaxBodySizeMiddlewareMeasuresUploadedFilesWhateverLengthIsDeclared(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_000);
        $request = Request::fromArray('POST', '/upload', files: [
            'doc' => new FileUpload('a.bin', 'application/octet-stream', '/tmp/phpA', 1_048_576),
        ], headers: ['Content-Length' => '10']);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
    }

    public function testMaxBodySizeMiddlewareAddsUpTheFilesOfAnArrayField(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_000);
        $request = Request::fromArray('POST', '/upload', files: [
            'docs' => [
                new FileUpload('a.bin', 'application/octet-stream', '/tmp/phpA', 600),
                new FileUpload('b.bin', 'application/octet-stream', '/tmp/phpB', 600),
            ],
        ]);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
    }

    public function testMaxBodySizeMiddlewareLetsAnHonestMultipartThrough(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_000);
        $request = Request::fromArray('POST', '/upload', files: [
            'doc' => new FileUpload('a.bin', 'application/octet-stream', '/tmp/phpA', 600),
        ], headers: ['Content-Length' => '700']);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(200, $response->status);
    }

    public function testMaxBodySizeMiddlewareLetsAnAcceptableBodyThrough(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_024);
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '512']);

        self::assertSame(
            'HANDLED',
            $middleware->process($request, static fn (): Response => Response::text('HANDLED'))->body,
        );
    }

    public function testMaxBodySizeMiddlewareTreatsZeroAsUnlimited(): void
    {
        $middleware = new MaxBodySizeMiddleware(0);
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '999999999']);

        self::assertSame(
            'HANDLED',
            $middleware->process($request, static fn (): Response => Response::text('HANDLED'))->body,
        );
    }

    public function testDefaultRefusalIsJsonWhenNoAcceptHeaderIsSent(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_024);
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '2048']);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type'] ?? '');
        self::assertSame('{"error":"Request body too large."}', $response->body);
    }

    public function testDefaultRefusalIsJsonForAnApiClient(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_024);
        $request = Request::fromArray(
            'POST',
            '/upload',
            headers: ['content-length' => '2048', 'accept' => 'application/json'],
        );

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type'] ?? '');
        self::assertSame('{"error":"Request body too large."}', $response->body);
    }

    public function testDefaultRefusalIsPlainTextWhenAcceptListsHtml(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_024);
        $request = Request::fromArray(
            'POST',
            '/upload',
            headers: [
                'content-length' => '2048',
                'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
        );

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->headers['content-type'] ?? '');
        self::assertSame('Request body too large.', $response->body);
    }

    public function testDefaultRefusalIsPlainTextWhenAcceptListsHtmlInUpperCase(): void
    {
        $middleware = new MaxBodySizeMiddleware(4);
        $request = Request::fromArray('POST', '/upload', headers: ['accept' => 'TEXT/HTML'], rawBody: 'abcdefgh');

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->headers['content-type'] ?? '');
        self::assertSame('Request body too large.', $response->body);
    }

    public function testNullFromOnFailureFallsBackToPlainTextWhenAcceptListsHtml(): void
    {
        $middleware = new MaxBodySizeMiddleware(
            1_024,
            onFailure: static fn (Request $request, BodyTooLarge $refusal): ?Response => null,
        );
        $request = Request::fromArray(
            'POST',
            '/upload',
            headers: ['content-length' => '2048', 'accept' => 'text/html'],
        );

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
        self::assertSame('text/plain; charset=utf-8', $response->headers['content-type'] ?? '');
        self::assertSame('Request body too large.', $response->body);
    }

    public function testOnFailureResponseIsAnsweredInsteadOfTheDefault(): void
    {
        $middleware = new MaxBodySizeMiddleware(
            1_024,
            onFailure: static fn (Request $request, BodyTooLarge $refusal): ?Response => Response::redirect('/form'),
        );
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '2048']);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(302, $response->status);
        self::assertSame('/form', $response->headers['location'] ?? '');
    }

    public function testNullFromOnFailureKeepsTheDefaultResponse(): void
    {
        $middleware = new MaxBodySizeMiddleware(
            1_024,
            onFailure: static fn (Request $request, BodyTooLarge $refusal): ?Response => null,
        );
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '2048']);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type'] ?? '');
        self::assertSame('{"error":"Request body too large."}', $response->body);
    }

    public function testOnFailureReceivesTheDeclaredLengthAndLimit(): void
    {
        $seen = [];
        $middleware = new MaxBodySizeMiddleware(
            1_024,
            onFailure: static function (Request $request, BodyTooLarge $refusal) use (&$seen): ?Response {
                $seen[] = [$refusal->bytes, $refusal->maxBytes];

                return null;
            },
        );
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '2048']);

        $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame([[2048, 1_024]], $seen);
    }

    public function testOnFailureReceivesTheRawBodyLengthAndLimit(): void
    {
        $seen = [];
        $middleware = new MaxBodySizeMiddleware(
            4,
            onFailure: static function (Request $request, BodyTooLarge $refusal) use (&$seen): ?Response {
                $seen[] = [$refusal->bytes, $refusal->maxBytes];

                return null;
            },
        );
        $request = Request::fromArray('POST', '/upload', rawBody: 'abcdefgh');

        $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame([[8, 4]], $seen);
    }

    public function testOnFailureIsNotCalledForABodyWithinBudget(): void
    {
        $calls = 0;
        $middleware = new MaxBodySizeMiddleware(
            1_024,
            onFailure: static function (Request $request, BodyTooLarge $refusal) use (&$calls): ?Response {
                ++$calls;

                return null;
            },
        );
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '512']);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(0, $calls);
        self::assertSame('HANDLED', $response->body);
    }

    public function testOnFailureIsNotCalledWhenMaxBytesIsZero(): void
    {
        $calls = 0;
        $middleware = new MaxBodySizeMiddleware(
            0,
            onFailure: static function (Request $request, BodyTooLarge $refusal) use (&$calls): ?Response {
                ++$calls;

                return null;
            },
        );
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '999999999']);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(0, $calls);
        self::assertSame('HANDLED', $response->body);
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function statusOfParsedBody(array $body, ?string $contentType, string $rawBody = ''): int
    {
        $headers = ['Content-Length' => '10'];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        $request = Request::fromArray('POST', '/upload', body: $body, headers: $headers, rawBody: $rawBody);

        return (new MaxBodySizeMiddleware(1_000))
            ->process($request, static fn (): Response => Response::text('HANDLED'))
            ->status;
    }

    public function testATextFieldOfAMultipartBodyCountsAgainstTheBudget(): void
    {
        $status = self::statusOfParsedBody(['note' => str_repeat('a', 2_000)], 'multipart/form-data; boundary=x');

        self::assertSame(413, $status);
    }

    public function testTheKeysOfANestedMultipartFieldCountAgainstTheBudget(): void
    {
        $body = ['list' => [str_repeat('k', 600) => 'v', str_repeat('j', 600) => ['w' => 'x']]];

        self::assertSame(413, self::statusOfParsedBody($body, 'multipart/form-data; boundary=x'));
    }

    public function testAMultipartBodyWithinTheBudgetPasses(): void
    {
        $status = self::statusOfParsedBody(['a' => 'xx', 'n' => ['k' => 'yyy']], 'multipart/form-data; boundary=x');

        self::assertSame(200, $status);
    }

    public function testMultipartFieldsOfExactlyTheLimitPassAndOneByteMoreIsRefused(): void
    {
        $contentType = 'multipart/form-data; boundary=x';

        self::assertSame(200, self::statusOfParsedBody(['k' => str_repeat('a', 999)], $contentType));
        self::assertSame(413, self::statusOfParsedBody(['k' => str_repeat('a', 1_000)], $contentType));
    }

    public function testAFieldWithoutATextValueCountsOnlyItsKeyToTheByte(): void
    {
        $contentType = 'multipart/form-data; boundary=x';

        self::assertSame(200, self::statusOfParsedBody([str_repeat('o', 1_000) => new \stdClass()], $contentType));
        self::assertSame(413, self::statusOfParsedBody([str_repeat('o', 1_001) => new \stdClass()], $contentType));
    }

    public function testAnObjectFieldOfAParsedBodyIsNotMeasuredAsText(): void
    {
        $status = self::statusOfParsedBody(['o' => new \stdClass()], 'multipart/form-data; boundary=x');

        self::assertSame(200, $status);
    }

    public function testAUrlEncodedBodyIsMeasuredByItsRawBodyAndNotByItsFields(): void
    {
        $status = self::statusOfParsedBody(
            ['note' => str_repeat('a', 600), 'other' => str_repeat('b', 600)],
            'application/x-www-form-urlencoded',
            str_repeat('x', 600),
        );

        self::assertSame(200, $status);
    }

    /** @return iterable<string, array{?string}> */
    public static function contentTypesPhpParsesOrIgnores(): iterable
    {
        yield 'mixed case with spaces' => [' Multipart/Form-Data ; boundary=x'];
        yield 'comma before the boundary' => ['multipart/form-data,boundary=x'];
        yield 'space before the boundary' => ['multipart/form-data boundary=x'];
        yield 'a second type before the boundary' => ['multipart/form-data, text/plain; boundary=x'];
        yield 'no content type at all' => [null];
    }

    #[DataProvider('contentTypesPhpParsesOrIgnores')]
    public function testTheFieldsOfAnEmptyRawBodyCountWhateverTheContentType(?string $contentType): void
    {
        self::assertSame(413, self::statusOfParsedBody(['note' => str_repeat('a', 2_000)], $contentType));
    }

    public function testTwoUploadsOfTheMaximumIntegerSaturateTheMeasure(): void
    {
        $seen = [];
        $middleware = new MaxBodySizeMiddleware(
            1_000,
            onFailure: static function (Request $request, BodyTooLarge $refusal) use (&$seen): ?Response {
                $seen[] = $refusal->bytes;

                return null;
            },
        );
        $request = Request::fromArray('POST', '/upload', files: [
            'a' => new FileUpload('a.bin', 'application/octet-stream', '/tmp/phpA', PHP_INT_MAX),
            'b' => new FileUpload('b.bin', 'application/octet-stream', '/tmp/phpB', PHP_INT_MAX),
        ]);

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
        self::assertSame([PHP_INT_MAX], $seen);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function contentLengthsThatAreNotPlainDigits(): iterable
    {
        yield 'digits after text' => ['x99999'];
        yield 'text after digits' => ['99999x'];
        yield 'signed' => ['+99999'];
        yield 'decimal' => ['99999.0'];
    }

    #[DataProvider('contentLengthsThatAreNotPlainDigits')]
    public function testAContentLengthThatIsNotPlainDigitsIsNotTakenAsADeclaredSize(string $declared): void
    {
        $middleware = new MaxBodySizeMiddleware(4);
        $request = Request::fromArray('POST', '/upload', headers: ['Content-Length' => $declared], rawBody: 'abc');

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(200, $response->status);
    }
}
