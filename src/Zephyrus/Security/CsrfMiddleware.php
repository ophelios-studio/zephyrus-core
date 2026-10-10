<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Closure;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function preg_match;

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
 *   $config = CsrfConfig::fromSecurityConfig($securityConfig);
 *   $mw = new CsrfMiddleware($sessionManager, $config);
 *
 * fromSecurityConfig() reads csrfEnabled and csrfExceptions from the
 * application's security section. See CsrfConfig for the pattern rules.
 *
 * Unmatched routes
 * ----------------
 * A request that matched no route is never gated. When HttpKernel finds no
 * route it flags the request with Request::ATTRIBUTE_UNMATCHED_ROUTE, and this
 * middleware passes it straight through so the response is the 404 or 405 it
 * should be, not a 403. There is no resource to protect when nothing matched,
 * and a security-shaped error there hides an ordinary wrong-URL bug. The error
 * response still travels through the rest of the global pipeline, so it keeps
 * its security headers.
 *
 * The token is validated by the injected CsrfTokenManagerInterface using a
 * constant-time comparison; the middleware itself does not generate tokens.
 *
 * A refused request is answered by the optional $onFailure closure, called as
 * ($onFailure)(Request, CsrfFailure) and returning the Response to send.
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
        private readonly ?Closure $onFailure = null,
    ) {
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
     * Answers a refused request with the application's failure callback, or
     * with the default 403 when none was given.
     */
    private function refuse(Request $request, CsrfFailure $failure): Response
    {
        if ($this->onFailure !== null) {
            return ($this->onFailure)($request, $failure);
        }

        return Response::json(
            ['error' => 'Invalid or missing CSRF token.'],
            403,
        );
    }

    /**
     * Returns true when no route matched, so this request is heading for a 404
     * or a 405 and there is nothing to protect.
     *
     * CSRF defends a RESOURCE against a state change triggered by a third-party
     * site. When routing found nothing, no handler runs and no state changes,
     * so validating a token guards nothing. Answering 403 there would replace a
     * plain "that URL does not exist" with a security-shaped error, and send
     * whoever debugs it hunting a token or signature problem when the real
     * fault is the URL: a stale webhook or a renamed endpoint is the common
     * case. It also buys no secrecy, because GET is a safe method and already
     * reveals the same 404.
     *
     * The 404 or 405 still leaves through the rest of the global pipeline, so
     * it keeps every security header a matched response would carry.
     */
    private function isUnmatchedRoute(Request $request): bool
    {
        return $request->attribute(Request::ATTRIBUTE_UNMATCHED_ROUTE) === true;
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

        // The CANONICAL path, never uri()->path(). Keying an exclusion on the
        // raw path was a live bypass: with an unanchored pattern such as
        // #/webhooks/#, a POST to //webhooks/account/close matched the
        // exclusion, skipped the token check, and the router dispatched the
        // protected /account/close. CsrfConfig now refuses that unanchored
        // shape outright, so this is the second of two independent guards.
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
     *
     * @return CsrfFailure|null null when the token is valid
     */
    private function tokenFailure(Request $request): ?CsrfFailure
    {
        // Body field takes precedence over the header.
        $submitted = $request->body()->get($this->config->bodyField)
            ?? $request->headers()->get($this->config->headerName);

        if ($submitted === null || !is_string($submitted) || $submitted === '') {
            return CsrfFailure::TokenMissing;
        }

        return $this->tokenManager->isTokenValid($submitted) ? null : CsrfFailure::TokenInvalid;
    }
}
