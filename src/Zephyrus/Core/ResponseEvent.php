<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Fired by HttpKernel after a Response has been produced (either by the
 * router or by the exception responder).
 *
 * Listeners may inspect or replace the response by calling setResponse().
 * Unlike RequestEvent there is no short-circuit semantic: every listener
 * always receives the event and may modify the response in turn.
 *
 * Typical use-cases:
 *   - Injecting global headers (CORS, Cache-Control, X-Frame-Options)
 *   - Structured response logging
 *   - Body post-processing (compression, serialisation transforms)
 *
 * Example:
 *
 *   $dispatcher->addListener(ResponseEvent::class, function (ResponseEvent $e): void {
 *       $e->setResponse(
 *           $e->getResponse()->withHeader('X-Response-Time', '42ms')
 *       );
 *   });
 */
final class ResponseEvent extends KernelEvent
{
    public function __construct(
        Request $request,
        private Response $response,
    ) {
        parent::__construct($request);
    }

    /**
     * Return the current response (may have been replaced by a prior listener).
     */
    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * Replace the response that will be returned to the caller.
     *
     * Subsequent listeners will see the new response via getResponse().
     */
    public function setResponse(Response $response): void
    {
        $this->response = $response;
    }
}
