<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Throwable;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\BodySize;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\MiddlewarePipeline;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\RouteDispatcher;
use Zephyrus\Routing\RouteMatch;
use Zephyrus\Security\MaxBodySizeMiddleware;

/**
 * Turns a Request into a Response: resolves the route, runs the global and
 * route middlewares, and converts any throwable into an error response.
 *
 * Order: RequestEvent (a listener may short-circuit), the truncated and incomplete
 * body checks, routing and pipeline, then ResponseEvent (a listener may replace the response).
 * ExceptionEvent fires once per throwable at the point of conversion.
 *
 * A body Request::fromGlobals() truncated at its read limit never reaches a
 * handler: a global MaxBodySizeMiddleware refuses it in the pipeline, and without
 * one the kernel answers that middleware's default 413 before the pipeline. When
 * the read limit is below the configured security.maxBodySize, a
 * ConfigurationException naming the fix goes to ExceptionEvent, then is rethrown
 * when unhandled exceptions are, or logged as its message alone, on one line.
 * A body Request::fromGlobals() read incomplete is refused with a 400 before the
 * pipeline, text/plain when Accept lists text/html, JSON otherwise.
 *
 * Error responses (404, 405, failing handler) pass through the global
 * pipeline and carry its decorations (security headers, CSP, host and HTTPS
 * checks). Four responses do not: a RequestEvent short-circuit, the kernel's 413
 * for a truncated body or 400 for an incomplete one, the backstop used when a
 * global middleware itself throws, and a response replaced by a ResponseEvent
 * listener. A header that must reach every response also belongs at the web
 * server or proxy.
 *
 * A throwable is unhandled when no handler or built-in mapping answers it,
 * when its handler rethrows it, or when its handler fails with another one.
 * handle() throws only when a RequestEvent or ResponseEvent listener throws,
 * or when a throwable is unhandled (the truncated body report included) and
 * unhandled exceptions are rethrown (KernelBuilder::withUnhandledExceptionsRethrown(),
 * for a debugger).
 * Otherwise an unhandled throwable is written to the error log and answered
 * with the generic 500 (a fixed text 500 when a handler failed, logged with
 * the exception it was answering).
 *
 * Route middlewares wrap only the matched route's handler. They never run for a
 * 404 or 405, and they do not post-process the error response of a failing
 * handler.
 *
 * A matched route skips the global middlewares it excludes (#[WithoutMiddleware]
 * or Router::withoutMiddleware()). Global middlewares run on every 404 and 405,
 * so their side effects happen there as well (a session starts, for instance).
 * Their $next() returns the error response instead of throwing (unless an
 * unhandled throwable is rethrown), so transaction handling must inspect
 * $response->status and error reporting belongs in ExceptionEvent. A middleware
 * validating a request against a resource must pass through when
 * Request::ATTRIBUTE_UNMATCHED_ROUTE is set (see CsrfMiddleware). Middlewares
 * validating the connection, the envelope or the caller may answer before
 * routing, and middlewares that decorate responses must keep running.
 */
