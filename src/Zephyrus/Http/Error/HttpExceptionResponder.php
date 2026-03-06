<?php

declare(strict_types=1);

namespace Zephyrus\Http\Error;

use Throwable;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Validation\ValidationException;

final class HttpExceptionResponder
{
    private const FORMAT_TEXT = 'text';
    private const FORMAT_JSON = 'json';
    private const FORMAT_PROBLEM_JSON = 'problem+json';

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

        if ($exception instanceof ValidationException) {
            return $this->formatValidation($exception, $request);
        }

        return $this->format(
            payload: new HttpErrorPayload(500, 'Internal Server Error'),
            request: $request,
        );
    }

    private function formatValidation(ValidationException $exception, ?Request $request): Response
    {
        $errors = $exception->errors()->toArray();
        $format = $this->preferredFormat($request);

        if ($format === self::FORMAT_PROBLEM_JSON) {
            return Response::json([
                'type'   => 'about:blank',
                'title'  => 'Unprocessable Entity',
                'status' => 422,
                'errors' => $errors,
            ], 422)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
        }

        if ($format === self::FORMAT_JSON) {
            return Response::json(['errors' => $errors], 422);
        }

        return Response::text('Unprocessable Entity', 422);
    }

    private function format(HttpErrorPayload $payload, ?Request $request): Response
    {
        $format = $this->preferredFormat($request);

        if ($format === self::FORMAT_PROBLEM_JSON) {
            return Response::json([
                'type' => 'about:blank',
                'title' => $payload->message,
                'status' => $payload->status,
            ], $payload->status)->withHeader('Content-Type', 'application/problem+json; charset=utf-8');
        }

        if ($format === self::FORMAT_JSON) {
            return Response::json($payload->toArray(), $payload->status);
        }

        return Response::text($payload->message, $payload->status);
    }

    private function preferredFormat(?Request $request): string
    {
        if ($request === null) {
            return self::FORMAT_TEXT;
        }

        $acceptHeader = $request->header('accept', '');
        $ranges = $this->parseAcceptHeader($acceptHeader);

        if ($ranges === []) {
            return self::FORMAT_TEXT;
        }

        $problemQ = 0.0;
        $jsonQ = 0.0;

        foreach ($ranges as $range) {
            if ($range['q'] <= 0.0 || $range['type'] !== 'application') {
                continue;
            }

            if ($range['subtype'] === 'problem+json' || $range['subtype'] === '*+json') {
                $problemQ = max($problemQ, $range['q']);
            }

            if (
                $range['subtype'] === 'json'
                || $range['subtype'] === '*+json'
                || str_ends_with($range['subtype'], '+json')
            ) {
                $jsonQ = max($jsonQ, $range['q']);
            }
        }

        if ($problemQ <= 0.0 && $jsonQ <= 0.0) {
            return self::FORMAT_TEXT;
        }

        return $problemQ >= $jsonQ ? self::FORMAT_PROBLEM_JSON : self::FORMAT_JSON;
    }

    /**
     * @return array<int, array{type: string, subtype: string, q: float}>
     */
    private function parseAcceptHeader(string $header): array
    {
        $ranges = [];

        foreach (explode(',', strtolower($header)) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = explode(';', $part);
            $mediaType = trim((string) array_shift($segments));
            if (!str_contains($mediaType, '/')) {
                continue;
            }

            [$type, $subtype] = array_map('trim', explode('/', $mediaType, 2));
            if ($type === '' || $subtype === '') {
                continue;
            }

            $q = 1.0;
            foreach ($segments as $parameter) {
                $parameter = trim($parameter);
                if (!str_starts_with($parameter, 'q=')) {
                    continue;
                }

                $rawQ = trim(substr($parameter, 2));
                if (!is_numeric($rawQ)) {
                    continue;
                }

                $q = max(0.0, min(1.0, (float) $rawQ));
            }

            $ranges[] = [
                'type' => $type,
                'subtype' => $subtype,
                'q' => $q,
            ];
        }

        return $ranges;
    }
}
