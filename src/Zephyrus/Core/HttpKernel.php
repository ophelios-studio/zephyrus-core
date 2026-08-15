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
 * handle() itself does not throw for a failing route, handler, middleware or
 * exception handler. It can still throw if a RequestEvent or ResponseEvent
 * listener throws, since those run outside the pipeline and outside the
 * backstop.
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
 * global middleware doing error capture, transaction management or span
 * completion must switch to inspecting $response->status instead of catching.
 * A middleware that silently stops reporting is the worst failure mode here, so
 * audit for this shape before upgrading.
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
 * produced, so the status code on those paths changes. Measured examples: with
 * CsrfMiddleware registered globally, a POST to an unknown path returns 403
 * instead of 404; with ForceHttpsMiddleware, a plain-HTTP request to an unknown
 * path returns a 308 redirect instead of 404; with AllowedHostsMiddleware, a
 * request carrying a disallowed Host returns 400 instead of 404. Each of those
 * is the intended consequence of the check applying everywhere rather than only
 * on matched routes, and each stops an unauthenticated prober from learning
 * which routes exist, but the response a client sees does change.
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
        return $this->fireResponseEvent($request, $this->resolveAndPipe($request));
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
            return $this->pipe(
                $request,
                fn (Request $piped): Response => $this->toErrorResponse($routingFailure, $piped),
            );
        }

        return $this->pipe(
            $request->withAttributes($match->parameters),
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
        } catch (Throwable $exception) {
            return $this->toErrorResponse($exception, $request);
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
            return $this->toErrorResponse($exception, $request);
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
    private function toErrorResponse(Throwable $exception, Request $request): Response
    {
        try {
            return $this->exceptionResponder->toResponse($exception, $request);
        } catch (Throwable $responderFailure) {
            return Response::text('Internal Server Error', 500);
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
