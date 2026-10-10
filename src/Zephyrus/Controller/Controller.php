<?php

declare(strict_types=1);

namespace Zephyrus\Controller;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Validation\ErrorBag;
use Zephyrus\Validation\FormValidator;
use Zephyrus\Validation\ValidationException;

/**
 * Optional base class for controllers, with response helpers and no-op lifecycle hooks.
 *
 * Handler methods may take a `Request $request` parameter and scalar parameters named
 * after route path parameters (e.g. `int $id`). Hooks are described in ControllerLifecycleInterface.
 * Any plain object whose handler methods return a Response also works.
 */
abstract class Controller implements ControllerLifecycleInterface
{
    /**
     * The current request, set by the default before().
     */
    protected Request $request;

    /**
     * Store the request and return null.
     *
     * Overrides must call parent::before($request), or $this->request stays unset.
     */
    public function before(Request $request): ?Response
    {
        $this->request = $request;
        return null;
    }

    /**
     * Return the handler's Response unchanged.
     */
    public function after(Request $request, Response $response): Response
    {
        return $response;
    }

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

    /**
     * Returns a redirect response. Never pass user input here: see localRedirect().
     */
    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Redirects to a local path, or to $fallback when $target is not one. Use it
     * for a target read from the request. See Response::localRedirect().
     */
    protected function localRedirect(mixed $target, string $fallback = '/', int $status = 302): Response
    {
        return Response::localRedirect($target, $fallback, $status);
    }

    /**
     * Returns an abort response with the given status code and body.
     */
    protected function abort(int $status, string $body = ''): Response
    {
        return Response::html($body, $status);
    }

    /**
     * Returns an abort JSON response with the given status code.
     *
     * @param array<mixed> $payload
     */
    protected function abortJson(int $status, array $payload): Response
    {
        return Response::json($payload, $status);
    }

    /**
     * Validate $data against the form and return its ErrorBag, which holds no errors on success.
     *
     * @param array<string, mixed> $data
     *
     * @throws ValidationException when a field fails (a 422 unless caught)
     */
    protected function validate(FormValidator $form, array $data): ErrorBag
    {
        return $form->validateOrFail($data);
    }
}
