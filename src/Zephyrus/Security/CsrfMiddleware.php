<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Closure;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function preg_match;
use function str_contains;
use function strtolower;

/**
 * Synchronizer-token CSRF protection.
 *
 * GET, HEAD, OPTIONS and TRACE pass through. Every other method must carry a valid
 * token, or the middleware refuses the request with a 403 without calling the next handler.
 *
 * The token is read from the body field CsrfConfig::bodyField (default "_csrf_token");
 * when that field is absent, from the header CsrfConfig::headerName (default "X-CSRF-Token").
 * Validation is delegated to the CsrfTokenManagerInterface.
 *
 * Paths matching a pattern in CsrfConfig::excludedPathPatterns skip the check.
 *
 * Requests that matched no route are never refused: the 404 or 405 is answered
 * instead, since no handler runs and no state changes.
 *
 * Usage:
 *
 *   $csrf = new SessionCsrfTokenManager($session);
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new CsrfMiddleware($csrf, CsrfConfig::fromSecurityConfig($configuration->security)))
 *       ->build();
 *
 *   // In a template, embed the token:
 *   <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf->getToken()) ?>">
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

    private const FORM_REFUSAL = 'This form could not be verified. Your session may have expired or not been saved. '
        . 'Reload the page and try again.';

    /**
     * @param CsrfConfig $config Built by fromSecurityConfig() to read csrfEnabled and csrfExceptions: a bare
     *        CsrfConfig ignores security.csrf.exceptions.
     * @param (Closure(Request, CsrfFailure): ?Response)|null $onFailure Answers a refusal; returning null keeps the
     *        default response: a 403 as text/plain when Accept lists text/html, JSON otherwise. It receives
     *        attacker-controlled input: only answer the refusal, never replay the request or act on the account.
     */
    public function __construct(
        private readonly CsrfTokenManagerInterface $tokenManager,
        private readonly CsrfConfig $config = new CsrfConfig(),
        private readonly ?Closure $onFailure = null,
    ) {
    }

    /** The configuration this middleware enforces. */
    public function config(): CsrfConfig
    {
        return $this->config;
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->config->enabled
            && !$this->isUnmatchedRoute($request)
            && !in_array($request->method, self::SAFE_METHODS, true)
            && !$this->isPathExcluded($request)
        ) {
            $failure = $this->tokenFailure($request);

            if ($failure !== null) {
                return $this->refuse($request, $failure);
            }
        }

        return $next($request);
    }

    /**
     * Answers a refusal: the $onFailure response if it returns one, otherwise a 403 as
     * text/plain when Accept lists text/html, or as JSON {"error": ...} for every other client.
     */
    private function refuse(Request $request, CsrfFailure $failure): Response
    {
        if ($this->onFailure !== null) {
            $response = ($this->onFailure)($request, $failure);

            if ($response !== null) {
                return $response;
            }
        }

        if (str_contains(strtolower($request->headers()->get('Accept') ?? ''), 'text/html')) {
            return Response::text(self::FORM_REFUSAL, 403);
        }

        return Response::json(
            ['error' => 'Invalid or missing CSRF token.'],
            403,
        );
    }

    /**
     * Returns true when HttpKernel flagged that no route matched, so nothing is protected.
     */
    private function isUnmatchedRoute(Request $request): bool
    {
        return $request->attribute(Request::ATTRIBUTE_UNMATCHED_ROUTE) === true;
    }

    /**
     * Returns true when the request path matches any configured exclusion pattern.
     */
    private function isPathExcluded(Request $request): bool
    {
        if ($this->config->excludedPathPatterns === []) {
            return false;
        }

        // Match the canonical path the router dispatches, never the raw URI path.
        $path = $request->path();
        foreach ($this->config->excludedPathPatterns as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the submitted token (body field first, then header) and validates it.
     *
     * @return CsrfFailure|null null when the token is valid
     */
    private function tokenFailure(Request $request): ?CsrfFailure
    {
        $submitted = $request->body()->get($this->config->bodyField)
            ?? $request->headers()->get($this->config->headerName);

        if ($submitted === null || !is_string($submitted) || $submitted === '') {
            return CsrfFailure::TokenMissing;
        }

        return $this->tokenManager->isTokenValid($submitted) ? null : CsrfFailure::TokenInvalid;
    }
}
