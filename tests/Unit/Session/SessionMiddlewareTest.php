<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Session\SessionManager;
use Zephyrus\Session\SessionMiddleware;

final class SessionMiddlewareTest extends TestCase
{
    public function testImplementsMiddlewareInterface(): void
    {
        $middleware = new SessionMiddleware(SessionConfig::fromArray([]));
        self::assertInstanceOf(MiddlewareInterface::class, $middleware);
    }

    public function testInjectsSessionManagerIntoRequest(): void
    {
        $session = new SessionManager([]);
        $config = SessionConfig::fromArray([]);
        $middleware = new SessionMiddleware($config, $session);

        $request = new Request(method: 'GET', uri: '/');
        $capturedSession = null;

        $middleware->process($request, function (Request $req) use (&$capturedSession): Response {
            $capturedSession = $req->attribute('session');
            return Response::text('ok');
        });

        self::assertInstanceOf(SessionManager::class, $capturedSession);
        self::assertSame($session, $capturedSession);
    }

    public function testSessionIsStarted(): void
    {
        $session = new SessionManager([]);
        $config = SessionConfig::fromArray([]);
        $middleware = new SessionMiddleware($config, $session);

        $request = new Request(method: 'GET', uri: '/');

        $middleware->process($request, function (Request $req): Response {
            $session = $req->attribute('session');
            self::assertTrue($session->isStarted());
            return Response::text('ok');
        });
    }

    public function testNextHandlerResponseIsReturned(): void
    {
        $session = new SessionManager([]);
        $config = SessionConfig::fromArray([]);
        $middleware = new SessionMiddleware($config, $session);

        $request = new Request(method: 'GET', uri: '/');
        $expected = Response::json(['status' => 'ok'], 201);

        $response = $middleware->process($request, function () use ($expected): Response {
            return $expected;
        });

        self::assertSame(201, $response->status);
    }

    public function testGetSessionManagerReturnsInjectedInstance(): void
    {
        $session = new SessionManager([]);
        $middleware = new SessionMiddleware(SessionConfig::fromArray([]), $session);

        self::assertSame($session, $middleware->getSessionManager());
    }

    public function testDefaultSessionManagerIsCreated(): void
    {
        $middleware = new SessionMiddleware(SessionConfig::fromArray([]));

        self::assertInstanceOf(SessionManager::class, $middleware->getSessionManager());
    }

    public function testSessionDataIsAccessibleDownstream(): void
    {
        $session = new SessionManager(['user_id' => 42]);
        $config = SessionConfig::fromArray([]);
        $middleware = new SessionMiddleware($config, $session);

        $request = new Request(method: 'GET', uri: '/');

        $middleware->process($request, function (Request $req): Response {
            $session = $req->attribute('session');
            self::assertSame(42, $session->get('user_id'));
            return Response::text('ok');
        });
    }
}
