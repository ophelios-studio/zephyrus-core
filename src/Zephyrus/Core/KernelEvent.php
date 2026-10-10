<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Event\Event;
use Zephyrus\Http\Request;

/**
 * Base class for kernel lifecycle events. Carries the request being handled.
 *
 * HttpKernel::handle() dispatches, in order: RequestEvent before routing,
 * ExceptionEvent each time a throwable becomes an error response, and
 * ResponseEvent for every response handle() returns.
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
