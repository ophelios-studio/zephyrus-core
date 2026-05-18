<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Throwable;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\RouteDispatcher;

final readonly class HttpKernel
{
    public function __construct(
        private RouteDispatcher $dispatcher,
        private HttpExceptionResponder $exceptionResponder,
        private ?EventDispatcher $events = null,
    ) {
    }

    /**
     * Handle one HTTP request while exposing it as the active framework request.
     *
     * Most of the kernel still receives the Request explicitly, but convenience
     * APIs such as Inertia::render() need to build a response from controller
     * code without forcing every call site to pass the current Request again.
     * Storing the request for the duration of dispatch gives those APIs access
     * to request headers, URL, method, and version information required by the
     * Inertia protocol.
     *
     * The previous request is restored in a finally block so the registry does
     * not leak state after exceptions, tests, nested dispatches, or long-running
     * PHP workers.
     */
    public function handle(Request $request): Response
    {
        $previousRequest = App::getRequest();
        App::setRequest($request);

        try {
            return $this->handleCurrentRequest($request);
        } finally {
            App::setRequest($previousRequest);
        }
    }

    private function handleCurrentRequest(Request $request): Response
    {
        // 1. Pre-dispatch: listeners may short-circuit routing entirely.
        if ($this->events !== null) {
            $requestEvent = new RequestEvent($request);
            $this->events->dispatch($requestEvent);

            if ($requestEvent->hasResponse()) {
                return $this->fireResponseEvent($request, $requestEvent->getResponse());
            }
        }

        // 2. Route dispatch (or exception → error response).
        try {
            $response = $this->dispatcher->dispatch($request);
        } catch (Throwable $exception) {
            $response = $this->exceptionResponder->toResponse($exception, $request);
        }

        // 3. Post-dispatch: listeners may inspect / replace the response.
        return $this->fireResponseEvent($request, $response);
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
