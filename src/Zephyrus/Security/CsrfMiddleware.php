<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Middleware that enforces synchronizer-token CSRF protection.
 *
 * Safe HTTP methods (GET, HEAD, OPTIONS, TRACE) pass through unchanged.
 * State-changing methods (POST, PUT, PATCH, DELETE) must supply a valid CSRF
 * token or the middleware returns a 403 Forbidden response without calling
 * the inner handler.
 *
 * Token lookup order (first match wins):
 *   1. Request body field (default: "_csrf_token").
 *   2. Request header (default: "X-CSRF-Token").
 *
 * Both sources are checked so that traditional HTML forms and AJAX/fetch
 * clients can both authenticate their requests with the same token.
 *
 * The token is validated by the injected CsrfTokenManagerInterface using a
 * constant-time comparison; the middleware itself does not generate tokens.
 *
 * Usage:
 *
 *   // Inject a session-backed manager (Phase 6):
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new CsrfMiddleware($sessionManager))
 *       ->build();
 *
 *   // In a Blade/Twig/PHP template, embed the token:
 *   <input type="hidden" name="_csrf_token" value="<?= $manager->getToken() ?>">
 *
 *   // Or via AJAX header:
 *   fetch('/api/action', {
 *       method: 'POST',
 *       headers: { 'X-CSRF-Token': csrfToken },
 *   });
 *
 * Customising the token field / header name:
 *
 *   new CsrfMiddleware($manager, bodyField: '_token', headerName: 'X-XSRF-TOKEN')
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** HTTP methods that do not mutate server state and are exempt from CSRF checks. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(
        private readonly CsrfTokenManagerInterface $tokenManager,
        private readonly string $bodyField  = '_csrf_token',
        private readonly string $headerName = 'X-CSRF-Token',
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method, self::SAFE_METHODS, true)) {
            /** @var Response */
            return $next($request);
        }

        if (!$this->isTokenValid($request)) {
            return Response::json(
                ['error' => 'Invalid or missing CSRF token.'],
                403,
            );
        }

        /** @var Response */
        return $next($request);
    }

    /**
     * Resolve the submitted CSRF token from the request (body or header) and
     * delegate validation to the injected token manager.
     */
    private function isTokenValid(Request $request): bool
    {
        // Body field takes precedence over the header.
        $submitted = $request->input($this->bodyField)
            ?? $request->header($this->headerName);

        if ($submitted === null || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return $this->tokenManager->isTokenValid($submitted);
    }
}
