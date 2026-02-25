<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Security\AuthGuardInterface;
use Zephyrus\Security\AuthGuardMiddleware;

final class AuthGuardMiddlewareTest extends TestCase
{
    public function testAllowsRequestWhenGuardAuthorizes(): void
    {
        $middleware = new AuthGuardMiddleware($this->guard(true));

        $response = $middleware->process(
            Request::fromArray('GET', '/admin'),
            static fn (Request $request): Response => Response::text('ok'),
        );

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
    }

    public function testRejectsRequestWhenGuardDenies(): void
    {
        $middleware = new AuthGuardMiddleware($this->guard(false));
        $called = false;

        $response = $middleware->process(
            Request::fromArray('GET', '/admin'),
            static function (Request $request) use (&$called): Response {
                $called = true;

                return Response::text('ok');
            },
        );

        self::assertFalse($called);
        self::assertSame(401, $response->status);
        self::assertStringContainsString('application/json', $response->headers['Content-Type'] ?? '');
        self::assertStringContainsString('Unauthorized', $response->body);
    }

    public function testSupportsCustomErrorStatusAndMessage(): void
    {
        $middleware = new AuthGuardMiddleware(
            guard: $this->guard(false),
            status: 403,
            message: 'Forbidden',
        );

        $response = $middleware->process(
            Request::fromArray('POST', '/admin/action'),
            static fn (Request $request): Response => Response::text('ok'),
        );

        self::assertSame(403, $response->status);
        self::assertStringContainsString('Forbidden', $response->body);
    }

    private function guard(bool $allowed): AuthGuardInterface
    {
        return new class($allowed) implements AuthGuardInterface {
            public function __construct(private readonly bool $allowed)
            {
            }

            public function isAuthorized(Request $request): bool
            {
                return $this->allowed;
            }
        };
    }
}
