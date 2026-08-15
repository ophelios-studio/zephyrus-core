<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Throwable;
use Zephyrus\Http\Request;

/**
 * Fired by HttpKernel every time a throwable is turned into an error response.
 *
 * This is the reporting seam. Since the error conversion happens INSIDE the
 * global middleware pipeline (so that error responses carry the security
 * headers), a global middleware's call to $next() RETURNS a 500 rather than
 * letting the exception propagate. A middleware written as
 * "catch, report, rethrow" therefore never sees the throwable. Without this
 * event an error reporter would go quiet with nothing to indicate it had, which
 * is the worst possible failure mode for a reporter. Listen here instead.
 *
 * ## Observation only, deliberately
 *
 * A listener CANNOT replace the response. That is not an oversight. The
 * framework already has two seams for changing an error response, and adding a
 * third with its own precedence rules would make it unclear which one wins:
 *
 *   - KernelBuilder::withExceptionHandler() maps an exception class to a
 *     response, BEFORE the response is built.
 *   - ResponseEvent fires after the response is built and may replace it
 *     wholesale, error responses included.
 *
 * Keeping this event read-only means a reporter cannot break error handling by
 * accident, and a listener that throws can be swallowed safely because it had
 * no influence on the response to begin with.
 *
 * ## A listener that throws is swallowed
 *
 * HttpKernel catches anything a listener throws and carries on building the
 * response. A broken reporter must never turn a handled 404 into a dead
 * connection.
 *
 * Two consequences worth knowing. A listener failure is SILENT, so a listener
 * should do its own error handling rather than rely on this. And listeners are
 * not isolated from each other: the dispatcher runs them in priority order in
 * one loop, so the first one to throw stops the rest for that event. If two
 * independent reporters must both run, make each one catch its own errors.
 *
 * ## Firing rules
 *
 * Fires exactly once per throwable, at the single point where the kernel
 * converts one. It never fires for a throwable that was already reported, and
 * it cannot re-enter the pipeline, so it cannot loop. A request that produces
 * two distinct throwables (a handler failing, then the exception responder
 * itself failing) fires twice, once for each, with different sources.
 *
 * Example:
 *
 *   $dispatcher->addListener(ExceptionEvent::class, function (ExceptionEvent $e): void {
 *       // A 404 is routine; a handler blowing up is not.
 *       if ($e->isRoutingFailure()) {
 *           return;
 *       }
 *
 *       $reporter->capture($e->getException(), [
 *           'path'   => $e->getRequest()->uri()->path(),
 *           'method' => $e->getRequest()->method,
 *           'source' => $e->getSource(),
 *       ]);
 *   });
 */
final class ExceptionEvent extends KernelEvent
{
    /** No route matched the request, so this became a 404 or a 405. */
    public const SOURCE_ROUTING = 'routing';

    /** The matched route's handler, or one of its route middlewares, threw. */
    public const SOURCE_HANDLER = 'handler';

    /** A GLOBAL middleware threw, so the pipeline produced no response. */
    public const SOURCE_MIDDLEWARE = 'middleware';

    /** The exception responder itself threw, usually a broken error template. */
    public const SOURCE_RESPONDER = 'responder';

    /**
     * @param string $source One of the SOURCE_* constants, describing where the
     *   throwable came from.
     */
    public function __construct(
        Request $request,
        private readonly Throwable $exception,
        private readonly string $source,
    ) {
        parent::__construct($request);
    }

    /**
     * Return the throwable being converted into an error response.
     */
    public function getException(): Throwable
    {
        return $this->exception;
    }

    /**
     * Return where the throwable came from, as one of the SOURCE_* constants.
     */
    public function getSource(): string
    {
        return $this->source;
    }

    /**
     * Return true when nothing matched the request, i.e. this is a 404 or a
     * 405 rather than an application failure.
     *
     * Most reporters want to ignore these: a missing URL is routine traffic and
     * reporting it drowns the real failures.
     */
    public function isRoutingFailure(): bool
    {
        return $this->source === self::SOURCE_ROUTING;
    }
}
