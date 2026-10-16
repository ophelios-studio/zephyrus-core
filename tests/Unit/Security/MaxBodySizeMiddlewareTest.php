<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\BodyTooLarge;
use Zephyrus\Security\MaxBodySizeMiddleware;

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
}
