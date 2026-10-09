<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function explode;
use function filter_var;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function substr_count;

/**
 * Enforces an allowlist of accepted hosts to mitigate host header abuse.
 *
 * The host judged is the one the request URL spells, see Uri::authority().
 * Each configured host may be:
 * - exact: "example.com", "2001:db8::1" or "[2001:db8::1]" for an IPv6 literal
 * - wildcard subdomain: "*.example.com"
 *
 * When allowlist is empty, all hosts are accepted.
 *
 * allows() applies the same syntax and matching for callers that decide a host
 * outside a request, so an application never copies the rules.
 */
final class AllowedHostsMiddleware implements MiddlewareInterface
{
    /** The only port a host may carry: one to five digits, nothing else. */
    private const PORT_PATTERN = '/^\d{1,5}$/D';

    /** An entry carrying a port, which the matcher would drop: "host:port" or "[v6]:port". */
    private const ENTRY_PORT_PATTERN = '/^(\[[^\]]*\]|[^:\[\]]+):\d{1,5}$/D';

    /**
     * One label: letters, digits, hyphens and underscores (Docker service names
     * use the last), no leading or trailing hyphen. None of them can split an
     * authority, so accepting them is safe.
     */
    private const LABEL_PATTERN = '/^(?!-)[a-z0-9_-]{1,63}(?<!-)$/D';

    private const MAX_NAME_LENGTH = 253;

    /** A scheme before "://", which an entry must not carry. */
    private const SCHEME_PATTERN = '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#';

    /** @var list<string> */
    private array $allowedHosts;

    /**
     * @param list<string> $allowedHosts
     * @throws InvalidArgumentException When an entry is not a host name, an IP
     *   literal or a wildcard over a name. Failing at boot beats an entry that
     *   silently matches nothing.
     */
    public function __construct(array $allowedHosts)
    {
        $normalized = [];
        foreach ($allowedHosts as $entry) {
            $reason = self::invalidEntryReason($entry);
            $host = self::normalizeEntry($entry);
            if ($reason !== null || $host === null) {
                throw new InvalidArgumentException(sprintf(
                    'Allowed host "%s": %s.',
                    $entry,
                    $reason ?? 'not a usable host',
                ));
            }

            $normalized[] = $host;
        }

        $this->allowedHosts = $normalized;
    }

    /**
     * Why a configured entry is unusable, or null when it is usable. The
     * constructor and boot-time configuration validation both call it, so they
     * accept and refuse exactly the same entries.
     */
    public static function invalidEntryReason(string $entry): ?string
    {
        if ($entry === '') {
            return 'an empty entry matches nothing, remove it';
        }

        if ($entry === '*') {
            return "'*' is not a host name, use an empty list to allow every host";
        }

        if (preg_match(self::SCHEME_PATTERN, $entry) === 1) {
            return 'drop the scheme, list the host only, such as example.com';
        }

        if (str_contains($entry, ',')) {
            return 'one entry per list item, a comma-separated value is not accepted';
        }

        if (trim($entry) !== $entry) {
            return 'remove the spaces around the host name';
        }

        if (preg_match(self::ENTRY_PORT_PATTERN, $entry, $portMatch) === 1
            && self::invalidEntryReason($portMatch[1]) === null
        ) {
            return sprintf('ports are not matched: list "%s" only', $portMatch[1]);
        }

        if (preg_match('/[^\x00-\x7F]/', $entry) === 1) {
            return 'use the punycode form of an internationalised name, such as xn--bcher-kva.example';
        }

        if (self::normalizeEntry($entry) === null) {
            return 'must be a host name, an IP literal, or a wildcard over a host name such as *.example.com';
        }

        return null;
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->allowedHosts === []) {
            /** @var Response */
            return $next($request);
        }

        if (!$this->allows($request->uri()->authority())) {
            return Response::json(['error' => 'Invalid Host header.'], 400);
        }

        /** @var Response */
        return $next($request);
    }

    /**
     * Whether a raw host, as a client would send it, passes this allowlist. An
     * empty allowlist accepts every host, so do not use allows() to vet links without one.
     */
    public function allows(string $host): bool
    {
        if ($this->allowedHosts === []) {
            return true;
        }

        $normalized = self::normalizeHost(self::authorityOf($host));

        return $normalized !== null && $this->isAllowed($normalized);
    }

    /**
     * Cuts a bare host at the first "/", "?" or "#", where an authority ends.
     */
    private static function authorityOf(string $text): string
    {
        return substr($text, 0, strcspn($text, '/?#'));
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

    /**
     * Entries are written by an operator, not sent by a client, so a bare IPv6
     * literal is accepted here. Only a Host value has to bracket it.
     */
    private static function normalizeEntry(string $entry): ?string
    {
        if (substr_count($entry, ':') > 1 && !str_starts_with($entry, '[')) {
            $literal = strtolower($entry);

            return self::isIpv6($literal) ? $literal : null;
        }

        $wildcard = str_starts_with($entry, '*.');
        $host = self::normalizeHost($wildcard ? substr($entry, 2) : $entry);
        if ($host === null) {
            return null;
        }

        if (!$wildcard) {
            return $host;
        }

        return self::isIpLiteral($host) ? null : '*.' . $host;
    }

    /**
     * Reduces a raw host to its canonical form, or returns null when it is not a
     * host name, an IPv4 literal or a bracketed IPv6 literal, with at most a
     * numeric port. Lowercased, one trailing dot removed.
     */
    private static function normalizeHost(string $host): ?string
    {
        $normalized = strtolower($host);

        if (str_starts_with($normalized, '[')) {
            $closingBracket = strpos($normalized, ']');
            if ($closingBracket === false) {
                return null;
            }

            $address = substr($normalized, 1, $closingBracket - 1);
            $suffix = substr($normalized, $closingBracket + 1);
            $hasValidPort = str_starts_with($suffix, ':') && preg_match(self::PORT_PATTERN, substr($suffix, 1)) === 1;
            if (($suffix !== '' && !$hasValidPort) || !self::isIpv6($address)) {
                return null;
            }

            return $address;
        }

        $parts = explode(':', $normalized, 3);
        if (count($parts) > 2) {
            return null;
        }

        if (count($parts) === 2) {
            if (preg_match(self::PORT_PATTERN, $parts[1]) !== 1) {
                return null;
            }

            $normalized = $parts[0];
        }

        if (str_ends_with($normalized, '.')) {
            $normalized = substr($normalized, 0, -1);
        }

        if (self::isIpv4($normalized)) {
            return $normalized;
        }

        return self::isDnsName($normalized) ? $normalized : null;
    }

    private static function isDnsName(string $name): bool
    {
        if ($name === '' || strlen($name) > self::MAX_NAME_LENGTH) {
            return false;
        }

        foreach (explode('.', $name) as $label) {
            if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function isIpLiteral(string $host): bool
    {
        return self::isIpv4($host) || self::isIpv6($host);
    }

    private static function isIpv4(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }

    private static function isIpv6(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }
}
