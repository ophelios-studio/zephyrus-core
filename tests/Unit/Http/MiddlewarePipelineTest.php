<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

final class MiddlewarePipelineTest extends TestCase
{
    public function testHandleRunsMiddlewaresInOrderBeforeDestination(): void
    {
        $pipeline = new MiddlewarePipeline([
            new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    $response = $next($request);

                    return $response->withHeader('X-First', 'yes');
                }
            },
            new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request)->withHeader('X-Second', 'yes');
                }
            },
        ]);

        $response = $pipeline->handle(
            Request::fromArray('GET', '/health'),
            static fn (Request $request): Response => Response::text('ok', 200),
        );

        self::assertSame('ok', $response->body);
        self::assertSame('yes', $response->headers['x-first']);
        self::assertSame('yes', $response->headers['x-second']);
    }

    public function testPipeReturnsNewPipeline(): void
    {
        $original = new MiddlewarePipeline();

        $middleware = new class implements MiddlewareInterface {
            public function process(Request $request, callable $next): Response
            {
                return $next($request);
            }
        };

        $extended = $original->pipe($middleware);

        $responseOriginal = $original->handle(
            Request::fromArray('GET', '/health'),
            static fn (Request $request): Response => Response::text('ok'),
        );

        $responseExtended = $extended->handle(
            Request::fromArray('GET', '/health'),
            static fn (Request $request): Response => Response::text('ok')->withHeader('X-Destination', 'yes'),
        );

        self::assertNotSame($original, $extended);
        self::assertArrayNotHasKey('x-destination', $responseOriginal->headers);
        self::assertSame('yes', $responseExtended->headers['x-destination']);
    }

    public function testPipeManyAppendsMiddlewaresInOrder(): void
    {
        $pipeline = new MiddlewarePipeline();

        $extended = $pipeline->pipeMany([
            new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request)->withHeader('X-One', '1');
                }
            },
            new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return $next($request)->withHeader('X-Two', '2');
                }
            },
        ]);

        $response = $extended->handle(
            Request::fromArray('GET', '/health'),
            static fn (Request $request): Response => Response::text('ok'),
        );

        self::assertSame('1', $response->headers['x-one']);
        self::assertSame('2', $response->headers['x-two']);
    }
}
