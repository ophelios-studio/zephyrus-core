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
        if ($this->prefersProblemJson($request)) {
            return Response::json([
                'type' => 'about:blank',
                'title' => $payload->message,
                'status' => $payload->status,
            ], $payload->status)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
        }

        if ($this->prefersJson($request)) {
            return Response::json($payload->toArray(), $payload->status);
        }

        return Response::text($payload->message, $payload->status);
    }

    private function prefersProblemJson(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }

        return str_contains(strtolower($request->header('accept', '')), 'application/problem+json');
    }

    private function prefersJson(?Request $request): bool
    {
        if ($request === null) {
            return false;
        }

        return str_contains(strtolower($request->header('accept', '')), 'application/json');
    }
}
