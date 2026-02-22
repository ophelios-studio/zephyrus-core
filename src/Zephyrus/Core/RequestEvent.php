<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Http\Response;

/**
 * Fired by HttpKernel immediately before route dispatching.
 *
 * A listener may short-circuit the entire dispatch pipeline by calling
 * setResponse().  When a response is set the kernel returns it directly
 * without invoking the router or executing any further listeners (propagation
 * is stopped automatically).
 *
 * Typical use-cases:
 *   - Maintenance-mode responses
 *   - IP-level or token-level access control
 *   - Full-page cache hits that bypass routing entirely
 *
 * Example:
 *
 *   $dispatcher->addListener(RequestEvent::class, function (RequestEvent $e): void {
 *       if ($e->getRequest()->header('X-Maintenance-Key') !== 'secret') {
 *           $e->setResponse(Response::text('Down for maintenance', status: 503));
 *       }
 *   });
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
