<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Http\Uri;

/**
 * Redirects plain-HTTP requests to their HTTPS URL with a 308; secure requests pass through.
 *
 * 308 keeps the method and body, so POST and form submissions survive the redirect.
 * Port 80 is dropped from the target, any other port is kept.
 *
 * Usage:
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new ForceHttpsMiddleware())
 *       ->build();
 *
 * Register it before authentication and CSRF middleware.
 */
final class ForceHttpsMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if ($request->uri()->isSecure()) {
            /** @var Response */
            return $next($request);
        }

        return Response::redirect($this->httpsUrl($request->uri()), 308);
    }

    /** Rebuilt from parts: userinfo and fragment are never copied. */
    private function httpsUrl(Uri $uri): string
    {
        $port  = $uri->port();
        $query = $uri->queryString();

        return 'https://'
            . $uri->host()
            . ($port === null || $port === 80 ? '' : ':' . $port)
            . $uri->path()
            . ($query === '' ? '' : '?' . $query);
    }
}
