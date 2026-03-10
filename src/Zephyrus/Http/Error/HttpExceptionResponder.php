<?php

declare(strict_types=1);

namespace Zephyrus\Http\Error;

use Throwable;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\MethodNotAllowedException;
use Zephyrus\Routing\Exception\RouteNotFoundException;
use Zephyrus\Validation\ValidationException;

/**
 * Converts thrown exceptions into HTTP error responses with content negotiation.
 *
 * Built-in mappings:
 *   - RouteNotFoundException        → 404 Not Found
 *   - MethodNotAllowedException     → 405 Method Not Allowed (+ Allow header)
 *   - ValidationException           → 422 Unprocessable Entity (+ field errors)
 *   - Any other Throwable           → 500 Internal Server Error
 *
 * Custom handlers can be registered via registerHandler(). When an exception
 * is thrown, registered handlers are checked first (most-specific class wins
 * via instanceof). If no custom handler matches, the built-in mappings apply.
 *
 * Example:
 *
 *   $responder = new HttpExceptionResponder();
 *   $responder->registerHandler(AccessDeniedException::class, function (Throwable $e, ?Request $r) {
 *       return Response::text('Forbidden', 403);
 *   });
 */
class HttpExceptionResponder
{
    private const FORMAT_TEXT = 'text';
    private const FORMAT_JSON = 'json';
    private const FORMAT_PROBLEM_JSON = 'problem+json';

    /** @var array<class-string<Throwable>, callable(Throwable, ?Request): Response> */
    private array $handlers = [];

    /**
     * Register a custom exception handler for a specific exception class.
     *
     * The handler receives (Throwable $exception, ?Request $request) and must
     * return a Response. Handlers are checked before built-in mappings.
     *
     * When multiple handlers could match (via inheritance), the most-specific
     * class wins (checked by order of registration, with instanceof matching).
     *
     * @param class-string<Throwable> $exceptionClass
     * @param callable(Throwable, ?Request): Response $handler
     * @return $this
     */
    public function registerHandler(string $exceptionClass, callable $handler): self
    {
        $this->handlers[$exceptionClass] = $handler;

        return $this;
    }

    public function toResponse(Throwable $exception, ?Request $request = null): Response
    {
        // Check custom handlers first (most-specific class wins)
        $customResponse = $this->resolveCustomHandler($exception, $request);
        if ($customResponse !== null) {
            return $customResponse;
        }

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

    /**
     * Resolve the most-specific custom handler for the given exception.
     *
     * Handlers registered for more specific exception classes take precedence.
     * When two handlers match, the one whose class is a subclass of the other wins.
     * If neither is more specific (unrelated classes), the first registered match wins.
     */
    private function resolveCustomHandler(Throwable $exception, ?Request $request): ?Response
    {
        if ($this->handlers === []) {
            return null;
        }

        $bestClass = null;
        $bestHandler = null;

        foreach ($this->handlers as $class => $handler) {
            if (!($exception instanceof $class)) {
                continue;
            }

            // First match or more-specific match (subclass of current best)
            if ($bestClass === null || is_subclass_of($class, $bestClass)) {
                $bestClass = $class;
                $bestHandler = $handler;
            }
        }

        if ($bestHandler !== null) {
            return $bestHandler($exception, $request);
        }

        return null;
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
        $problemSpecificity = 0;
        $jsonQ = 0.0;
        $jsonSpecificity = 0;

        foreach ($ranges as $range) {
            if ($range['q'] <= 0.0 || $range['type'] !== 'application') {
                continue;
            }

            if ($range['subtype'] === 'problem+json') {
                if ($range['q'] > $problemQ || ($range['q'] === $problemQ && 2 > $problemSpecificity)) {
                    $problemQ = $range['q'];
                    $problemSpecificity = 2;
                }
            } elseif ($range['subtype'] === '*+json') {
                if ($range['q'] > $problemQ || ($range['q'] === $problemQ && 1 > $problemSpecificity)) {
                    $problemQ = $range['q'];
                    $problemSpecificity = 1;
                }
            }

            if ($range['subtype'] === 'json' || str_ends_with($range['subtype'], '+json')) {
                $specificity = match ($range['subtype']) {
                    '*+json', 'problem+json' => 1,
                    default => 2,
                };

                if ($range['q'] > $jsonQ || ($range['q'] === $jsonQ && $specificity > $jsonSpecificity)) {
                    $jsonQ = $range['q'];
                    $jsonSpecificity = $specificity;
                }
            }
        }

        if ($problemQ <= 0.0 && $jsonQ <= 0.0) {
            return self::FORMAT_TEXT;
        }

        if ($problemQ > $jsonQ) {
            return self::FORMAT_PROBLEM_JSON;
        }

        if ($jsonQ > $problemQ) {
            return self::FORMAT_JSON;
        }

        if ($problemSpecificity > $jsonSpecificity) {
            return self::FORMAT_PROBLEM_JSON;
        }

        return self::FORMAT_JSON;
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
