<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function array_map;
use function array_values;
use function parse_url;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function substr_count;
use function strtolower;
use function substr;
use function trim;

/**
 * Enforces an allowlist of accepted Host values to mitigate host header abuse.
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

    private function resolveRequestHost(Request $request): ?string
    {
        $rawHost = $request->header('host');
        if (!is_string($rawHost) || trim($rawHost) === '') {
            $rawHost = parse_url($request->uri, PHP_URL_HOST);
        }

        if (!is_string($rawHost) || trim($rawHost) === '') {
            return null;
        }

        return self::normalizeHost($rawHost);
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
