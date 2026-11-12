<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Exceptions\MessageValue;

use function explode;
use function filter_var;
use function str_ends_with;
use function str_starts_with;
use function strtolower;
use function substr;
use function substr_count;

/**
 * Refuses, with a 400, a request whose host is not in the allowlist.
 *
 * The host judged is the one the request URL spells, see Uri::authority(). Each entry is either:
 * - exact: "example.com", "2001:db8::1" or "[2001:db8::1]" for an IPv6 literal
 * - wildcard: "*.example.com", matching subdomains at any depth, never the bare domain
 *
 * An entry may pin a port ("example.com:8443", "[2001:db8::1]:8080", "*.example.com:443"): it then
 * admits that port only, a URL without a port standing for its scheme's default (80 or 443). An
 * entry without a port admits any port. Pin the port in production when the application builds
 * absolute URLs from the request. When ForceHttpsMiddleware runs after this one, also list the
 * host on port 80, which then only reaches the redirect: ["example.com:443", "example.com:80"].
 * An IPv6 literal pins a port only in brackets: "::1:8080" is an address, admitted on any port.
 *
 * Entries with a scheme, a port outside 1-65535, a comma, surrounding spaces, non-ASCII characters,
 * a lone "*" or a wildcard IP literal are refused at construction. An empty allowlist accepts every host.
 *
 * allows() applies the same rules to a host outside a request.
 */
final class AllowedHostsMiddleware implements MiddlewareInterface
{
    /** The only port a host may carry: one to five digits, nothing else. */
    private const PORT_PATTERN = '/^\d{1,5}$/D';

    /** An entry pinned to a port: "host:port", "[v6]:port" or "*.host:port". */
    private const ENTRY_PORT_PATTERN = '/^(\[[^\]]*\]|[^:\[\]]+):(\d+)$/D';

    /** The port a URL without one stands for. */
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** One label of letters, digits, hyphens and underscores (Docker service names); none can split an authority. */
    private const LABEL_PATTERN = '/^(?!-)[a-z0-9_-]{1,63}(?<!-)$/D';

    private const MAX_NAME_LENGTH = 253;

    /** A scheme before "://", which an entry must not carry. */
    private const SCHEME_PATTERN = '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#';

    /** @var list<array{string, ?int}> The normalised host and pinned port of each entry. */
    private array $entries = [];

    /**
     * @param list<string> $allowedHosts
     * @throws InvalidArgumentException When an entry is unusable, see invalidEntryReason().
     */
    public function __construct(array $allowedHosts)
    {
        foreach ($allowedHosts as $entry) {
            $reason = self::invalidEntryReason($entry);
            $parsed = self::parseEntry($entry);
            if ($reason !== null || $parsed === null) {
                throw new InvalidArgumentException(sprintf(
                    'Allowed host %s: %s.',
                    MessageValue::quote($entry),
                    $reason ?? 'not a usable host',
                ));
            }

            $this->entries[] = $parsed;
        }
    }

