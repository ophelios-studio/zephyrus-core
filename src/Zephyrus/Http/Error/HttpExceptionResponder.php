<?php

declare(strict_types=1);

namespace Zephyrus\Http\Error;

use Throwable;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;

final class HttpExceptionResponder
{
    public function toResponse(Throwable $exception): Response
    {
        if ($exception instanceof MethodNotAllowedException) {
            return Response::text('Method Not Allowed', 405)
                ->withHeader('Allow', implode(', ', $exception->allowedMethods));
        }

        if ($exception instanceof RouteNotFoundException) {
            return Response::text('Not Found', 404);
        }

        return Response::text('Internal Server Error', 500);
    }
}
