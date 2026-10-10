<?php

declare(strict_types=1);

namespace Zephyrus\Core\Config;

use Zephyrus\Http\IpRange;
use Zephyrus\Http\Request;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\CsrfConfig;

/**
 * Immutable configuration section for HTTP security behaviour.
 *
 * Supports both flat keys (legacy) and nested YAML sections:
 *
 * Nested format (preferred):
 *   security:
 *     forceHttps: true
 *     allowedHosts: [example.com]
 *     maxBodySize: 2097152
 *     trustedProxies: []
 *     trustedHeaders: [x-forwarded-for, x-forwarded-host, x-forwarded-proto, x-forwarded-port]
 *     csrf:
 *       enabled: true
 *       exceptions: []
 *     encryption:
 *       key: !env ENCRYPTION_KEY
 *
 * Flat format (legacy, still accepted):
 *   security:
 *     forceHttps: true
 *     csrfEnabled: true
 *     csrfExceptions: []
 *     allowedHosts: []
 *     maxBodySize: 2097152
 *     trustedProxies: []
 *     trustedHeaders: []
 *
 * Defaults are conservative yet development-friendly:
 *   - forceHttps:     false (must be explicitly enabled in production)
 *   - csrfEnabled:    true  (on by default)
 *   - csrfExceptions: []    (no excluded paths by default)
 *   - allowedHosts:   []    (empty = any host; populate for production lockdown)
 *   - maxBodySize:    2097152 (2 MB; 0 = unlimited)
 *   - trustedProxies: []    (empty = trust no proxies; forwarded headers ignored)
 *   - trustedHeaders: the X-Forwarded-* family (see Request::TRUSTED_HEADERS_DEFAULT);
 *                     'forwarded', 'x-real-ip', 'cf-connecting-ip' and 'x-client-ip'
 *                     are opt-in, and [] reads no forwarded header at all
 *   - encryptionKey:  null  (must be set explicitly for Cryptography usage)
 *
 * Validation rules:
 *   - maxBodySize must be 0 or greater.
 *   - Each allowedHost entry must be a non-empty string.
 *   - Each csrfExceptions entry must be a non-empty string.
 *   - csrf.autoHtml and its aliases are not settings: omit them. A value that
 *     casts to true is REJECTED at boot.
 *   - Each trustedProxies entry must be '*', a valid IP address or a valid CIDR range.
 *   - Each trustedHeaders entry must name a header Request can actually read;
 *     an unknown name is REJECTED rather than ignored, because silently dropping
 *     a typo would leave an operator believing they trust a header they do not.
 */
