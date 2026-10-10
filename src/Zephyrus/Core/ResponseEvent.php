<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Fired by HttpKernel with the response it is about to return, whether it comes
 * from routing, the exception responder or a RequestEvent short-circuit. Every
 * listener runs, and each may replace the response in turn.
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
