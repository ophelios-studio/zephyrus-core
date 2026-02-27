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
 *   1. Request body field (default: "_csrf_token", via CsrfConfig::bodyField).
 *   2. Request header (default: "X-CSRF-Token", via CsrfConfig::headerName).
 *
 * Both sources are checked so that traditional HTML forms and AJAX/fetch
 * clients can both authenticate their requests with the same token.
 *
 * Path exclusions
 * ---------------
 * Paths whose URI matches any PCRE pattern in CsrfConfig::excludedPathPatterns
 * are exempt from CSRF checks entirely.  This is useful for webhook receivers,
 * public API endpoints protected by other means (bearer tokens, HMAC, …), or
 * health-check routes.
 *
 *   $config = CsrfConfig::fromArray([
 *       'excluded_path_patterns' => ['#^/webhooks/#', '#^/api/public#'],
 *   ]);
 *   $mw = new CsrfMiddleware($sessionManager, $config);
 *
 * The token is validated by the injected CsrfTokenManagerInterface using a
 * constant-time comparison; the middleware itself does not generate tokens.
 *
 * Usage:
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new CsrfMiddleware($sessionManager))
 *       ->build();
 *
 *   // In a template, embed the token:
 *   <input type="hidden" name="_csrf_token" value="<?= $manager->getToken() ?>">
 *
 *   // Or via AJAX header:
 *   fetch('/api/action', {
 *       method: 'POST',
 *       headers: { 'X-CSRF-Token': csrfToken },
 *   });
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** HTTP methods that do not mutate server state and are exempt from CSRF checks. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public function __construct(
        private readonly CsrfTokenManagerInterface $tokenManager,
        private readonly CsrfConfig $config = new CsrfConfig(),
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if (in_array($request->method, self::SAFE_METHODS, true)) {
            /** @var Response */
            return $next($request);
        }

        if ($this->isPathExcluded($request)) {
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
     * Returns true when the request path matches any of the configured
     * exclusion patterns and should bypass CSRF validation.
     */
    private function isPathExcluded(Request $request): bool
    {
        if ($this->config->excludedPathPatterns === []) {
            return false;
        }

        $path = $request->path();
        foreach ($this->config->excludedPathPatterns as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the submitted CSRF token from the request (body or header) and
     * delegate validation to the injected token manager.
     */
    private function isTokenValid(Request $request): bool
    {
        // Body field takes precedence over the header.
        $submitted = $request->input($this->config->bodyField)
            ?? $request->header($this->config->headerName);

        if ($submitted === null || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return $this->tokenManager->isTokenValid($submitted);
    }
}
