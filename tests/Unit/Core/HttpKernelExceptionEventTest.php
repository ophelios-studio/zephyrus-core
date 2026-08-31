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
 * ExceptionEvent is the reporting seam that replaces the catch block a global
 * middleware used to be able to rely on.
 *
 * Since error conversion moved inside the global pipeline, $next() RETURNS a
 * 500 instead of letting the throwable propagate, so a "catch, report, rethrow"
 * middleware would go quiet with nothing to show it had. These tests pin the
 * seam, and pin that adding it changed nothing for anyone not using it.
 */
final class HttpKernelExceptionEventTest extends TestCase
{
    private string $errorLogFile = '';

    private string $previousErrorLog = '';

    /**
     * Several cases below register a reporter that throws on purpose, and the
     * kernel logs every listener failure. Route that to a file so the suite's
     * stderr stays readable; HttpKernelListenerFailureLoggingTest is what
     * asserts the content.
     */
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

    // -- David's condition: opting into nothing must change nothing ----------

    /**
     * The non-breakage proof. Builds the same kernel three ways (no dispatcher
     * at all, a dispatcher with unrelated listeners, and a dispatcher with an
     * ExceptionEvent listener) and asserts the RESPONSE is identical across all
     * of them on every error path.
     *
     * If firing the event ever influenced the response, this fails.
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
        // Mirrors how RequestEvent / ResponseEvent treat a null dispatcher:
        // the kernel simply does not fire, and nothing else changes.
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
        // Two DIFFERENT throwables, so two events: the original routing failure,
        // then the responder's own failure. A broken error template is visible
        // rather than swallowed.
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
        // The throwing middleware runs once and the backstop reports once. If
        // the backstop re-entered the pipeline both counts would climb.
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
        // And the response is still fully decorated: a broken reporter costs
        // the response nothing at all.
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
     * Reporters registered together are INDEPENDENT.
     *
     * This test used to pin the opposite, and said so: EventDispatcher had no
     * per-listener isolation, the kernel's guard caught the first throw, and
     * the remaining listeners for that event never ran. Pinning it was honest
     * about the code, but the behaviour it described was a security defect
     * rather than a design choice. fireExceptionEvent() is the seam whose whole
     * purpose is to make failures visible, so one broken reporter silently
     * suppressing an audit reporter behind it is the worst possible place for
     * that to happen. EventDispatcher::dispatch() now takes a per-listener
     * error handler and the kernel passes one, so the assertion is inverted.
     *
     * What did NOT change, and is asserted alongside: nothing a listener throws
     * can influence the response, and the next request is unaffected.
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

        // Once per request, both times: the failing high-priority reporter no
        // longer cancels the low-priority one.
        self::assertSame(2, $reached);

        // Still guaranteed: the kernel keeps producing correct responses, and
        // the isolation holds on every subsequent request too.
        self::assertSame(500, $kernel->handle(Request::fromArray('GET', '/boom'))->status);
        self::assertSame(3, $reached);
    }

    // -- The event cannot influence the response -----------------------------

    public function testListenerHasNoWayToReplaceTheResponse(): void
    {
        // Observation only, by design: replacement already exists via
        // withExceptionHandler() and ResponseEvent. Pinned so nobody adds a
        // setResponse() to this event without deciding precedence first.
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
