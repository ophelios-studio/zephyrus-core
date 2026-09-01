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
     * ## The root cause is now fixed
     *
     * uri()->isSecure() alone used not to be enough. Uri::__construct fell back
     * to "http" and "localhost" when parse_url() returned false, and it did so
     * silently, so any malformed authority collapsed the whole URI and made an
     * HTTPS request look like a plain one. HSTS was dropped from a response that
     * had every reason to carry it, with nothing logged and nothing thrown.
     * Request::fromGlobals composes the URL as scheme . "://" . host . target
     * with host taken from the Host header, so an attacker-chosen Host such as
     * "example.com:port" was enough to defeat the parse on a real HTTPS request.
     *
     * Uri::decompose() now preserves the scheme it was actually given instead of
     * inventing one, so uri()->isSecure() answers this correctly on its own.
     *
     * ## Why the raw-string check STAYS anyway
     *
     * It is redundant by construction today, and it is kept deliberately. It
     * costs one string comparison; it can only ever ADD the header, never remove
     * it, and an over-emitted HSTS is inert because browsers honour it only over
     * TLS; and the thing it protects, an omitted security header, fails
     * SILENTLY, which is the failure mode worth paying a byte to avoid. Deleting
     * it would leave this middleware's most important guarantee resting entirely
     * on a parsing detail in another class, with no local evidence that it
     * holds.
     */
    private static function isSecure(Request $request): bool
    {
        if ($request->uri()->isSecure()) {
            return true;
        }

        return str_starts_with(strtolower($request->uri()->full()), 'https://');
    }
}
