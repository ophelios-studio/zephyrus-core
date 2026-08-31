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
 *   - Strict-Transport-Security is only emitted on HTTPS requests (see isSecure()).
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

        if ($hstsValue !== '' && self::isSecure($request)) {
            $response = $response->withHeader('Strict-Transport-Security', $hstsValue);
        }

        return $response;
    }

    /**
     * Whether the request arrived over HTTPS, asked in a way a parse failure
     * cannot answer wrongly.
     *
     * uri()->isSecure() alone was not enough. Uri::__construct falls back to
     * "http" and "localhost" when parse_url() returns false, and it does so
     * silently, so any malformed authority collapsed the whole URI and made an
     * HTTPS request look like a plain one. HSTS was then dropped from a response
     * that had every reason to carry it, with nothing logged and nothing thrown.
     * Request::fromGlobals composes the URL as scheme . "://" . host . target
     * with host taken from the Host header, so an attacker-chosen Host such as
     * "example.com:port" is enough to defeat the parse on a real HTTPS request.
     *
     * The raw URL string is therefore consulted as well. It still carries the
     * scheme the SAPI reported, because Request only ever rewrites the PATH when
     * it canonicalises. Failing safe here means keeping the header, never
     * dropping it: an over-emitted HSTS on a request that was somehow not HTTPS
     * is ignored by browsers, which only honour it over TLS.
     *
     * The right place to stop the collapse is Uri::__construct, which should not
     * invent an authority it failed to parse. That is a separate change with a
     * much wider blast radius than this middleware.
     */
    private static function isSecure(Request $request): bool
    {
        if ($request->uri()->isSecure()) {
            return true;
        }

        return str_starts_with(strtolower($request->uri()->full()), 'https://');
    }
}
