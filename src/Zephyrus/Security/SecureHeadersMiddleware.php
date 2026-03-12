<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Middleware that injects HTTP security response headers on every response.
 *
 * Behaviour:
 *   - Calls $next to obtain the inner response first.
 *   - Appends each configured security header via Response::withHeader().
 *   - Headers with an empty string value in the config are skipped (not emitted).
 *   - Strict-Transport-Security is only emitted on HTTPS requests ($request->uri()->isSecure()).
 *
 * Usage:
 *
 *   $config = SecureHeadersConfig::defaults();          // sensible defaults
 *   // — or —
 *   $config = SecureHeadersConfig::fromArray([
 *       'hsts_max_age'             => 31_536_000,
 *       'hsts_include_subdomains'  => true,
 *       'csp'                      => "default-src 'self'",
 *   ]);
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new SecureHeadersMiddleware($config))
 *       ->build();
 */
final class SecureHeadersMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SecureHeadersConfig $config)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $this->applyHeaders($request, $response);
    }

    private function applyHeaders(Request $request, Response $response): Response
    {
        if ($this->config->xFrameOptions !== '') {
            $response = $response->withHeader('X-Frame-Options', $this->config->xFrameOptions);
        }

        if ($this->config->xContentTypeOptions !== '') {
            $response = $response->withHeader('X-Content-Type-Options', $this->config->xContentTypeOptions);
        }

        if ($this->config->referrerPolicy !== '') {
            $response = $response->withHeader('Referrer-Policy', $this->config->referrerPolicy);
        }

        if ($this->config->xssProtection !== '') {
            $response = $response->withHeader('X-XSS-Protection', $this->config->xssProtection);
        }

        if ($this->config->csp !== '') {
            $response = $response->withHeader('Content-Security-Policy', $this->config->csp);
        }

        if ($this->config->permissionsPolicy !== '') {
            $response = $response->withHeader('Permissions-Policy', $this->config->permissionsPolicy);
        }

        $hstsValue = $this->config->hstsHeaderValue();

        if ($hstsValue !== '' && $request->uri()->isSecure()) {
            $response = $response->withHeader('Strict-Transport-Security', $hstsValue);
        }

        return $response;
    }
}
