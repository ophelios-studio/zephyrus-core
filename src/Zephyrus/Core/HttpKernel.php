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
 * Turns a Request into a Response: resolves the route, runs the middlewares,
 * and converts anything thrown along the way into an HTTP error response.
 *
 * ## Which middlewares apply to which response
 *
 * The GLOBAL pipeline wraps route dispatch AND the conversion of a throwable
 * into an error response, so a 404, a 405 and a handler-thrown 500 carry the
 * global decorations (security headers, CSP, allowed-host checks, HTTPS
 * enforcement) that a 200 carries. Error responses used to be built outside the
 * pipeline and shipped with none of those headers, on exactly the requests an
 * attacker is most likely to be probing.
 *
 * Three responses deliberately do NOT carry them, because the global pipeline
 * either never ran or had already finished:
 *
 *   1. A RequestEvent short-circuit. A listener answered before any dispatching.
 *   2. The backstop in pipe(), reached when a GLOBAL middleware itself threw.
 *      The pipeline never produced a response, and the throw already unwound
 *      past the post-processing of every middleware outside it.
 *   3. A ResponseEvent listener that replaces the response wholesale. It runs
 *      after the pipeline, so whatever it returns is final.
 *
 * Do not make a global middleware the sole carrier of a header that must be on
 * every byte leaving the process. A header that must never be missing belongs
 * at the web server or proxy as well.
 *
 * handle() does not throw for a failing route, handler or middleware, nor for
 * an exception handler that breaks. It DOES throw in two cases:
 *
 *   - A registered exception handler rethrows the SAME throwable it was given.
 *     That is an application deciding to let the exception through, normally so
 *     a debugger can render it in development, and the framework passes it
 *     along rather than overriding the decision. A handler that throws anything
 *     else is treated as broken and yields the decorated fallback instead.
 *   - A RequestEvent or ResponseEvent listener throws, since those run outside
 *     the pipeline and outside the backstop.
 *
 * ROUTE middlewares only ever wrap the matched route's handler:
 *
 *   - 404 / 405: no route matched, so the route has no middlewares to run.
 *     Only global middlewares apply.
 *   - Handler threw: route middlewares run on the way IN, but the throw unwinds
 *     past them, so only global middlewares decorate the resulting response.
 *     See dispatchMatchedRoute().
 *   - Matched route returning normally: global middlewares first, then route
 *     middlewares, then the handler. That ordering is unchanged.
 *
 * ## Behaviour changes to be aware of
 *
 * **Global middlewares no longer observe a throwable from the handler or from a
 * route middleware.** The conversion now happens inside the pipeline, so a
 * global middleware's call to $next() RETURNS a 500 response where it
 * previously let the exception propagate. A middleware written as
 *
 *     try { $response = $next($request); $this->commit(); return $response; }
 *     catch (Throwable $e) { $this->rollback(); throw $e; }
 *
 * will no longer roll back, no longer report, and will commit on a 500. Any
 * global middleware doing transaction management or span completion must switch
 * to inspecting $response->status instead of catching.
 *
 * For ERROR REPORTING specifically, listen to ExceptionEvent instead. It fires
 * once per throwable at the point of conversion, carries the throwable, the
 * request and where it came from, and cannot be silently skipped the way a
 * catch block now is. Registering no listener changes nothing.
 *
 * Because the global pipeline now also runs on requests that match no route,
 * two more things follow.
 *
 * A global middleware with a SIDE EFFECT now has it on a 404. SessionMiddleware
 * is the notable one: a 404 now starts a session, as it does in Laravel and
 * Symfony. See SessionMiddleware for the measured cost and how to avoid it on
 * unauthenticated traffic.
 *
 * A global middleware that SHORT-CIRCUITS can now answer before the 404 is
 * produced, so the status code on those paths can change. Whether that is right
 * depends on what the middleware is asking about, and the two cases are not the
 * same:
 *
 *   - A middleware validating the CONNECTION, the ENVELOPE or the CALLER should
 *     still run. ForceHttpsMiddleware answers a plain-HTTP request to an
 *     unknown path with a 308, AllowedHostsMiddleware answers a disallowed Host
 *     with a 400, and a globally registered AuthGuardMiddleware answers an
 *     unauthorised caller with a 401. None of those questions depends on the
 *     URL existing: nothing should be served over plain HTTP, a forged Host
 *     header is abuse whatever it points at, and an application that guards
 *     every request usually means to reveal nothing to a stranger, route map
 *     included. Answering before routing is the point.
 *   - A middleware validating a request AGAINST A RESOURCE must not.
 *     CsrfMiddleware asks whether a state change to a resource is authorised;
 *     when no route matched there is no resource and no state change, so it
 *     would be answering a question that does not apply. It is passed over via
 *     Request::ATTRIBUTE_UNMATCHED_ROUTE, and the request gets its 404 or 405.
 *
 * Consumer middlewares that validate against a resource should follow
 * CsrfMiddleware and consult that attribute. Ones that decorate a response must
 * ignore it and keep running, or error responses lose their headers again.
 */