final readonly class HttpKernel
{
    private const string INCOMPLETE_BODY = 'Request body incomplete.';

    private MiddlewarePipeline $globalPipeline;

    private bool $bodyLimitMounted;

    /**
     * @param MiddlewarePipeline|null $globalPipeline Middlewares wrapping routed responses and
     *   404/405/failing-handler error responses. Not RequestEvent short-circuits, the kernel's
     *   413 for a truncated body or 400 for an incomplete one, the global-middleware backstop
     *   or ResponseEvent replacements (see class docblock).
     * @param bool $rethrowUnhandled Lets an unhandled throwable leave handle(), for a debugger.
     * @param int|null $configuredMaxBodySize The configured security.maxBodySize (0 for unlimited): a body
     *   truncated at a smaller read limit is reported. Null reports nothing.
     */
    public function __construct(
        private RouteDispatcher $dispatcher,
        private HttpExceptionResponder $exceptionResponder,
        private ?EventDispatcher $events = null,
        ?MiddlewarePipeline $globalPipeline = null,
        private bool $rethrowUnhandled = false,
        private ?int $configuredMaxBodySize = null,
    ) {
        $this->globalPipeline = $globalPipeline ?? new MiddlewarePipeline();
        $this->bodyLimitMounted = $this->globalPipeline->hasMiddleware(MaxBodySizeMiddleware::class);
    }

    public function handle(Request $request): Response
    {
        if ($this->events !== null) {
            $requestEvent = new RequestEvent($request);
            $this->events->dispatch($requestEvent);

            $shortCircuit = $requestEvent->getResponse();
            if ($shortCircuit !== null) {
                return $this->fireResponseEvent($request, $shortCircuit);
            }
        }

        if ($request->body()->isTruncated()) {
            $this->reportReadLimitBelowConfiguration($request);

            if (!$this->bodyLimitMounted) {
                return $this->fireResponseEvent($request, MaxBodySizeMiddleware::defaultRefusal($request));
            }
        }

        if (self::isIncomplete($request)) {
            return $this->fireResponseEvent($request, self::incompleteBodyRefusal($request));
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
     * Reports a body truncated at a read limit below the configured security.maxBodySize: PHP's post_max_size, or
     * Request::fromGlobals() not given the configuration. The request is refused either way.
     */
    private function reportReadLimitBelowConfiguration(Request $request): void
    {
        $readLimit = $request->body()->readLimit();
        $configured = $this->configuredMaxBodySize;
        if ($readLimit === null || $configured === null) {
            return;
        }

        if ($configured !== 0 && $configured <= $readLimit) {
            return;
        }

        $override = strtoupper(trim($request->headers()->get('x-http-method-override') ?? ''));
        $sentMethod = $request->method !== 'POST' && $override === $request->method ? 'POST' : $request->method;
        $postMaxSize = BodySize::postMaxSize($sentMethod, $request->headers()->get('content-type'));
        $error = $postMaxSize !== 0 && $readLimit === $postMaxSize
            ? ConfigurationException::postMaxSizeBelowConfiguredLimit($postMaxSize, $configured)
            : ConfigurationException::bodyReadBelowConfiguredLimit($readLimit, $configured);
        $this->fireExceptionEvent($error, $request, ExceptionEvent::SOURCE_REQUEST);

        if ($this->rethrowUnhandled) {
            throw $error;
        }

        // Any client can trigger this on every request: the message alone, never the trace or its paths.
        error_log('Zephyrus: ' . $error->getMessage());
    }

    /** A body Request::fromGlobals() read incomplete; a body built by hand is never judged. */
    private static function isIncomplete(Request $request): bool
    {
        $body = $request->body();

        // fromGlobals() marks an incomplete body malformed; the guard also keeps out a complete multipart POST, whose raw body is empty.
        return $body->readLimit() !== null
            && $body->isMalformed()
            && !$body->isTruncated()
            && BodySize::isIncomplete($request->headers(), $body->raw(), $request->files);
    }

    /** The 400 for an incomplete body: text/plain when Accept lists text/html, JSON {"error": ...} otherwise. */
    private static function incompleteBodyRefusal(Request $request): Response
    {
        if (str_contains(strtolower($request->headers()->get('Accept') ?? ''), 'text/html')) {
            return Response::text(self::INCOMPLETE_BODY, 400);
        }

        return Response::json(['error' => self::INCOMPLETE_BODY], 400);
    }

    /**
     * Matches the route, then runs the global pipeline over the matched handler
     * or over the routing error. Matching happens before the pipeline, so global
     * middlewares already see the route parameters, and a matched route can skip
     * the ones it excludes.
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
                $this->globalPipeline,
                $unmatched,
                fn (Request $piped): Response => $this->toErrorResponse(
                    $routingFailure,
                    $piped,
                    ExceptionEvent::SOURCE_ROUTING,
                ),
            );
        }

        return $this->pipe(
            $this->globalPipeline->without($match->route->excludedMiddlewares),
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
    private function pipe(MiddlewarePipeline $pipeline, Request $request, callable $destination): Response
    {
        try {
            return $pipeline->handle($request, $destination);
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
     * itself throws another throwable, that failure is unhandled in turn (see
     * toHandlerFailureResponse()).
     */
    private function toErrorResponse(Throwable $exception, Request $request, string $source): Response
    {
        $this->fireExceptionEvent($exception, $request, $source);

        try {
            $response = $this->exceptionResponder->toMappedResponse($exception, $request);
        } catch (Throwable $responderFailure) {
            // Identity, not instanceof: any other throwable is a failing handler.
            if ($responderFailure !== $exception) {
                $this->fireExceptionEvent($responderFailure, $request, ExceptionEvent::SOURCE_RESPONDER);

                return $this->toHandlerFailureResponse($responderFailure, $exception);
            }

            $response = null;
        }

        return $response ?? $this->toUnhandledResponse($exception, $request);
    }

    /**
     * Passes an unhandled throwable on when unhandled exceptions are rethrown. Otherwise logs it and returns the
     * generic 500 inside the pipeline, so global middlewares still decorate it.
     */
    private function toUnhandledResponse(Throwable $exception, Request $request): Response
    {
        if ($this->rethrowUnhandled) {
            throw new KernelRethrowSignal($exception);
        }

        $hint = DebugIntegration::refusalHint();
        $reason = $hint === null ? '' : ' (' . $hint . ')';
        self::logError('unhandled exception, answered with a 500' . $reason, $exception);

        return $this->exceptionResponder->internalErrorResponse($request);
    }

    /**
     * Passes a failing exception handler's throwable on when unhandled exceptions are rethrown. Otherwise logs it with
     * the exception it was answering and returns a fixed 500 inside the pipeline, without calling a handler again.
     */
    private function toHandlerFailureResponse(Throwable $failure, Throwable $exception): Response
    {
        if ($this->rethrowUnhandled) {
            throw new KernelRethrowSignal($failure);
        }

        self::logError('exception handler failed, answered with a 500', $failure);
        self::logError('exception the failed handler was answering', $exception);

        return Response::text('Internal Server Error', 500);
    }

    /**
     * Logs a throwable with its trace. It never throws, as it runs on the error path.
     */
    private static function logError(string $what, Throwable $exception): void
    {
        try {
            error_log('Zephyrus: ' . $what . ': ' . $exception);
        } catch (Throwable) {
            // A throwing __toString() leaves nothing to report. Never rethrown.
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
