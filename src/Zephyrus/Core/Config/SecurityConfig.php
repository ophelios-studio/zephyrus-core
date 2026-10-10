<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Http\IpRange;
use Zephyrus\Http\Request;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\CsrfConfig;
use Zephyrus\Security\SecureHeadersConfig;

/**
 * Immutable configuration section for HTTP security behaviour.
 *
 * Preferred nested YAML:
 *   security:
 *     forceHttps: true
 *     allowedHosts: [example.com]
 *     maxBodySize: 2097152
 *     trustedProxies: []
 *     trustedHeaders: [x-forwarded-for, x-forwarded-host, x-forwarded-proto, x-forwarded-port]
 *     headers: { xFrameOptions: DENY, hstsMaxAge: 31536000 }
 *     csrf:
 *       enabled: true
 *       exceptions: []
 *     encryption:
 *       key: !env ENCRYPTION_KEY
 *
 * Flat keys (csrfEnabled, csrfExceptions, encryptionKey) are still accepted at the top level,
 * and every key also accepts snake_case. Any other key, and a setting written under two of its
 * spellings (nested or flat), is refused.
 *
 * Defaults:
 *   - forceHttps:     false. Enable it explicitly in production.
 *   - csrfEnabled:    true. CSRF protection is opt-out.
 *   - csrfExceptions: [] (no excluded paths).
 *   - allowedHosts:   [] (any host). Populate it in production.
 *   - maxBodySize:    2097152 (2 MB). 0 = unlimited. An integer or a string of at most 18 digits, no unit suffix.
 *   - trustedProxies: [] (no proxy trusted, so forwarded headers are ignored).
 *   - trustedHeaders: the X-Forwarded-* family (Request::TRUSTED_HEADERS_DEFAULT). 'forwarded',
 *                     'x-real-ip', 'cf-connecting-ip' and 'x-client-ip' are opt-in. [] reads none.
 *   - headers:        SecureHeadersConfig::defaults(), read by fromArray() from the headers section.
 *   - encryptionKey:  null. Required for Cryptography usage.
 *
 * Validation:
 *   - maxBodySize: 0 or greater, an integer or a string of at most 18 digits.
 *   - allowedHosts: each entry a non-empty host name without a scheme, such as example.com.
 *     allowedHosts and trustedProxies also accept one comma-separated string (typically from !env).
 *     A string naming nothing is an empty
 *     trustedProxies, but is REJECTED for allowedHosts, where an empty list allows every host.
 *     A declared null allowedHosts (an unset !env without default) is REJECTED for the same reason.
 *   - csrf, encryption: a mapping or null; any other value is REJECTED without being shown.
 *   - encryptionKey: a string or null; any other value is REJECTED without being shown.
 *   - csrfExceptions: each entry a non-empty string.
 *   - forceHttps, csrfEnabled, csrfAutoHtml: a boolean; a declared null is REJECTED.
 *   - csrf.autoHtml and its aliases: not settings, omit them. A true value is REJECTED at boot.
 *   - trustedProxies: each entry '*', a valid IP address or a valid CIDR range. IPv6 ranges
 *     below /96 that embed an IPv4 address (::ffff:a.b.c.d/N, ::a.b.c.d/N, 64:ff9b::a.b.c.d/N)
 *     are REJECTED.
 *   - trustedHeaders: each entry a header Request can read. An unknown name is REJECTED, not
 *     ignored, so a typo cannot leave the operator trusting a header they do not.
 */
