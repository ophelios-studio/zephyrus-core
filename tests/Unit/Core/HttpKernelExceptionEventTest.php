<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Zephyrus\Core\ExceptionEvent;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Core\ResponseEvent;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;

/**
 * ExceptionEvent is observation only: reporters see the error that the pipeline
 * converts into a 500, and cannot change the response.
 */
final class HttpKernelExceptionEventTest extends TestCase
{
    private string $errorLogFile = '';

    private string $previousErrorLog = '';

    /** Sends listener failures to a temp file so the suite's stderr stays readable. */
    protected function setUp(): void
    {
        $this->errorLogFile = (string) tempnam(sys_get_temp_dir(), 'zephyrus-kernel-events-');
        $previous = ini_get('error_log');
        $this->previousErrorLog = $previous === false ? '' : $previous;
        ini_set('error_log', $this->errorLogFile);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        @unlink($this->errorLogFile);
    }

    // -- Opting into nothing must change nothing ----------------------------

    /**
     * The response is identical with no dispatcher, with unrelated listeners and
     * with an ExceptionEvent listener, on every error path.
     */
    public function testResponsesAreIdenticalWhetherOrNotAListenerIsRegistered(): void
    {
        $paths = [
            ['GET', '/missing'],      // routing failure, 404
            ['POST', '/only-get'],    // routing failure, 405
            ['GET', '/boom'],         // handler failure, 500
        ];

        $withoutDispatcher = $this->buildKernel(null);

        $unrelatedEvents = new EventDispatcher();
        $unrelatedEvents->addListener(ResponseEvent::class, static function (ResponseEvent $e): void {
            // Registered, but not for ExceptionEvent.
        });
        $withUnrelatedListener = $this->buildKernel($unrelatedEvents);

        $reportingEvents = new EventDispatcher();
        $reportingEvents->addListener(ExceptionEvent::class, static function (ExceptionEvent $e): void {
            // A well-behaved reporter: observes, changes nothing.
        });
        $withReporter = $this->buildKernel($reportingEvents);

        foreach ($paths as [$method, $path]) {
            $a = $withoutDispatcher->handle(Request::fromArray($method, $path));
            $b = $withUnrelatedListener->handle(Request::fromArray($method, $path));
            $c = $withReporter->handle(Request::fromArray($method, $path));

            $label = $method . ' ' . $path;

            self::assertSame($a->status, $b->status, $label);
            self::assertSame($a->status, $c->status, $label);
            self::assertSame($a->body, $b->body, $label);
            self::assertSame($a->body, $c->body, $label);
            self::assertSame($a->headers, $b->headers, $label);
            self::assertSame($a->headers, $c->headers, $label);
        }
    }

    public function testSuccessfulRequestNeverFiresTheEvent(): void
    {
        $seen = [];
        $kernel = $this->buildKernel($this->recordingDispatcher($seen));

        $response = $kernel->handle(Request::fromArray('GET', '/only-get'));

        self::assertSame(200, $response->status);
        self::assertSame([], $seen);
    }

    public function testKernelWithoutEventDispatcherStillHandlesErrors(): void
    {
        // A null dispatcher fires nothing, as with RequestEvent and ResponseEvent.
        $kernel = $this->buildKernel(null);

        self::assertSame(404, $kernel->handle(Request::fromArray('GET', '/missing'))->status);
        self::assertSame(500, $kernel->handle(Request::fromArray('GET', '/boom'))->status);
    }

    // -- What a listener receives --------------------------------------------

    public function testListenerReceivesTheThrowableAndTheRequest(): void
    {
        $seen = [];
        $kernel = $this->buildKernel($this->recordingDispatcher($seen));

        $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertCount(1, $seen);
        self::assertInstanceOf(RuntimeException::class, $seen[0]['exception']);
        self::assertSame('handler exploded', $seen[0]['exception']->getMessage());
        self::assertSame('/boom', $seen[0]['path']);
        self::assertSame('GET', $seen[0]['method']);
    }

