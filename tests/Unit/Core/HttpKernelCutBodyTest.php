<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\ExceptionEvent;
use Zephyrus\Core\HttpKernel;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\RequestBody;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteCollection;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Security\BodyTooLarge;
use Zephyrus\Security\MaxBodySizeMiddleware;
use Zephyrus\Tests\Support\CapturedErrorLog;

final class HttpKernelCutBodyTest extends TestCase
{
    use CapturedErrorLog;

    private const string READ_BELOW_CONFIGURATION = 'Request::fromGlobals() read the body with a limit of 4 bytes, '
        . 'but security.maxBodySize allows 5: pass maxBodySize: $config->security->maxBodySize to Request::fromGlobals().';

    private int $handlerCalls = 0;

    protected function setUp(): void
    {
        $this->captureErrorLog();
    }

    protected function tearDown(): void
    {
        $this->releaseErrorLog();
    }

    public function testACutBodyIsRefusedBeforeAnyGlobalMiddlewareWhenNoBodyLimitIsMounted(): void
    {
        $globalMiddleware = new class implements MiddlewareInterface {
            public int $calls = 0;

            public function process(Request $request, callable $next): Response
            {
                ++$this->calls;

                /** @var Response */
                return $next($request);
            }
        };

        $response = $this->kernel([$globalMiddleware])->handle(self::formCutAtFourBytes());

        self::assertSame([413, 0, 0], [$response->status, $globalMiddleware->calls, $this->handlerCalls]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptProvider(): array
    {
        return [
            'a browser'     => ['text/html,application/xhtml+xml'],
            'an API client' => ['application/json'],
        ];
    }

    #[DataProvider('acceptProvider')]
    public function testTheKernelRefusesACutBodyAsTheDefaultMiddlewareWould(string $accept): void
    {
        $request = self::formCutAtFourBytes(['HTTP_ACCEPT' => $accept]);
        $expected = (new MaxBodySizeMiddleware(0))->process($request, static fn (): Response => Response::text('HANDLED'));

        $response = $this->kernel()->handle($request);

        self::assertSame(
            [$expected->status, $expected->headers['content-type'], $expected->body],
            [$response->status, $response->headers['content-type'], $response->body],
        );
    }

    public function testAMountedBodyLimitAnswersACutBodyWithItsOnFailure(): void
    {
        $bodyLimit = new MaxBodySizeMiddleware(
            1_000,
            onFailure: static fn (Request $request, BodyTooLarge $refusal): Response => Response::text('Too large', 422),
        );

        $response = $this->kernel([$bodyLimit])->handle(self::formCutAtFourBytes());

        self::assertSame([422, 0], [$response->status, $this->handlerCalls]);
    }

    public function testABodyReadWholeReachesTheHandler(): void
    {
        $request = Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/submit', 'CONTENT_TYPE' => 'application/json'],
            get: [],
            post: [],
            cookie: [],
            files: [],
            rawBody: '{"a":1}',
            maxBodySize: 7,
        );

        $response = $this->kernel(configuredMaxBodySize: 1_000)->handle($request);

        self::assertSame([200, 1], [$response->status, $this->handlerCalls]);
    }

    public function testABodyCutBelowTheConfiguredLimitIsRethrownWithItsFix(): void
    {
        try {
            $this->kernel(configuredMaxBodySize: 5, rethrowUnhandled: true)->handle(self::formCutAtFourBytes());
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(self::READ_BELOW_CONFIGURATION, $e->getMessage());
        }

        self::assertSame(0, $this->handlerCalls);
    }

    public function testABodyCutBelowAnUnlimitedConfigurationIsRethrownWithItsFix(): void
    {
        try {
            $this->kernel(configuredMaxBodySize: 0, rethrowUnhandled: true)->handle(self::formCutAtFourBytes());
            self::fail('expected a refusal');
        } catch (ConfigurationException $e) {
            self::assertSame(
                'Request::fromGlobals() read the body with a limit of 4 bytes, but security.maxBodySize is 0 (unlimited): '
                    . 'set security.maxBodySize to the largest body the application accepts and pass maxBodySize: '
                    . '$config->security->maxBodySize to Request::fromGlobals(); a limit of 0 reads any body whole into memory.',
                $e->getMessage(),
            );
        }
    }

    public function testABodyCutBelowTheConfiguredLimitIsLoggedOnOneLineAndRefusedWith413(): void
    {
        $response = $this->kernel(configuredMaxBodySize: 5)->handle(self::formCutAtFourBytes());

        self::assertSame(413, $response->status);
        self::assertSame(['Zephyrus: ' . self::READ_BELOW_CONFIGURATION], $this->capturedErrorLogLines());
    }

    public function testABodyCutBelowTheConfiguredLimitIsReportedToExceptionListeners(): void
    {
        $reported = [];
        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (ExceptionEvent $e) use (&$reported): void {
            $reported[] = [$e->getSource(), $e->getException()::class];
        });

        $this->kernel(configuredMaxBodySize: 5, events: $events)->handle(self::formCutAtFourBytes());

        self::assertSame([[ExceptionEvent::SOURCE_REQUEST, ConfigurationException::class]], $reported);
    }

    public function testABodyCutAtTheConfiguredLimitIsRefusedWithoutAReport(): void
    {
        $response = $this->kernel(configuredMaxBodySize: 4, rethrowUnhandled: true)->handle(self::formCutAtFourBytes());

        self::assertSame([413, ''], [$response->status, $this->capturedErrorLog()]);
    }

    public function testATruncatedBodyBuiltWithoutAReadLimitIsRefusedWithoutAReport(): void
    {
        $request = new Request('POST', '/submit', new RequestBody([], '', truncated: true));

        $response = $this->kernel(configuredMaxBodySize: 5, rethrowUnhandled: true)->handle($request);

        self::assertSame([413, 0], [$response->status, $this->handlerCalls]);
    }

    /**
     * @param list<MiddlewareInterface> $globalMiddlewares
     */
    private function kernel(
        array $globalMiddlewares = [],
        ?int $configuredMaxBodySize = null,
        bool $rethrowUnhandled = false,
        ?EventDispatcher $events = null,
    ): HttpKernel {
        $routes = new RouteCollection();
        $routes->add(Route::define('POST', '/submit', 'FormController@submit'));

        $dispatcher = new RouteDispatcher(
            routes: $routes,
            pipeline: new MiddlewarePipeline(),
            resolver: function (): Response {
                ++$this->handlerCalls;

                return Response::text('saved');
            },
        );

        return new HttpKernel(
            $dispatcher,
            new HttpExceptionResponder(),
            $events,
            new MiddlewarePipeline($globalMiddlewares),
            $rethrowUnhandled,
            $configuredMaxBodySize,
        );
    }

    /**
     * @param array<string, string> $server
     */
    private static function formCutAtFourBytes(array $server = []): Request
    {
        return Request::fromGlobals(
            server: ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/submit', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'] + $server,
            get: [],
            post: ['_csrf_token' => 'token', 'name' => 'Ada'],
            cookie: [],
            files: [],
            rawBody: '_csrf_token=token&name=Ada',
            maxBodySize: 4,
        );
    }
}
