<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Middleware that enforces HTTPS by permanently redirecting plain-HTTP requests.
 *
 * When a request arrives over plain HTTP (isSecure() === false) this middleware
 * short-circuits the pipeline and returns a 308 Permanent Redirect pointing at
 * the HTTPS version of the same URL. Requests that are already secure pass
 * straight through to the next middleware.
 *
 * 308 (Permanent Redirect) is used instead of 301 because 308 guarantees the
 * original HTTP method and body are preserved on the redirect, which matters
 * for POST/PUT/PATCH/DELETE forms and AJAX calls.
 *
 * Port rewriting: if the incoming HTTP request arrives on the standard HTTP
 * port (80) the redirect strips the port so the HTTPS URL is clean. Requests
 * on non-standard ports keep their port (useful for local dev on e.g. 8080).
 *
 * Usage:
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new ForceHttpsMiddleware())
 *       ->build();
 *
 * Tip: place ForceHttpsMiddleware early in the pipeline (before any
 * authentication or CSRF middleware) so HTTP requests are redirected before
 * any meaningful work is done.
 */
final class ForceHttpsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if ($request->uri()->isSecure()) {
            /** @var Response */
            return $next($request);
        }

        return Response::redirect($this->buildHttpsUrl($request->uri()->full()), 308);
    }

    /**
     * Rewrite an HTTP URI to its HTTPS equivalent.
     *
     * - Replaces the "http://" scheme with "https://".
     * - Strips the default HTTP port (:80) when present so the HTTPS URL is clean.
     */
    private function buildHttpsUrl(string $uri): string
    {
        if (!str_starts_with($uri, 'http://')) {
            return $uri;
        }

        $https = 'https://' . substr($uri, strlen('http://'));

        // Strip the default HTTP port from the authority component.
        // "https://example.com:80/path" -> "https://example.com/path"
        // "https://example.com:80"      -> "https://example.com"
        return preg_replace('#^(https://[^/:]+):80(?=(?:[/?\#]|$))#', '$1', $https) ?? $https;
    }
}