final readonly class SecurityConfig
{
    /**
     * The keys fromArray() reads each setting from, in order of preference; 'csrf.enabled' is the key enabled
     * of the csrf mapping. Any other key is refused.
     */
    private const array SPELLINGS = [
        'forceHttps'     => ['forceHttps', 'force_https'],
        'csrfEnabled'    => ['csrf.enabled', 'csrf.csrf_enabled', 'csrfEnabled', 'csrf_enabled'],
        'csrfAutoHtml'   => ['csrf.autoHtml', 'csrf.auto_html', 'csrfAutoHtml', 'csrf_auto_html'],
        'csrfExceptions' => ['csrf.exceptions', 'csrf.csrf_exceptions', 'csrfExceptions', 'csrf_exceptions'],
        'allowedHosts'   => ['allowedHosts', 'allowed_hosts'],
        'maxBodySize'    => ['maxBodySize', 'max_body_size'],
        'trustedProxies' => ['trustedProxies', 'trusted_proxies'],
        'trustedHeaders' => ['trustedHeaders', 'trusted_headers'],
        'encryptionKey'  => ['encryption.key', 'encryptionKey', 'encryption_key'],
        'headers'        => ['headers'],
    ];

    private const string ENCRYPTION_EXAMPLE = 'encryption: { key: !env ENCRYPTION_KEY }';

    /** Shown when a mapping holds a plain value. */
    private const array MAPPING_EXAMPLES = [
        'csrf' => 'csrf: { enabled: false }',
        'encryption' => self::ENCRYPTION_EXAMPLE,
    ];

    /** The security response headers, read from the security.headers section. */
    public SecureHeadersConfig $headers;

    /**
     * @param bool     $forceHttps      Redirect plain-HTTP requests to HTTPS.
     * @param bool     $csrfEnabled     Enable CSRF token verification on mutating requests.
     * @param bool     $csrfAutoHtml    Pass false: fromArray() refuses true, and the constructor ignores it.
     * @param string[] $csrfExceptions  Regex path patterns excluded from CSRF validation.
     * @param string[] $allowedHosts    Restrict accepted Host headers; empty allows all.
     * @param int      $maxBodySize     Maximum request body in bytes (0 = unlimited).
     * @param string[] $trustedProxies  IP addresses/CIDR ranges whose forwarded headers
     *                                  are trusted. Empty = trust no proxies (safe default).
     *                                  Use ['*'] to trust all proxies (development only).
     * @param ?string  $encryptionKey   Application encryption key (nullable, from !env).
     * @param string[] $trustedHeaders  Forwarding headers read once the peer is a trusted proxy.
     *                                  Trusting a proxy does not trust every header it passes through.
     * @param string[] $declaredKeys    Canonical names the SOURCE ARRAY contained. See isDeclared();
     *                                  empty when built directly rather than through fromArray().
     * @param ?SecureHeadersConfig $headers  Response headers. Null means SecureHeadersConfig::defaults().
     */
    public function __construct(
        public bool $forceHttps,
        public bool $csrfEnabled,
        /** @deprecated since 0.14, will be removed in 0.15. Pass false: fromArray() refuses true. */
        public bool $csrfAutoHtml,
        public array $csrfExceptions,
        public array $allowedHosts,
        public int $maxBodySize,
        public array $trustedProxies = [],
        public ?string $encryptionKey = null,
        public array $trustedHeaders = Request::TRUSTED_HEADERS_DEFAULT,
        public array $declaredKeys = [],
        ?SecureHeadersConfig $headers = null,
    ) {
        $this->headers = $headers ?? SecureHeadersConfig::defaults();
    }

    /**
     * Whether the configuration source named this setting.
     *
     * The typed object cannot tell "CSRF requested" from "said nothing", since csrfEnabled
     * defaults to true. ApplicationBuilder uses this to refuse a boot that requests a protection
     * nothing consumes, without failing applications that never mention the section.
     *
     * Accepts the canonical camelCase name: forceHttps, csrfEnabled, csrfAutoHtml, csrfExceptions,
     * allowedHosts, maxBodySize, trustedProxies, trustedHeaders, encryptionKey, headers.
     */
    public function isDeclared(string $key): bool
    {
        return in_array($key, $this->declaredKeys, true);
    }

    /**
     * Build a SecurityConfig from a plain key-value array.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException if a key is unknown, a setting is written under two spellings, csrf or
     *         encryption holds a plain value, the encryption key is not a string, a value is not a boolean,
     *         csrf.autoHtml is true, allowedHosts is null or names nothing, maxBodySize is negative or not a number
     *         of bytes, or a list entry is invalid (csrfExceptions, allowedHosts, trustedProxies, trustedHeaders),
     *         or headers is neither null nor a mapping, or a header setting is invalid (see
     *         SecureHeadersConfig::fromArray()).
     */
    public static function fromArray(array $values): self
    {
        $keys = ConfigKeys::read(
            'security',
            $values,
            self::SPELLINGS,
            unlisted: ['csrfAutoHtml'],
            mappingExamples: self::MAPPING_EXAMPLES,
        );

        $headers = $keys->value('headers');
        if ($headers !== null && !is_array($headers)) {
            throw ConfigurationException::invalidValue(
                'security',
                'headers',
                $headers,
                'the security.headers section must be a mapping of header settings, such as xFrameOptions and csp',
            );
        }

        $forceHttps = $keys->boolean('forceHttps', false);
        $csrfEnabled = $keys->boolean('csrfEnabled', true);
        $csrfAutoHtml = $keys->boolean('csrfAutoHtml', false);
        $csrfExceptions = (array) ($keys->value('csrfExceptions') ?? []);

        $allowedHostsValue = $keys->value('allowedHosts');
        if ($allowedHostsValue === null && $keys->has('allowedHosts')) {
            throw ConfigurationException::invalidValue('security', 'allowedHosts', null, 'an empty list is written []');
        }

        $allowedHosts = self::listValue($allowedHostsValue);
        if ($allowedHosts === [] && is_string($allowedHostsValue)) {
            throw ConfigurationException::invalidValue('security', 'allowedHosts', $allowedHostsValue, 'set the variable to at least one entry, or remove it');
        }

        $maxBodySize = $keys->value('maxBodySize') === null
            ? 2_097_152
            : self::byteCount($keys->key('maxBodySize'), $keys->value('maxBodySize'));
        $trustedProxies = self::listValue($keys->value('trustedProxies'));
        // An absent key takes the default set. An explicit [] is a valid, strictest setting.
        $trustedHeaders = (array) ($keys->value('trustedHeaders') ?? Request::TRUSTED_HEADERS_DEFAULT);

        $encryptionKey = $keys->value('encryptionKey');
        if ($encryptionKey !== null && !is_string($encryptionKey)) {
            throw ConfigurationException::invalidType(
                'security',
                $keys->key('encryptionKey'),
                'must be a string, such as ' . self::ENCRYPTION_EXAMPLE,
            );
        }

        $encryptionKey = $encryptionKey === null || trim($encryptionKey) === '' ? null : trim($encryptionKey);

        if ($csrfAutoHtml) {
            throw ConfigurationException::invalidValue(
                'security',
                $keys->key('csrfAutoHtml'),
                $keys->value('csrfAutoHtml'),
                'remove this line. ' . sprintf(CsrfConfig::INJECTION_REFUSAL, '_csrf_token'),
            );
        }

        foreach ($csrfExceptions as $i => $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                throw ConfigurationException::invalidValue(
                    'security',
                    "csrfExceptions[$i]",
                    $pattern,
                    'each entry must be a non-empty string'
                        . ($pattern === null ? '; quote a pattern that starts with "#" in YAML' : ''),
                );
            }
        }

        foreach ($allowedHosts as $i => $host) {
            $reason = is_string($host)
                ? AllowedHostsMiddleware::invalidEntryReason($host)
                : 'each entry must be a host name string';
            if ($reason !== null) {
                throw ConfigurationException::invalidValue('security', "allowedHosts[$i]", $host, $reason);
            }
        }

        foreach ($trustedProxies as $i => $proxy) {
            $reason = match (true) {
                !is_string($proxy) => 'each entry must be a string',
                $proxy === '*' => null,
                default => IpRange::invalidEntryReason($proxy),
            };
            if ($reason !== null && is_string($proxy) && str_contains($proxy, ',')) {
                $reason .= '; use list items, not a comma inside one item';
            }
            if ($reason !== null) {
                throw ConfigurationException::invalidValue('security', "trustedProxies[$i]", $proxy, $reason);
            }
        }

        $normalizedTrustedHeaders = [];
        foreach ($trustedHeaders as $i => $header) {
            if (!is_string($header) || trim($header) === '') {
                throw ConfigurationException::invalidValue(
                    'security',
                    "trustedHeaders[$i]",
                    $header,
                    'each entry must be a non-empty string',
                );
            }

            $name = strtolower(trim($header));
            if (!in_array($name, Request::TRUSTED_HEADERS_SUPPORTED, true)) {
                throw ConfigurationException::invalidValue(
                    'security',
                    "trustedHeaders[$i]",
                    $header,
                    'unknown forwarding header, supported names are '
                        . implode(', ', Request::TRUSTED_HEADERS_SUPPORTED),
                );
            }

            if (!in_array($name, $normalizedTrustedHeaders, true)) {
                $normalizedTrustedHeaders[] = $name;
            }
        }

        return new self(
            forceHttps: $forceHttps,
            csrfEnabled: $csrfEnabled,
            csrfAutoHtml: $csrfAutoHtml,
            csrfExceptions: array_values($csrfExceptions),
            allowedHosts: array_values($allowedHosts),
            maxBodySize: $maxBodySize,
            trustedProxies: array_values($trustedProxies),
            encryptionKey: $encryptionKey,
            trustedHeaders: $normalizedTrustedHeaders,
            declaredKeys: $keys->properties(),
            headers: is_array($headers) ? SecureHeadersConfig::fromArray($headers) : null,
        );
    }

    /**
     * @param string $written The key as the file wrote it, named in the refusal.
     * @throws ConfigurationException if the value is negative, or neither an integer nor a string of at most 18 digits.
     */
    private static function byteCount(string $written, mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw ConfigurationException::invalidValue(
                    'security',
                    $written,
                    $value,
                    'must be 0 (unlimited) or a positive byte count',
                );
            }

            return $value;
        }

        if (is_string($value) && preg_match('/^\d{1,18}$/D', $value) === 1) {
            return (int) $value;
        }

        throw ConfigurationException::invalidValue(
            'security',
            $written,
            $value,
            'must be a number of bytes, for example 2097152 (no unit suffix)',
        );
    }

    /**
     * A list setting: an array as given, or a comma-separated string split into trimmed,
     * non-empty entries. A string naming nothing is an empty list.
     *
     * @return array<mixed>
     */
    private static function listValue(mixed $value): array
    {
        if (!is_string($value)) {
            return (array) ($value ?? []);
        }

        $entries = [];
        foreach (explode(',', $value) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

}