    public function testListenerCanTellARoutingFailureFromAHandlerFailure(): void
    {
        $seen = [];
        $kernel = $this->buildKernel($this->recordingDispatcher($seen));

        $kernel->handle(Request::fromArray('GET', '/missing'));
        $kernel->handle(Request::fromArray('POST', '/only-get'));
        $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(ExceptionEvent::SOURCE_ROUTING, $seen[0]['source']);
        self::assertTrue($seen[0]['isRouting'], '404 is a routing failure');

        self::assertSame(ExceptionEvent::SOURCE_ROUTING, $seen[1]['source']);
        self::assertTrue($seen[1]['isRouting'], '405 is a routing failure');

        self::assertSame(ExceptionEvent::SOURCE_HANDLER, $seen[2]['source']);
        self::assertFalse($seen[2]['isRouting'], 'a handler blowing up is not');
    }

    public function testGlobalMiddlewareFailureIsReportedWithTheMiddlewareSource(): void
    {
        $seen = [];

        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get('/only-get', ExceptionEventController::class . '@ping'))
            ->withMiddleware(new ExceptionEventThrowingMiddleware())
            ->withEventDispatcher($this->recordingDispatcher($seen))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/only-get'));

        self::assertSame(500, $response->status);
        self::assertCount(1, $seen);
        self::assertSame(ExceptionEvent::SOURCE_MIDDLEWARE, $seen[0]['source']);
        self::assertSame('global middleware exploded', $seen[0]['exception']->getMessage());
    }

    public function testBrokenExceptionResponderIsReportedAsASecondDistinctThrowable(): void
    {
        $seen = [];

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withExceptionHandler(
                \Zephyrus\Routing\Exception\RouteNotFoundException::class,
                static fn (): Response => throw new RuntimeException('the 404 template is missing'),
            )
            ->withEventDispatcher($this->recordingDispatcher($seen))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(500, $response->status);
        // Two throwables, two events: the routing failure, then the responder's own
        // failure, so a broken error template is not swallowed.
        self::assertCount(2, $seen);
        self::assertSame(ExceptionEvent::SOURCE_ROUTING, $seen[0]['source']);
        self::assertSame(ExceptionEvent::SOURCE_RESPONDER, $seen[1]['source']);
        self::assertSame('the 404 template is missing', $seen[1]['exception']->getMessage());
    }

    // -- Exactly once, and no looping ----------------------------------------

    public function testEventFiresExactlyOncePerThrowable(): void
    {
        $seen = [];
        $kernel = $this->buildKernel($this->recordingDispatcher($seen));

        $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertCount(1, $seen, 'one throwable must produce exactly one event');
    }

    public function testEventFiresOnceOnTheBackstopPathAndDoesNotLoop(): void
    {
        $seen = [];
        $middleware = new ExceptionEventThrowingMiddleware();

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware($middleware)
            ->withEventDispatcher($this->recordingDispatcher($seen))
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(500, $response->status);
        // A backstop that re-entered the pipeline would make both counts climb.
        self::assertSame(1, $middleware->calls);
        self::assertCount(1, $seen);
    }

    // -- A broken listener must not take down the response -------------------

    public function testListenerThatThrowsDoesNotTakeDownTheResponse(): void
    {
        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (ExceptionEvent $e): void {
            throw new RuntimeException('the reporter is broken');
        });

        $kernel = KernelBuilder::create()
            ->withRouter((new Router())->get('/boom', ExceptionEventController::class . '@boom'))
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withEventDispatcher($events)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        // A broken reporter does not touch the response headers.
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
        self::assertSame('nosniff', $response->headers['x-content-type-options']);
    }

    public function testListenerThatThrowsOnARoutingFailureStillYieldsThe404(): void
    {
        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (ExceptionEvent $e): void {
            throw new RuntimeException('the reporter is broken');
        });

        $kernel = KernelBuilder::create()
            ->withRouter(new Router())
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->withEventDispatcher($events)
            ->build();

        $response = $kernel->handle(Request::fromArray('GET', '/missing'));

        self::assertSame(404, $response->status);
        self::assertSame('SAMEORIGIN', $response->headers['x-frame-options']);
    }

    /**
     * A throwing reporter must not suppress the reporters registered after it.
     *
     * Nothing a listener throws changes the response, and the next request is unaffected.
     */
    public function testAThrowingListenerNoLongerCancelsTheRemainingListeners(): void
    {
        $reached = 0;

        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (ExceptionEvent $e): void {
            throw new RuntimeException('broken');
        }, priority: 10);
        $events->addListener(ExceptionEvent::class, static function (ExceptionEvent $e) use (&$reached): void {
            $reached++;
        }, priority: 0);

        $kernel = $this->buildKernel($events);

        $kernel->handle(Request::fromArray('GET', '/boom'));
        $kernel->handle(Request::fromArray('GET', '/boom'));

        // The low-priority reporter runs on each request.
        self::assertSame(2, $reached);

        // Isolation holds on subsequent requests too.
        self::assertSame(500, $kernel->handle(Request::fromArray('GET', '/boom'))->status);
        self::assertSame(3, $reached);
    }

    // -- The event cannot influence the response -----------------------------

    public function testListenerHasNoWayToReplaceTheResponse(): void
    {
        // Replacement belongs to withExceptionHandler() and ResponseEvent; a
        // setResponse() here would first need a precedence decision.
        self::assertFalse(
            method_exists(ExceptionEvent::class, 'setResponse'),
            'ExceptionEvent must stay observation-only',
        );
        self::assertFalse(method_exists(ExceptionEvent::class, 'getResponse'));
    }

    public function testResponseEventRemainsTheSeamForChangingAnErrorResponse(): void
    {
        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (ExceptionEvent $e): void {
            // observes only
        });
        $events->addListener(ResponseEvent::class, static function (ResponseEvent $e): void {
            $e->setResponse($e->getResponse()->withHeader('X-Reported', 'yes'));
        });

        $kernel = $this->buildKernel($events);

        $response = $kernel->handle(Request::fromArray('GET', '/boom'));

        self::assertSame(500, $response->status);
        self::assertSame('yes', $response->headers['x-reported']);
    }

    // -- Helpers --------------------------------------------------------------

    private function buildKernel(?EventDispatcher $events): \Zephyrus\Core\HttpKernel
    {
        $router = (new Router())
            ->get('/only-get', ExceptionEventController::class . '@ping')
            ->get('/boom', ExceptionEventController::class . '@boom');

        $builder = KernelBuilder::create()->withRouter($router);

        if ($events !== null) {
            $builder = $builder->withEventDispatcher($events);
        }

        return $builder->build();
    }

    /**
     * @param array<int, array{exception: Throwable, source: string, path: string, method: string, isRouting: bool}> $seen
     */
    private function recordingDispatcher(array &$seen): EventDispatcher
    {
        $events = new EventDispatcher();
        $events->addListener(ExceptionEvent::class, static function (ExceptionEvent $e) use (&$seen): void {
            $seen[] = [
                'exception' => $e->getException(),
                'source'    => $e->getSource(),
                'path'      => $e->getRequest()->uri()->path(),
                'method'    => $e->getRequest()->method,
                'isRouting' => $e->isRoutingFailure(),
            ];
        });

        return $events;
    }
}

// ===========================================================================
// Fixtures
// ===========================================================================

final class ExceptionEventController
{
    public function ping(): Response
    {
        return Response::text('pong');
    }

    public function boom(): Response
    {
        throw new RuntimeException('handler exploded');
    }
}

final class ExceptionEventThrowingMiddleware implements MiddlewareInterface
{
    public int $calls = 0;

    public function process(Request $request, callable $next): Response
    {
        $this->calls++;

        throw new RuntimeException('global middleware exploded');
    }
}
