<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Throwable;
use Zephyrus\Http\Request;

/**
 * Fired by HttpKernel each time a throwable is turned into an error response.
 *
 * This is the reporting seam. Error conversion happens inside the global
 * middleware pipeline, so a middleware's $next() returns the error response
 * rather than throwing. A middleware doing "catch, report, rethrow" never sees
 * the throwable: report here instead.
 *
 * The event is observation only. A listener cannot replace the response: use
 * KernelBuilder::withExceptionHandler() to map an exception to a response, or
 * ResponseEvent to change the final one. A listener that throws is logged and
 * skipped, and the remaining listeners still run.
 *
 * Fires once per throwable. A request whose handler fails and whose exception
 * responder then fails fires twice, with different sources.
 *
 * Example:
 *
 *   $dispatcher->addListener(ExceptionEvent::class, function (ExceptionEvent $e): void {
 *       if ($e->isRoutingFailure()) {
 *           return;
 *       }
 *
 *       $reporter->capture($e->getException(), [
 *           'path'   => $e->getRequest()->path(),
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
     * Return true for a 404 or 405 (no route matched), which most reporters ignore.
     */
    public function isRoutingFailure(): bool
    {
        return $this->source === self::SOURCE_ROUTING;
    }
}