final readonly class HttpKernel
{
    private MiddlewarePipeline $globalPipeline;

    /**
     * @param MiddlewarePipeline|null $globalPipeline Middlewares wrapping every
     *   response, error responses included. Defaults to an empty pipeline.
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
        // 1. Pre-dispatch: listeners may short-circuit routing entirely.
        if ($this->events !== null) {
            $requestEvent = new RequestEvent($request);
            $this->events->dispatch($requestEvent);

            if ($requestEvent->hasResponse()) {
                return $this->fireResponseEvent($request, $requestEvent->getResponse());
            }
        }

        // 2. Post-dispatch: listeners may inspect / replace the response.
        try {
            $response = $this->resolveAndPipe($request);
        } catch (KernelRethrowSignal $signal) {
            // An exception handler deliberately rethrew. Unwrap so the
            // application sees its own exception, never this internal marker.
            // No ResponseEvent fires: there is no response to fire it with.
            throw $signal->original;
        }

        return $this->fireResponseEvent($request, $response);
    }

    /**
     * Runs the global pipeline over whatever the request resolves to: the
     * matched route's handler, or the error response for a routing failure.
     * Either way the conversion happens INSIDE the pipeline, so the error
     * response carries the global decorations.
     *
     * Matching runs out here, before the pipeline, because it is a resolution
     * step whose result the request needs: the route parameters are on the
     * request by the time the global middlewares see it, which is where they
     * were before this class owned the pipeline. A routing failure is not
     * rethrown, it is turned into the pipeline's destination.
     */
    private function resolveAndPipe(Request $request): Response
    {
        try {
            $match = $this->dispatcher->match($request);
        } catch (Throwable $routingFailure) {
            // Flag the request as unmatched so a global middleware that
            // VALIDATES a request can decline to answer for a resource that
            // does not exist. Set only here, because only a routing failure has
            // anything to flag. See Request::ATTRIBUTE_UNMATCHED_ROUTE.
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

        // withRouteParameters(), not withAttributes(): the values land in the
        // attributes exactly as before, and the request additionally records
        // that a URL segment is where they came from. A security decision keyed
        // on an attribute can then tell a value the CALLER chose from a value a
        // middleware established. See Request::$routeParameters.
        return $this->pipe(
            $request->withRouteParameters($match->parameters),
            fn (Request $piped): Response => $this->dispatchMatchedRoute($match, $piped),
        );
    }

    /**
     * Runs the global pipeline, with a last-resort backstop.
     *
     * The backstop is reached only when a GLOBAL middleware itself threw, so
     * the pipeline never produced a response. It converts directly and never
     * back through the pipeline, so a middleware that always throws yields one
     * error response instead of looping. That response carries no global
     * decorations, because the throw already unwound past them. See the class
     * docblock.
     *
     * @param callable(Request): Response $destination
     */
    private function pipe(Request $request, callable $destination): Response
    {
        try {
            return $this->globalPipeline->handle($request, $destination);
        } catch (KernelRethrowSignal $signal) {
            // A deliberate rethrow from the application's own exception
            // handler. Converting it here would call that handler a second
            // time, so it passes straight through to handle().
            throw $signal;
        } catch (Throwable $exception) {
            return $this->toErrorResponse($exception, $request, ExceptionEvent::SOURCE_MIDDLEWARE);
        }
    }

    /**
     * Destination of the global pipeline for a request that matched a route.
     *
     * The catch sits here, one layer INSIDE the global middlewares, so that a
     * throwing handler still produces a response they decorate.
     *
     * Route middlewares run on the way in, but they do NOT post-process that
     * response: the exception has already unwound past their process() calls by
     * the time it arrives here. Catching deeper (inside the route pipeline) was
     * rejected on purpose. Route middlewares are typically guards whose
     * post-processing assumes the handler succeeded, and a 404 can never run
     * them at all, so running them on a 500 would make error decoration depend
     * on how the error happened. Global middlewares carry the security headers,
     * and they always run.
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
     * Converts a throwable into an error response, guarding against the
     * responder itself failing.
     *
     * A registered exception handler is application code and can throw: a
     * missing error-page template is the usual cause. Without this guard that
     * second throwable escapes the destination closure, unwinds past every
     * global middleware, and the request is answered with an UNDECORATED
     * response, which is the very bug this class is arranged to prevent. A
     * broken 404 template would otherwise strip the security headers from every
     * 404 on the site.
     *
     * The fallback is deliberately dumb: a fixed 500 built without touching the
     * responder again, so it cannot fail in turn. It is returned from inside the
     * pipeline, so the global middlewares still decorate it.
     */
    private function toErrorResponse(Throwable $exception, Request $request, string $source): Response
    {
        $this->fireExceptionEvent($exception, $request, $source);

        try {
            return $this->exceptionResponder->toResponse($exception, $request);
        } catch (Throwable $responderFailure) {
            // A handler that rethrows the SAME object is passing the exception
            // through on purpose, typically so a debugger can render it in
            // development. That is an application decision and the framework
            // must not override it: swallowing it replaced a stack trace with a
            // plain "Internal Server Error" and took the developer's debugger
            // away with nothing to explain why.
            //
            // Identity, not instanceof: a handler that throws anything ELSE is
            // failing, not deciding, and that is the case this guard exists for.
            if ($responderFailure === $exception) {
                throw new KernelRethrowSignal($exception);
            }

            // A second, distinct throwable. Reported separately so a broken
            // error template is visible rather than swallowed.
            $this->fireExceptionEvent($responderFailure, $request, ExceptionEvent::SOURCE_RESPONDER);

            return Response::text('Internal Server Error', 500);
        }
    }

    /**
     * Dispatch the reporting seam for one throwable.
     *
     * This is the ONLY place ExceptionEvent is fired, which is what makes
     * "exactly once per throwable" hold. It never touches the pipeline or the
     * responder, so it cannot recurse.
     *
     * Anything a listener throws is swallowed: a reporter must never be able to
     * turn a handled error response into a dead connection.
     *
     * The catch is still around the WHOLE dispatch, so the first reporter that
     * throws also cancels the reporters after it. EventDispatcher::dispatch()
     * now accepts a per-listener error handler that would fix exactly that, and
     * passing one here is a one-line change; it is deliberately NOT made,
     * because HttpKernelExceptionEventTest pins the current behaviour on
     * purpose and inverting a pinned expectation is not this layer's call to
     * make on its own.
     *
     * What DID change: the failure is no longer invisible. It used to vanish
     * into an empty catch block, so an application whose reporter had been
     * broken for weeks had no way to find out.
     */
    private function fireExceptionEvent(Throwable $exception, Request $request, string $source): void
    {
        if ($this->events === null) {
            return;
        }

        try {
            $this->events->dispatch(new ExceptionEvent($request, $exception, $source));
        } catch (Throwable $listenerFailure) {
            // Never rethrown, see above. Logged so it is findable.
            error_log(sprintf(
                'Zephyrus: an ExceptionEvent listener failed and the remaining listeners were skipped: %s: %s',
                $listenerFailure::class,
                $listenerFailure->getMessage(),
            ));
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