final readonly class SecurityConfig
{
    private const int SHOWN_VALUE_MAX_LENGTH = 64;

    /**
     * The spellings fromArray() reads each setting from, in the order it prefers
     * them: [section, key], where section 'values' is the top level.
     */
    private const array SPELLINGS = [
        'forceHttps'     => [['values', 'forceHttps'], ['values', 'force_https']],
        'csrfEnabled'    => [
            ['csrf', 'enabled'], ['csrf', 'csrf_enabled'], ['values', 'csrfEnabled'], ['values', 'csrf_enabled'],
        ],
        'csrfAutoHtml'   => [
            ['csrf', 'autoHtml'], ['csrf', 'auto_html'], ['values', 'csrfAutoHtml'], ['values', 'csrf_auto_html'],
        ],
        'csrfExceptions' => [
            ['csrf', 'exceptions'], ['csrf', 'csrf_exceptions'], ['values', 'csrfExceptions'], ['values', 'csrf_exceptions'],
        ],
        'allowedHosts'   => [['values', 'allowedHosts'], ['values', 'allowed_hosts']],
        'maxBodySize'    => [['values', 'maxBodySize'], ['values', 'max_body_size']],
        'trustedProxies' => [['values', 'trustedProxies'], ['values', 'trusted_proxies']],
        'trustedHeaders' => [['values', 'trustedHeaders'], ['values', 'trusted_headers']],
        'encryptionKey'  => [['encryption', 'key'], ['values', 'encryptionKey'], ['values', 'encryption_key']],
    ];

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
     * @param string[] $trustedHeaders  Which forwarding headers may be read once the peer is a
     *                                  trusted proxy. Trusting a proxy is NOT the same as trusting
     *                                  every header a caller can name: a proxy manages one family
     *                                  and passes the rest through untouched. Defaults to the
     *                                  X-Forwarded-* family; [] reads none.
     * @param string[] $declaredKeys    Canonical names of the settings the SOURCE ARRAY actually
     *                                  contained. See isDeclared(); empty when the object was
     *                                  built directly rather than through fromArray().
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
    ) {
    }

    /**
     * Whether the configuration source actually named this setting.
     *
     * The typed object cannot answer that on its own: csrfEnabled DEFAULTS to
     * true, so "the operator asked for CSRF" and "the operator said nothing"
     * produce the identical value. ApplicationBuilder needs the difference to
     * refuse a boot where a protection was REQUESTED and nothing consumes it,
     * without failing every application that simply never mentioned the
     * section.
     *
     * Accepts the canonical camelCase name: forceHttps, csrfEnabled,
     * csrfAutoHtml, csrfExceptions, allowedHosts, maxBodySize, trustedProxies,
     * trustedHeaders, encryptionKey.
     */
    public function isDeclared(string $key): bool
    {
        return in_array($key, $this->declaredKeys, true);
    }

    /**
     * Build a SecurityConfig from a plain key-value array.
     *
     * Accepts both nested (csrf: / encryption:) and flat (csrfEnabled, etc.)
     * key variants. Nested keys take precedence when both are present.
     *
     * @param array<string, mixed> $values
     * @throws ConfigurationException if supplied values violate constraints.
     */
    public static function fromArray(array $values): self
    {
        $csrf = isset($values['csrf']) && is_array($values['csrf']) ? $values['csrf'] : [];
        $encryption = isset($values['encryption']) && is_array($values['encryption']) ? $values['encryption'] : [];
        $sections = ['values' => $values, 'csrf' => $csrf, 'encryption' => $encryption];

        $forceHttps = (bool) (self::read('forceHttps', $sections) ?? false);
        $csrfEnabled = (bool) (self::read('csrfEnabled', $sections) ?? true);
        $autoHtml = self::findWritten('csrfAutoHtml', $sections);
        $csrfAutoHtml = (bool) ($autoHtml[1] ?? false);
        $csrfExceptions = (array) (self::read('csrfExceptions', $sections) ?? []);

        $allowedHosts = (array) (self::read('allowedHosts', $sections) ?? []);
        $maxBodySize = (int) (self::read('maxBodySize', $sections) ?? 2_097_152);
        $trustedProxies = (array) (self::read('trustedProxies', $sections) ?? []);
        // An ABSENT key takes the default set; an explicitly empty list is a
        // valid, maximally strict setting and must not be confused with it.
        $trustedHeaders = (array) (self::read('trustedHeaders', $sections) ?? Request::TRUSTED_HEADERS_DEFAULT);

        $encryptionKey = self::read('encryptionKey', $sections);
        if (is_string($encryptionKey)) {
            $encryptionKey = trim($encryptionKey);
            if ($encryptionKey === '') {
                $encryptionKey = null;
            }
        } else {
            $encryptionKey = null;
        }

        if ($autoHtml !== null && $csrfAutoHtml) {
            [$written, $rawValue] = $autoHtml;

            throw ConfigurationException::invalidValue(
                'security',
                $written,
                self::rawValueForMessage($rawValue),
                'remove this line. ' . sprintf(CsrfConfig::INJECTION_REFUSAL, '_csrf_token'),
            );
        }

        if ($maxBodySize < 0) {
            throw ConfigurationException::invalidValue(
                'security',
                'maxBodySize',
                $maxBodySize,
                'must be 0 (unlimited) or a positive byte count',
            );
        }

        foreach ($csrfExceptions as $i => $pattern) {
            if (!is_string($pattern) || trim($pattern) === '') {
                throw ConfigurationException::invalidValue(
                    'security',
                    "csrfExceptions[$i]",
                    $pattern,
                    'each entry must be a non-empty string',
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
            if (!is_string($proxy) || ($proxy !== '*' && !IpRange::isValid($proxy))) {
                $reason = 'each entry must be "*", an IP address or a CIDR range such as 10.0.0.0/8 or 2001:db8::/32';
                if (is_string($proxy) && str_contains($proxy, ',')) {
                    $reason .= '; one entry per list item, a comma-separated value is not accepted';
                }

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
            declaredKeys: self::declaredKeys($sections),
        );
    }

    /**
     * The canonical names the source array actually mentioned, under any of the
     * aliases fromArray() accepts. See isDeclared().
     *
     * @param array<string, array<string, mixed>> $sections Source array and nested sections, by name.
     * @return list<string>
     */
    private static function declaredKeys(array $sections): array
    {
        $declared = [];

        foreach (self::SPELLINGS as $canonical => $spellings) {
            foreach ($spellings as [$section, $key]) {
                if (array_key_exists($key, $sections[$section])) {
                    $declared[] = $canonical;
                    continue 2;
                }
            }
        }

        return $declared;
    }

    /**
     * The value fromArray() uses for a setting, or null when no spelling wrote it.
     *
     * @param array<string, array<string, mixed>> $sections
     */
    private static function read(string $canonical, array $sections): mixed
    {
        return self::findWritten($canonical, $sections)[1] ?? null;
    }

    /**
     * The spelling that supplied the value fromArray() used, as a path such as
     * "csrf.auto_html", with its raw value. A null spelling is skipped, as fromArray() does.
     *
     * @param array<string, array<string, mixed>> $sections
     * @return array{string, mixed}|null
     */
    private static function findWritten(string $canonical, array $sections): ?array
    {
        foreach (self::SPELLINGS[$canonical] as [$section, $key]) {
            $value = $sections[$section][$key] ?? null;

            if ($value !== null) {
                return [$section === 'values' ? $key : $section . '.' . $key, $value];
            }
        }

        return null;
    }

    private static function rawValueForMessage(mixed $value): string
    {
        return match (true) {
            is_string($value) => '"' . self::shownString($value) . '"',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }

    /** Cuts a written string to the shown length, with invalid UTF-8 replaced and control characters escaped. */
    private static function shownString(string $value): string
    {
        $valid = mb_scrub($value, 'UTF-8');

        return addcslashes(mb_strcut($valid, 0, self::SHOWN_VALUE_MAX_LENGTH, 'UTF-8'), "\\\0..\37\177");
    }
}
