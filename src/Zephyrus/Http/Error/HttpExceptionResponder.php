<?php

declare(strict_types=1);

namespace Zephyrus\Http\Error;

use Throwable;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;

final class HttpExceptionResponder
{
    public function toResponse(Throwable $exception, ?Request $request = null): Response
    {
        if ($exception instanceof MethodNotAllowedException) {
            return $this->format(
                payload: new HttpErrorPayload(405, 'Method Not Allowed'),
                request: $request,
            )->withHeader('Allow', implode(', ', $exception->allowedMethods));
        }

        if ($exception instanceof RouteNotFoundException) {
            return $this->format(
                payload: new HttpErrorPayload(404, 'Not Found'),
                request: $request,
            );
        }

        return $this->format(
            payload: new HttpErrorPayload(500, 'Internal Server Error'),
            request: $request,
        );
    }

    private function format(HttpErrorPayload $payload, ?Request $request): Response
    {
        if ($this->prefersJson($request)) {
            return Response::json($payload->toArray(), $payload->status);
        }

        return Response::text($payload->message, $payload->status);
    }

    private function prefersJson(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }

        $accept = strtolower($request->header('accept', ''));

        return str_contains($accept, 'application/json')
            || str_contains($accept, 'application/problem+json');
    }
}
