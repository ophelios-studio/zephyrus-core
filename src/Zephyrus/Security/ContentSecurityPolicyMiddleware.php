<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Middleware that appends a Content Security Policy header on responses.
 *
 * - Accepts either a prebuilt ContentSecurityPolicy object or a raw header string.
 * - Skips header emission when the policy resolves to an empty string.
 * - Supports report-only mode via Content-Security-Policy-Report-Only.
 */
final readonly class ContentSecurityPolicyMiddleware implements MiddlewareInterface
{
    private string $policy;

    public function __construct(ContentSecurityPolicy|string $policy, private bool $reportOnly = false)
    {
        $this->policy = $policy instanceof ContentSecurityPolicy
            ? $policy->toHeaderValue()
            : trim($policy);
    }

    public function process(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($this->policy === '') {
            return $response;
        }

        return $response->withHeader($this->headerName(), $this->policy);
    }

    private function headerName(): string
    {
        return $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }
}
