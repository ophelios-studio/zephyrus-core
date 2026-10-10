<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Http\Response;

/**
 * Fired by HttpKernel before routing. A listener calling setResponse() short-circuits
 * routing: no further RequestEvent listener runs, and the response still goes through
 * ResponseEvent.
 */
final class RequestEvent extends KernelEvent
{
    private ?Response $response = null;

    /**
     * Set a short-circuit response.
     *
     * Calling this method also stops event propagation so that no further
     * listeners receive the event.
     */
    public function setResponse(Response $response): void
    {
        $this->response = $response;
        $this->stopPropagation();
    }

    /**
     * Return the short-circuit response, or null when no listener has set one.
     */
    public function getResponse(): ?Response
    {
        return $this->response;
    }

    /**
     * Return true when a listener has provided a short-circuit response.
     */
    public function hasResponse(): bool
    {
        return $this->response !== null;
    }
}
