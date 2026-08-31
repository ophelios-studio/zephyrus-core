<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function array_map;
use function array_values;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function substr_count;
use function strtolower;
use function substr;
use function trim;

/**
 * Enforces an allowlist of accepted hosts to mitigate host header abuse.
 *
 * The value judged is $request->uri()->host(), which is the host every other
 * consumer resolves against (links, redirects, cookie domains, baseUrl()). See
 * resolveRequestHost() for why the raw Host header is deliberately not read.
 *
 * When allowlist is empty, all hosts are accepted.
 * Each configured host may be:
 * - exact: "example.com"
 * - wildcard subdomain: "*.example.com"
 */
final class AllowedHostsMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(array $allowedHosts)
    {
        $this->allowedHosts = array_values(array_map(
            static fn (string $host): string => self::normalizeHost($host),
            $allowedHosts,
        ));
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->allowedHosts === []) {
            /** @var Response */
            return $next($request);
        }

        $requestHost = $this->resolveRequestHost($request);
        if ($requestHost === null || !$this->isAllowed($requestHost)) {
            return Response::json(['error' => 'Invalid Host header.'], 400);
        }

        /** @var Response */
        return $next($request);
    }

    /**
     * The host to judge is uri()->host(), and only that.
     *
     * WHY NOT THE RAW HOST HEADER. It used to be read first, with the URI as a
     * fallback, and the two can name different hosts: Request::fromGlobals lets
     * a trusted X-Forwarded-Host decide uri()->host(), while the raw header
     * still carries whatever the peer sent. Every downstream consumer, link
     * generation, redirects, cookie domains and baseUrl(), reads the URI, so the
     * allowlist was vetting a value nothing else used. An attacker only had to
     * send an allowed Host header to get the request served with a URI pointing
     * somewhere else.
     *
     * Disagreement between the two is NOT treated as an attack, because it is
     * the normal shape of a reverse-proxy deployment: the proxy rewrites Host to
     * an internal name and forwards the public one. Request::fromGlobals already
     * gates that header on the trusted-proxy allowlist, so an untrusted peer
     * cannot move uri()->host() at all, and refusing on disagreement would only
     * break the topologies this middleware exists to protect.
     */
    private function resolveRequestHost(Request $request): ?string
    {
        $host = $request->uri()->host();
        if (trim($host) === '') {
            return null;
        }

        return self::normalizeHost($host);
    }

    private function isAllowed(string $requestHost): bool
    {
        foreach ($this->allowedHosts as $allowedHost) {
            if ($allowedHost === $requestHost) {
                return true;
            }

            if (!str_starts_with($allowedHost, '*.')) {
                continue;
            }

            $suffix = substr($allowedHost, 1);
            if (!str_ends_with($requestHost, $suffix)) {
                continue;
            }

            $prefix = substr($requestHost, 0, -strlen($suffix));
            if ($prefix !== '') {
                return true;
            }
        }

        return false;
    }

    private static function normalizeHost(string $host): string
    {
        $normalized = strtolower(trim($host));
        if (str_ends_with($normalized, '.')) {
            $normalized = substr($normalized, 0, -1);
        }

        if (str_starts_with($normalized, '[') && str_contains($normalized, ']')) {
            $closingBracket = strpos($normalized, ']');
            if ($closingBracket !== false) {
                return substr($normalized, 1, $closingBracket - 1);
            }
        }

        $colonPosition = strpos($normalized, ':');
        if ($colonPosition !== false && substr_count($normalized, ':') === 1) {
            $normalized = substr($normalized, 0, $colonPosition);
        }

        return $normalized;
    }
}
