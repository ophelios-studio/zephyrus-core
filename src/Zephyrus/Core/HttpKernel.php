<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Throwable;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Routing\RouteMatch;

/**
 * Turns a Request into a Response: resolves the route, runs the global and
 * route middlewares, and converts any throwable into an error response.
 *
 * Order: RequestEvent (a listener may short-circuit), routing and pipeline,
 * then ResponseEvent (a listener may replace the response). ExceptionEvent
 * fires once per throwable at the point of conversion.
 *
 * Error responses (404, 405, failing handler) pass through the global
 * pipeline and carry its decorations (security headers, CSP, host and HTTPS
 * checks). Three responses do not: a RequestEvent short-circuit, the backstop
 * used when a global middleware itself throws, and a response replaced by a
 * ResponseEvent listener. A header that must reach every response also belongs
 * at the web server or proxy.
 *
 * handle() throws only when an exception handler rethrows the same throwable
 * it was given (passed through, e.g. for a debugger), or when a RequestEvent or
 * ResponseEvent listener throws. A failing route, handler or middleware, or a
 * broken exception handler, yields an error response instead.
 *
 * Route middlewares wrap only the matched route's handler. They never run for a
 * 404 or 405, and they do not post-process the error response of a failing
 * handler.
 *
 * Global middlewares run on 404 and 405 too, so their side effects happen
 * there as well (a session starts, for instance). Their $next() returns the
 * error response instead of throwing, so transaction handling must inspect
 * $response->status and error reporting belongs in ExceptionEvent. A middleware
 * validating a request against a resource must pass through when
 * Request::ATTRIBUTE_UNMATCHED_ROUTE is set (see CsrfMiddleware). Middlewares
 * validating the connection, the envelope or the caller may answer before
 * routing, and middlewares that decorate responses must keep running.
 */
final readonly class HttpKernel
{
    private MiddlewarePipeline $globalPipeline;

    /**
     * @param MiddlewarePipeline|null $globalPipeline Middlewares wrapping routed responses and
     *   404/405/failing-handler error responses. Not RequestEvent short-circuits, the
     *   global-middleware backstop or ResponseEvent replacements (see class docblock).
     */
    public function __construct(
        private RouteDispatcher $dispatcher,
        private HttpExceptionResponder $exceptionResponder,
        private ?EventDispatcher $events = null,
        ?MiddlewarePipeline $globalPipeline = null,
    ) {
        $this->globalPipeline = $globalPipeline ?? new MiddlewarePipeline();
    }

    public function handle(Request $request): Response
    {
        if ($this->events !== null) {
            $requestEvent = new RequestEvent($request);
            $this->events->dispatch($requestEvent);

            if ($requestEvent->hasResponse()) {
                return $this->fireResponseEvent($request, $requestEvent->getResponse());
            }
        }

        try {
            $response = $this->resolveAndPipe($request);
        } catch (KernelRethrowSignal $signal) {
            // Unwrap the marker so the application sees its own throwable.
            // No ResponseEvent: there is no response.
            throw $signal->original;
        }

        return $this->fireResponseEvent($request, $response);
    }

    /**
     * Matches the route, then runs the global pipeline over the matched handler
     * or over the routing error. Matching happens before the pipeline, so global
     * middlewares already see the route parameters.
     */
    private function resolveAndPipe(Request $request): Response
    {
        try {
            $match = $this->dispatcher->match($request);
        } catch (Throwable $routingFailure) {
            // Lets a global middleware validating against a resource decline.
            // See Request::ATTRIBUTE_UNMATCHED_ROUTE.
            $unmatched = $request->withAttribute(Request::ATTRIBUTE_UNMATCHED_ROUTE, true);

            return $this->pipe(
                $unmatched,
                fn (Request $piped): Response => $this->toErrorResponse(
                    $routingFailure,
                    $piped,
                    ExceptionEvent::SOURCE_ROUTING,
                ),
            );
        }

        return $this->pipe(
            $request->withMatchedRoute($match),
            fn (Request $piped): Response => $this->dispatchMatchedRoute($match, $piped),
        );
    }

    /**
     * Runs the global pipeline. If a global middleware throws, the error is
     * converted here without re-entering the pipeline, so the response carries
     * no global decorations.
     *
     * @param callable(Request): Response $destination
     */
    private function pipe(Request $request, callable $destination): Response
    {
        try {
            return $this->globalPipeline->handle($request, $destination);
        } catch (KernelRethrowSignal $signal) {
            // Converting again would call the exception handler twice.
            throw $signal;
        } catch (Throwable $exception) {
            return $this->toErrorResponse($exception, $request, ExceptionEvent::SOURCE_MIDDLEWARE);
        }
    }

    /**
     * Destination of the global pipeline for a matched route. A throwing handler
     * is converted inside the global middlewares, so they still decorate the
     * error response. Route middlewares do not post-process it.
     *
     * The exception handler receives the request as it entered the route
     * pipeline: global middleware attributes are set, route middleware ones are not.
     */
    private function dispatchMatchedRoute(RouteMatch $match, Request $request): Response
    {
        try {
            return $this->dispatcher->dispatchMatch($match, $request);
        } catch (Throwable $exception) {
            return $this->toErrorResponse($exception, $request, ExceptionEvent::SOURCE_HANDLER);
        }
    }

    /**
     * Converts a throwable into an error response. If the exception handler
     * itself throws, a fixed 500 is returned inside the pipeline, so global
     * middlewares still decorate it.
     */
    private function toErrorResponse(Throwable $exception, Request $request, string $source): Response
    {
        $this->fireExceptionEvent($exception, $request, $source);

        try {
            return $this->exceptionResponder->toResponse($exception, $request);
        } catch (Throwable $responderFailure) {
            // The handler rethrew on purpose (e.g. for a debugger): pass it on.
            // Identity, not instanceof: any other throwable is a failing handler.
            if ($responderFailure === $exception) {
                throw new KernelRethrowSignal($exception);
            }

            $this->fireExceptionEvent($responderFailure, $request, ExceptionEvent::SOURCE_RESPONDER);

            return Response::text('Internal Server Error', 500);
        }
    }

    /**
     * Fires ExceptionEvent. This is the only place it is fired, so it fires once
     * per throwable. A listener failure is logged and skipped, so it cannot stop
     * the listeners after it, and nothing thrown here reaches the client.
     */
    private function fireExceptionEvent(Throwable $exception, Request $request, string $source): void
    {
        if ($this->events === null) {
            return;
        }

        try {
            $this->events->dispatch(
                new ExceptionEvent($request, $exception, $source),
                self::reportListenerFailure(...),
            );
        } catch (Throwable $dispatchFailure) {
            // The dispatcher itself failed. Swallowed for the same reason.
        }
    }

    /**
     * Logs one listener failure. It never throws, as it runs on the error path.
     */
    private static function reportListenerFailure(Throwable $listenerFailure): void
    {
        try {
            error_log(sprintf(
                'Zephyrus: an ExceptionEvent listener failed and was skipped: %s: %s',
                $listenerFailure::class,
                $listenerFailure->getMessage(),
            ));
        } catch (Throwable) {
            // Nothing left to report to. Never rethrown.
        }
    }

    private function fireResponseEvent(Request $request, Response $response): Response
    {
        if ($this->events === null) {
            return $response;
        }

        $responseEvent = new ResponseEvent($request, $response);
        $this->events->dispatch($responseEvent);

        return $responseEvent->getResponse();
    }
}