    /** Why a configured entry is unusable, or null. Shared with boot-time config validation. */
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
            return 'use list items, not a comma inside one item';
        }

        if (trim($entry) !== $entry) {
            return 'remove the spaces around the host name';
        }

        if (preg_match(self::ENTRY_PORT_PATTERN, $entry, $portMatch) === 1
            && self::invalidEntryReason($portMatch[1]) === null
            && self::portNumber($portMatch[2]) === null
        ) {
            return 'the port must be a number from 1 to 65535 without a leading zero';
        }

        if (preg_match('/[^\x00-\x7F]/', $entry) === 1) {
            return 'use the punycode form of an internationalised name, such as xn--bcher-kva.example';
        }

        if (self::parseEntry($entry) === null) {
            return 'must be a host name, an IP literal, or a wildcard over a host name such as *.example.com';
        }

        return null;
    }

    /**
     * The allowlist in its normalised form: lowercased, trailing dot removed, IPv6 brackets removed
     * unless the entry pins a port, as in "[2001:db8::1]:8080".
     *
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $hosts = [];
        foreach ($this->entries as [$host, $port]) {
            $hosts[] = match (true) {
                $port === null => $host,
                self::isIpv6($host) => '[' . $host . ']:' . $port,
                default => $host . ':' . $port,
            };
        }

        return $hosts;
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->entries === []) {
            /** @var Response */
            return $next($request);
        }

        $uri = $request->uri();
        if (!$this->admits($uri->authority(), self::DEFAULT_PORTS[$uri->scheme()] ?? null)) {
            return Response::json(['error' => 'Invalid Host header.'], 400);
        }

        /** @var Response */
        return $next($request);
    }

    /**
     * Whether a host passes. An empty allowlist returns true for any input.
     *
     * A host without a port matches only the entries without one. To judge a URL, pass its host
     * with the effective port, such as `$host . ':' . ($port ?? 443)` for https.
     */
    public function allows(string $host): bool
    {
        return $this->entries === [] || $this->admits($host, null);
    }

    /**
     * @param int|null $defaultPort The port a host without one stands for; null matches it to unpinned entries only.
     */
    private function admits(string $host, ?int $defaultPort): bool
    {
        $parsed = self::parseHost(self::authorityOf($host));
        if ($parsed === null) {
            return false;
        }

        [$requestHost, $requestPort] = $parsed;
        $requestPort ??= $defaultPort;
        foreach ($this->entries as [$allowedHost, $allowedPort]) {
            if (($allowedPort === null || $allowedPort === $requestPort) && self::matchesHost($allowedHost, $requestHost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cuts a bare host at the first "/", "?" or "#", where an authority ends.
     */
    private static function authorityOf(string $text): string
    {
        return substr($text, 0, strcspn($text, '/?#'));
    }

    private static function matchesHost(string $allowedHost, string $requestHost): bool
    {
        if ($allowedHost === $requestHost) {
            return true;
        }

        if (!str_starts_with($allowedHost, '*.')) {
            return false;
        }

        $suffix = substr($allowedHost, 1);

        return str_ends_with($requestHost, $suffix) && strlen($requestHost) > strlen($suffix);
    }

    /**
     * The normalised host and pinned port of an entry, or null when it is unusable.
     *
     * @return array{string, ?int}|null
     */
    private static function parseEntry(string $entry): ?array
    {
        $port = null;
        if (preg_match(self::ENTRY_PORT_PATTERN, $entry, $portMatch) === 1) {
            $port = self::portNumber($portMatch[2]);
            if ($port === null) {
                return null;
            }

            $entry = $portMatch[1];
        }

        $host = self::normalizeEntry($entry);

        return $host === null ? null : [$host, $port];
    }

    /** Operator entries may be bare IPv6 literals; a Host header must bracket them. */
    private static function normalizeEntry(string $entry): ?string
    {
        if (substr_count($entry, ':') > 1 && !str_starts_with($entry, '[')) {
            $literal = strtolower($entry);

            return self::isIpv6($literal) ? $literal : null;
        }

        $wildcard = str_starts_with($entry, '*.');
        $parsed = self::parseHost($wildcard ? substr($entry, 2) : $entry);
        if ($parsed === null || $parsed[1] !== null) {
            return null;
        }

        $host = $parsed[0];
        if (!$wildcard) {
            return $host;
        }

        return self::isIpLiteral($host) ? null : '*.' . $host;
    }

    /** A port from 1 to 65535 written without a leading zero, or null. */
    private static function portNumber(string $port): ?int
    {
        if (preg_match('/^[1-9][0-9]{0,4}$/D', $port) !== 1 || (int) $port > 65535) {
            return null;
        }

        return (int) $port;
    }

    /**
     * Canonical host and port: the host lowercased, one trailing dot removed, brackets removed from
     * an IPv6 literal. Accepts a bracketed IPv6 literal, an IPv4 address or a DNS name, each with an
     * optional port; null for anything else.
     *
     * @return array{string, ?int}|null
     */
    private static function parseHost(string $host): ?array
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

            return [$address, $hasValidPort ? (int) substr($suffix, 1) : null];
        }

        $port = null;
        $parts = explode(':', $normalized, 3);
        if (count($parts) > 2) {
            return null;
        }

        if (count($parts) === 2) {
            if (preg_match(self::PORT_PATTERN, $parts[1]) !== 1) {
                return null;
            }

            $normalized = $parts[0];
            $port = (int) $parts[1];
        }

        if (str_ends_with($normalized, '.')) {
            $normalized = substr($normalized, 0, -1);
        }

        if (self::isIpv4($normalized) || self::isDnsName($normalized)) {
            return [$normalized, $port];
        }

        return null;
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
