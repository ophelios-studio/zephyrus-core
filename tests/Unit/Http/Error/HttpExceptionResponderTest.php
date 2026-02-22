<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http\Error;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;

final class HttpExceptionResponderTest extends TestCase
{
    public function testMapsRouteNotFoundTo404(): void
    {
        $responder = new HttpExceptionResponder();

        $response = $responder->toResponse(new RouteNotFoundException('No route matched GET /x'));

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
    }

    public function testMapsMethodNotAllowedTo405WithAllowHeader(): void
    {
        $responder = new HttpExceptionResponder();

        $response = $responder->toResponse(new MethodNotAllowedException(['GET', 'POST'], '/users'));

        self::assertSame(405, $response->status);
        self::assertSame('Method Not Allowed', $response->body);
        self::assertSame('GET, POST', $response->headers['Allow']);
    }

    public function testMapsUnknownExceptionsTo500(): void
    {
        $responder = new HttpExceptionResponder();

        $response = $responder->toResponse(new RuntimeException('boom'));

        self::assertSame(500, $response->status);
        self::assertSame('Internal Server Error', $response->body);
    }
}
