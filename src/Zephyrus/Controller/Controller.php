<?php

declare(strict_types=1);

namespace Zephyrus\Controller;

use Zephyrus\Http\Response;

/**
 * Optional base class for route controllers.
 *
 * Provides convenience factory methods for building common Response values.
 * Controllers are not required to extend this class — HandlerResolver works
 * with any plain object whose methods return a Response. This class exists
 * only to reduce boilerplate in concrete controller implementations.
 *
 * ## Usage
 *
 * ```php
 * final class UserController extends Controller
 * {
 *     public function show(int $id): Response
 *     {
 *         return $this->json(['id' => $id]);
 *     }
 *
 *     public function store(Request $request): Response
 *     {
 *         $name = $request->input('name');
 *         return $this->created(['name' => $name]);
 *     }
 * }
 * ```
 *
 * Handler methods may declare any combination of:
 * - A `Request $request` parameter — receives the current request.
 * - Scalar parameters whose names match route path parameters (e.g. `int $id`)
 *   — resolved from request attributes populated by RouteDispatcher.
 */
abstract class Controller
{
    /**
     * Returns a 200 JSON response.
     *
     * @param array<mixed> $payload
     */
    protected function json(array $payload, int $status = 200): Response
    {
        return Response::json($payload, $status);
    }

    /**
     * Returns a 201 Created JSON response.
     *
     * @param array<mixed> $payload
     */
    protected function created(array $payload): Response
    {
        return Response::json($payload, 201);
    }

    /**
     * Returns a 200 plain-text response.
     */
    protected function text(string $body, int $status = 200): Response
    {
        return Response::text($body, $status);
    }

    /**
     * Returns a 204 No Content response.
     */
    protected function noContent(): Response
    {
        return Response::noContent();
    }

    /**
     * Returns a JSON response with the given HTTP status code.
     *
     * @param array<mixed> $payload
     */
    protected function respond(array $payload, int $status): Response
    {
        return Response::json($payload, $status);
    }
}
