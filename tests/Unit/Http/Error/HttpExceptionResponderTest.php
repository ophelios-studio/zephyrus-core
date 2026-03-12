<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Http\Error;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Routing\Exception\RouteParameterException;
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
        self::assertSame('GET, POST', $response->headers['allow']);
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
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
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
        self::assertSame('application/problem+json; charset=utf-8', $response->headers['content-type']);
        self::assertSame('{"type":"about:blank","title":"Not Found","status":404}', $response->body);
    }

    public function testFormatsErrorAsJsonForVendorPlusJsonAcceptHeader(): void
    {
        $responder = new HttpExceptionResponder();

        $request = Request::fromArray(
            method: 'GET',
            uri: '/missing',
            headers: ['Accept' => 'application/vnd.api+json'],
        );

        $response = $responder->toResponse(new RouteNotFoundException('No route matched GET /missing'), $request);

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
        self::assertSame('{"error":{"status":404,"message":"Not Found"}}', $response->body);
    }

    public function testFormatsErrorAsTextWhenJsonQValueIsZero(): void
    {
        $responder = new HttpExceptionResponder();

        $request = Request::fromArray(
            method: 'GET',
            uri: '/missing',
            headers: ['Accept' => 'application/json;q=0'],
        );

        $response = $responder->toResponse(new RouteNotFoundException('No route matched GET /missing'), $request);

        self::assertSame(404, $response->status);
        self::assertSame('Not Found', $response->body);
        self::assertSame('text/plain; charset=utf-8', $response->headers['content-type']);
    }

    public function testFormatsErrorUsingHighestWeightedJsonFamilyMediaType(): void
    {
        $responder = new HttpExceptionResponder();

        $request = Request::fromArray(
            method: 'GET',
            uri: '/missing',
            headers: ['Accept' => 'application/json;q=0.6, application/problem+json;q=0.9'],
        );

        $response = $responder->toResponse(new RouteNotFoundException('No route matched GET /missing'), $request);

        self::assertSame(404, $response->status);
        self::assertSame('application/problem+json; charset=utf-8', $response->headers['content-type']);
        self::assertSame('{"type":"about:blank","title":"Not Found","status":404}', $response->body);
    }

    public function testFormatsErrorAsGenericJsonForWildcardPlusJsonAcceptHeader(): void
    {
        $responder = new HttpExceptionResponder();

        $request = Request::fromArray(
            method: 'GET',
            uri: '/missing',
            headers: ['Accept' => 'application/*+json'],
        );

        $response = $responder->toResponse(new RouteNotFoundException('No route matched GET /missing'), $request);

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
        self::assertSame('{"error":{"status":404,"message":"Not Found"}}', $response->body);
    }

    public function testFormatsErrorAsGenericJsonWhenWildcardAndJsonAreTied(): void
    {
        $responder = new HttpExceptionResponder();

        $request = Request::fromArray(
            method: 'GET',
            uri: '/missing',
            headers: ['Accept' => 'application/*+json;q=0.9, application/json;q=0.9'],
        );

        $response = $responder->toResponse(new RouteNotFoundException('No route matched GET /missing'), $request);

        self::assertSame(404, $response->status);
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);
        self::assertSame('{"error":{"status":404,"message":"Not Found"}}', $response->body);
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
        self::assertSame('application/json; charset=utf-8', $response->headers['content-type']);

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
        self::assertSame('application/problem+json; charset=utf-8', $response->headers['content-type']);

        $decoded = json_decode($response->body, true);
        self::assertSame('about:blank', $decoded['type']);
        self::assertSame('Unprocessable Entity', $decoded['title']);
        self::assertSame(422, $decoded['status']);
        self::assertSame(['Must be an integer.'], $decoded['errors']['age']);
    }

    // -----------------------------------------------------------------
    // Custom exception handler registry
    // -----------------------------------------------------------------

    public function testCustomHandlerOverridesBuiltInMapping(): void
    {
        $responder = new HttpExceptionResponder();
        $responder->registerHandler(
            RouteNotFoundException::class,
            fn (\Throwable $e, ?Request $r) => Response::text('Custom 404', 404),
        );

        $response = $responder->toResponse(new RouteNotFoundException('No route'));

        self::assertSame(404, $response->status);
        self::assertSame('Custom 404', $response->body);
    }

    public function testCustomHandlerForGenericException(): void
    {
        $responder = new HttpExceptionResponder();
        $responder->registerHandler(
            RuntimeException::class,
            fn (\Throwable $e, ?Request $r) => Response::text('Custom: ' . $e->getMessage(), 503),
        );

        $response = $responder->toResponse(new RuntimeException('service down'));

        self::assertSame(503, $response->status);
        self::assertSame('Custom: service down', $response->body);
    }

    public function testMostSpecificCustomHandlerWins(): void
    {
        $responder = new HttpExceptionResponder();
        $responder->registerHandler(
            \Throwable::class,
            fn (\Throwable $e, ?Request $r) => Response::text('generic', 500),
        );
        $responder->registerHandler(
            RuntimeException::class,
            fn (\Throwable $e, ?Request $r) => Response::text('specific', 503),
        );

        $response = $responder->toResponse(new RuntimeException('boom'));

        self::assertSame(503, $response->status);
        self::assertSame('specific', $response->body);
    }

    public function testCustomHandlerForParentClassMatchesChildException(): void
    {
        $responder = new HttpExceptionResponder();
        $responder->registerHandler(
            RuntimeException::class,
            fn (\Throwable $e, ?Request $r) => Response::text('caught runtime', 500),
        );

        // LogicException extends \Exception, not RuntimeException → should NOT match
        $response = $responder->toResponse(new \LogicException('not a runtime'));
        self::assertSame(500, $response->status);
        self::assertSame('Internal Server Error', $response->body); // built-in fallback

        // \InvalidArgumentException extends \LogicException → also NOT runtime
        // But a custom subclass of RuntimeException should match
        $response2 = $responder->toResponse(new \UnexpectedValueException('is runtime'));
        self::assertSame(500, $response2->status);
        self::assertSame('caught runtime', $response2->body);
    }

    public function testNoCustomHandlerFallsToBuiltIn(): void
    {
        $responder = new HttpExceptionResponder();
        // Register handler for a class that won't match
        $responder->registerHandler(
            \InvalidArgumentException::class,
            fn (\Throwable $e, ?Request $r) => Response::text('never called', 999),
        );

        $response = $responder->toResponse(new RuntimeException('unhandled'));

        self::assertSame(500, $response->status);
        self::assertSame('Internal Server Error', $response->body);
    }

    public function testCustomHandlerReceivesRequest(): void
    {
        $responder = new HttpExceptionResponder();
        $responder->registerHandler(
            RuntimeException::class,
            function (\Throwable $e, ?Request $r): Response {
                $path = $r?->uri()->full() ?? 'unknown';
                return Response::text("Error on $path", 500);
            },
        );

        $request = Request::fromArray('GET', '/api/test');
        $response = $responder->toResponse(new RuntimeException('boom'), $request);

        self::assertSame(500, $response->status);
        self::assertSame('Error on /api/test', $response->body);
    }

    public function testRegisterHandlerReturnsSelf(): void
    {
        $responder = new HttpExceptionResponder();
        $result = $responder->registerHandler(
            RuntimeException::class,
            fn (\Throwable $e, ?Request $r) => Response::text('ok', 200),
        );

        self::assertSame($responder, $result);
    }

    public function testRouteParameterExceptionYieldsNotFound(): void
    {
        $exception = new RouteParameterException('Ctrl', 'show', 'id', 'int', 'abc');
        $response = (new HttpExceptionResponder())->toResponse($exception);
        self::assertSame(404, $response->status);
    }
}
