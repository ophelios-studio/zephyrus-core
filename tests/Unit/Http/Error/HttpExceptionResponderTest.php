<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http\Error;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\Request;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Validation\ErrorBag;
use Zephyrus\Validation\ValidationException;

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

    public function testFormatsErrorAsJsonWhenRequestAcceptsJson(): void
    {
        $responder = new HttpExceptionResponder();

        $request = Request::fromArray(
            method: 'GET',
            uri: '/missing',
            headers: ['Accept' => 'application/json'],
        );

        $response = $responder->toResponse(new RouteNotFoundException('No route matched GET /missing'), $request);

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('{"error":{"status":404,"message":"Not Found"}}', $response->body);
    }

    public function testFormatsErrorAsProblemJsonWhenRequestAcceptsProblemJson(): void
    {
        $responder = new HttpExceptionResponder();

        $request = Request::fromArray(
            method: 'GET',
            uri: '/missing',
            headers: ['Accept' => 'application/problem+json'],
        );

        $response = $responder->toResponse(new RouteNotFoundException('No route matched GET /missing'), $request);

        self::assertSame(404, $response->status);
        self::assertSame('application/problem+json; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('{"type":"about:blank","title":"Not Found","status":404}', $response->body);
    }

    // ---- ValidationException → 422 -----------------------------------------

    public function testMapsValidationExceptionTo422PlainText(): void
    {
        $responder = new HttpExceptionResponder();

        $bag = new ErrorBag();
        $bag->add('email', 'Must be a valid email address.');

        $response = $responder->toResponse(ValidationException::fromErrorBag($bag));

        self::assertSame(422, $response->status);
        self::assertSame('Unprocessable Entity', $response->body);
    }

    public function testMapsValidationExceptionTo422JsonWithErrors(): void
    {
        $responder = new HttpExceptionResponder();

        $bag = new ErrorBag();
        $bag->add('email', 'Must be a valid email address.');
        $bag->add('name', 'This field is required.');

        $request = Request::fromArray(
            method: 'POST',
            uri: '/users',
            headers: ['Accept' => 'application/json'],
        );

        $response = $responder->toResponse(ValidationException::fromErrorBag($bag), $request);

        self::assertSame(422, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        self::assertArrayHasKey('errors', $decoded);
        self::assertSame(['Must be a valid email address.'], $decoded['errors']['email']);
        self::assertSame(['This field is required.'], $decoded['errors']['name']);
    }

    public function testMapsValidationExceptionTo422ProblemJson(): void
    {
        $responder = new HttpExceptionResponder();

        $bag = new ErrorBag();
        $bag->add('age', 'Must be an integer.');

        $request = Request::fromArray(
            method: 'POST',
            uri: '/users',
            headers: ['Accept' => 'application/problem+json'],
        );

        $response = $responder->toResponse(ValidationException::fromErrorBag($bag), $request);

        self::assertSame(422, $response->status);
        self::assertSame('application/problem+json; charset=utf-8', $response->headers['Content-Type']);

        $decoded = json_decode($response->body, true);
        self::assertSame('about:blank', $decoded['type']);
        self::assertSame('Unprocessable Entity', $decoded['title']);
        self::assertSame(422, $decoded['status']);
        self::assertSame(['Must be an integer.'], $decoded['errors']['age']);
    }
}
