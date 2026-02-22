<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Event\Event;
use Zephyrus\Http\Request;

/**
 * Base class for all kernel lifecycle events.
 *
 * Every kernel event carries the current HTTP request so that listeners may
 * inspect the incoming data.  Concrete subclasses add lifecycle-specific
 * payload (e.g. a short-circuit Response or the outgoing Response).
 *
 * Kernel events are dispatched synchronously by HttpKernel::handle() at two
 * fixed points in the request lifecycle:
 *
 *   1. Before route dispatching  → RequestEvent
 *   2. After a response is built → ResponseEvent
 */
abstract class KernelEvent extends Event
{
    public function __construct(
        private readonly Request $request,
    ) {
    }

    /**
     * Return the current HTTP request.
     */
    public function getRequest(): Request
    {
        return $this->request;
    }
}
