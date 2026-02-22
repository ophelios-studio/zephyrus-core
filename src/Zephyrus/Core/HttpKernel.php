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
