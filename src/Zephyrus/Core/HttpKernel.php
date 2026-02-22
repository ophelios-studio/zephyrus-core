<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Throwable;
use Zephyrus\Http\Error\HttpExceptionResponder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\RouteDispatcher;

final readonly class HttpKernel
{
    public function __construct(
        private RouteDispatcher $dispatcher,
        private HttpExceptionResponder $exceptionResponder,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->dispatcher->dispatch($request);
        } catch (Throwable $exception) {
            return $this->exceptionResponder->toResponse($exception, $request);
        }
    }
}
