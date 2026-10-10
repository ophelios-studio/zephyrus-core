<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Sets the configured security headers on every response that does not already carry them with a
 * non-blank value; a blank value set by a route does not opt out.
 *
 * A header set by the inner response wins, including a looser one. A route's Content-Security-Policy
 * replaces the configured policy whole, it is not merged. Empty config values are not emitted.
 * Strict-Transport-Security is only sent on HTTPS requests.
 *
 *   $config = SecureHeadersConfig::defaults();
 *   // or, with explicit values:
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

    /** Whether the configured csp is set, so it would replace a policy registered outside this middleware. */
    public function hasContentSecurityPolicy(): bool
    {
        return trim($this->config->csp) !== '';
    }

    private function applyHeaders(Request $request, Response $response): Response
    {
        if ($this->config->xFrameOptions !== '') {
            $response = self::withDefault($response, 'X-Frame-Options', $this->config->xFrameOptions);
        }

        if ($this->config->xContentTypeOptions !== '') {
            $response = self::withDefault($response, 'X-Content-Type-Options', $this->config->xContentTypeOptions);
        }

        if ($this->config->referrerPolicy !== '') {
            $response = self::withDefault($response, 'Referrer-Policy', $this->config->referrerPolicy);
        }

        if ($this->config->xssProtection !== '') {
            $response = self::withDefault($response, 'X-XSS-Protection', $this->config->xssProtection);
        }

        if ($this->hasContentSecurityPolicy()) {
            $response = self::withDefault($response, 'Content-Security-Policy', $this->config->csp);
        }

        if ($this->config->permissionsPolicy !== '') {
            $response = self::withDefault($response, 'Permissions-Policy', $this->config->permissionsPolicy);
        }

        $hstsValue = $this->config->hstsHeaderValue();

        if ($hstsValue !== '' && self::isSecure($request)) {
            $response = self::withDefault($response, 'Strict-Transport-Security', $hstsValue);
        }

        return $response;
    }

    private static function withDefault(Response $response, string $name, string $value): Response
    {
        return $response->hasNonBlankHeader($name) ? $response : $response->withHeader($name, $value);
    }

    /**
     * Whether the request is HTTPS. The raw "https://" prefix is checked too, so a malformed Host
     * cannot drop HSTS silently; an extra HSTS header is harmless, browsers only honour it over TLS.
     */
    private static function isSecure(Request $request): bool
    {
        if ($request->uri()->isSecure()) {
            return true;
        }

        return str_starts_with(strtolower($request->uri()->full()), 'https://');
    }
}
