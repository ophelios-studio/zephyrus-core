<?php

declare(strict_types=1);

namespace Zephyrus\Http;

use InvalidArgumentException;
use Zephyrus\Exceptions\MessageValue;

final readonly class Response
{
    /** RFC 9110 token: the characters a header field name may contain. */
    private const HEADER_NAME_PATTERN = "/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D";

    /**
     * A single leading "/" (not "//"), then no backslash and no ASCII control character. Browsers read "/\" like "//",
     * another host.
     */
    private const LOCAL_PATH_PATTERN = '#^/(?![/\\\\])[^\x00-\x1F\x7F\\\\]*+$#D';

    /** RFC 9110 allows HTAB as the only control character in a field value. */
    private const HEADER_VALUE_FORBIDDEN_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]/';

    /** A leading "/", then printable ASCII without space, "," or ";". */
    private const COOKIE_PATH_PATTERN = '#^/[\x21-\x2B\x2D-\x3A\x3C-\x7E]*+$#D';

    private const COOKIE_DOMAIN_PATTERN = '/^\.?[A-Za-z0-9-]++(?:\.[A-Za-z0-9-]++)*+$/D';

    private const COOKIE_SAME_SITE_VALUES = ['Strict', 'Lax', 'None'];

    private const STATUS_PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        206 => 'Partial Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        410 => 'Gone',
        413 => 'Content Too Large',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /** @var array<string, string> Header names are stored lowercased. */
    public array $headers;

    /**
     * Header values and cookies are stored as given; use withHeader() and withCookie() for computed or request values.
     *
     * @param array<string, string> $headers
     * @param array<array-key, string> $cookies A list of Set-Cookie values, each emitted on its own line after the headers.
     *
     * @throws InvalidArgumentException When $cookies is not a list: use withCookie() to name a cookie.
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        array $headers = [],
        public array $cookies = [],
    ) {
        if (!array_is_list($cookies)) {
            throw new InvalidArgumentException(
                'Invalid cookies: expected a list of Set-Cookie values indexed from 0, as array_values() gives. '
                . 'Use withCookie() to set a cookie by name.',
            );
        }
        $this->headers = array_change_key_case($headers);
    }

    /**
     * Whether a header value is free of control characters other than HTAB, the RFC 9110 rule for field values.
     */
    public static function isValidHeaderValue(string $value): bool
    {
        return preg_match(self::HEADER_VALUE_FORBIDDEN_PATTERN, $value) !== 1;
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, [
            'content-type' => 'text/plain; charset=utf-8',
        ]);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, [
            'content-type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * @param mixed $payload Any value supported by json_encode
     */
    public static function json(mixed $payload, int $status = 200): self
    {
        return new self(
            body: (string) json_encode($payload, JSON_THROW_ON_ERROR),
            status: $status,
            headers: [
                'content-type' => 'application/json; charset=utf-8',
            ],
        );
    }

    public static function noContent(): self
    {
        return new self(body: '', status: 204, headers: []);
    }

    /**
     * Redirects to $url with an empty body. Never pass user input here: use localRedirect().
     *
     * Default status 302. 301 and 308 are cacheable, 303 suits redirect-after-POST, 307 and 308 keep the method.
     *
     * @throws InvalidArgumentException When $url holds a control character other than HTAB.
     */
    public static function redirect(string $url, int $status = 302): self
    {
        if (!self::isValidHeaderValue($url)) {
            throw new InvalidArgumentException(
                'Invalid redirect URL: it contains a control character; percent-encode it, or use localRedirect() '
                . 'for request input.',
            );
        }

        return new self(body: '', status: $status, headers: ['location' => $url]);
    }

    /**
     * Redirects to $target when it is a local path, otherwise to $fallback. Use it for targets read from a request.
     *
     * See isLocalPath() for what counts as local. A non-string $target falls back too. A percent-encoded CRLF stays
     * local: it is inert in a Location header.
     *
     * @throws InvalidArgumentException When $fallback is not a local path.
     */
    public static function localRedirect(mixed $target, string $fallback = '/', int $status = 302): self
    {
        if (!self::isLocalPath($fallback)) {
            throw new InvalidArgumentException(sprintf(
                'The redirect fallback "%s" must be a local path starting with a single "/".',
                $fallback,
            ));
        }

        $isLocal = is_string($target) && self::isLocalPath($target);
        $location = $isLocal ? $target : $fallback;

        return self::redirect($location, $status);
    }

    /**
     * Whether $path is a local path: a single leading "/", no backslash and no ASCII control character.
     * A redirect target, not a filesystem path: dot segments are allowed.
     */
    public static function isLocalPath(string $path): bool
    {
        return preg_match(self::LOCAL_PATH_PATTERN, $path) === 1;
    }

    /**
     * Returns a copy with the header set. The name and the value are validated.
     *
     * @throws InvalidArgumentException When $name is not a valid header name.
     * @throws InvalidArgumentException When $value holds a control character other than HTAB.
     */
    public function withHeader(string $name, string $value): self
    {
        self::assertValidHeaderName($name);
        self::assertValidHeaderValue($name, $value);

        $headers = $this->headers;
        $headers[strtolower($name)] = $value;

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $headers,
            cookies: $this->cookies,
        );
    }

    /**
     * Returns a copy with the headers set, as withHeader() does.
     *
     * @param array<string, string> $headers
     * @throws InvalidArgumentException When a key is an integer: a list, or a numeric name PHP cast to int.
     * @throws InvalidArgumentException When any name is not a valid header name.
     * @throws InvalidArgumentException When a value is not a string.
     * @throws InvalidArgumentException When any value holds a control character other than HTAB.
     */
    public function withHeaders(array $headers): self
    {
        $normalized = $this->headers;
        foreach ($headers as $name => $value) {
            if (!is_string($name)) { // @phpstan-ignore function.alreadyNarrowedType
                throw new InvalidArgumentException(sprintf(
                    'withHeaders() takes a name => value map; key %d is not a header name.',
                    $name,
                ));
            }

            self::assertValidHeaderName($name);
            if (!is_string($value)) { // @phpstan-ignore function.alreadyNarrowedType
                throw new InvalidArgumentException(sprintf(
                    'Invalid value for HTTP header "%s": it must be a string.',
                    $name,
                ));
            }

            self::assertValidHeaderValue($name, $value);
            $normalized[strtolower($name)] = $value;
        }

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $normalized,
            cookies: $this->cookies,
        );
    }

    /**
     * Returns a copy that sets a cookie on its own Set-Cookie line, replacing an earlier cookie of the same name, domain
     * and path (never a constructor line without Path=, which browsers scope to the request's directory, nor one marked
     * Partitioned, which browsers store apart).
     *
     * Secure, HttpOnly, SameSite=Lax and the path "/" unless told otherwise; Secure is on even where the session
     * setting is "auto", so pass $secure = false explicitly for plain-HTTP development. The value is percent-encoded as
     * setcookie() does. A null $maxAge makes a browser-session cookie; 0 expires the cookie.
     *
     * @throws InvalidArgumentException When $name is not a token, or $maxAge, $path, $domain or $sameSite is invalid.
     * @throws InvalidArgumentException When browsers would discard the cookie: SameSite=None, or a __Secure- or
     *     __Host- name, without Secure; a __Host- name with a domain or a path other than "/".
     */
    public function withCookie(
        string $name,
        string $value,
        ?int $maxAge = null,
        string $path = '/',
        ?string $domain = null,
        bool $secure = true,
        bool $httpOnly = true,
        string $sameSite = 'Lax',
    ): self {
        self::assertValidCookieAttributes($name, $maxAge, $path, $domain, $sameSite);
        self::assertBrowsersKeepCookie($name, $path, $domain, $secure, $sameSite);

        $attributes = [$name . '=' . rawurlencode($value)];
        if ($maxAge !== null) {
            $attributes[] = 'Max-Age=' . $maxAge;
        }
        $attributes[] = 'Path=' . $path;
        if ($domain !== null) {
            $attributes[] = 'Domain=' . $domain;
        }
        if ($secure) {
            $attributes[] = 'Secure';
        }
        if ($httpOnly) {
            $attributes[] = 'HttpOnly';
        }
        $attributes[] = 'SameSite=' . $sameSite;

        $cookies = array_values(array_filter(
            $this->cookies,
            static fn (string $cookie): bool => !self::isCookieScope($cookie, $name, $domain, $path),
        ));
        $cookies[] = implode('; ', $attributes);

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $this->headers,
            cookies: $cookies,
        );
    }

    /** Whether a Set-Cookie line is the cookie browsers identify by this name, domain and path; a partitioned line never is. */
    private static function isCookieScope(string $line, string $name, ?string $domain, string $path): bool
    {
        $parts = array_map('trim', explode(';', $line));
        $pair = explode('=', array_shift($parts), 2);
        if (count($pair) !== 2 || trim($pair[0]) !== $name) {
            return false;
        }

        $lineDomain = null;
        $linePath = null;
        foreach ($parts as $part) {
            [$attribute, $value] = array_map('trim', explode('=', $part, 2)) + [1 => ''];
            if (strtolower($attribute) === 'partitioned') {
                return false;
            }
            match (strtolower($attribute)) {
                'domain' => $lineDomain = $value !== '' ? $value : $lineDomain,
                'path' => $linePath = $value,
                default => null,
            };
        }

        return self::normalizeCookieDomain($lineDomain) === self::normalizeCookieDomain($domain)
            && $linePath === $path;
    }

    /** Lowercases a cookie domain and drops one leading dot, as browsers compare domains. */
    private static function normalizeCookieDomain(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        return strtolower(str_starts_with($domain, '.') ? substr($domain, 1) : $domain);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function assertValidCookieAttributes(
        string $name,
        ?int $maxAge,
        string $path,
        ?string $domain,
        string $sameSite,
    ): void {
        if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid cookie name %s: a cookie name may only contain RFC 9110 token characters.',
                MessageValue::quote($name),
            ));
        }

        if ($maxAge !== null && $maxAge < 0) {
            throw new InvalidArgumentException(sprintf(
                'Invalid Max-Age %d for cookie "%s": use 0 to expire the cookie, or null for a browser-session cookie.',
                $maxAge,
                $name,
            ));
        }

        if (preg_match(self::COOKIE_PATH_PATTERN, $path) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid path %s for cookie "%s": it must start with "/" and contain only printable ASCII without space, "," or ";".',
                MessageValue::quote($path),
                $name,
            ));
        }

        if ($domain !== null && preg_match(self::COOKIE_DOMAIN_PATTERN, $domain) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid domain %s for cookie "%s": use a host name such as example.com, or null for a host-only '
                . 'cookie.',
                MessageValue::quote($domain),
                $name,
            ));
        }

        if (!in_array($sameSite, self::COOKIE_SAME_SITE_VALUES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid SameSite value %s for cookie "%s": use Strict, Lax or None.',
                MessageValue::quote($sameSite),
                $name,
            ));
        }
    }

    /**
     * Refuses the combinations browsers silently drop. Prefixes are matched case-insensitively, as browsers do.
     *
     * @throws InvalidArgumentException
     */
    private static function assertBrowsersKeepCookie(
        string $name,
        string $path,
        ?string $domain,
        bool $secure,
        string $sameSite,
    ): void {
        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException(sprintf(
                'Cookie "%s" cannot use SameSite=None without Secure: browsers discard it.',
                $name,
            ));
        }

        if (stripos($name, '__Host-') === 0 && (!$secure || $path !== '/' || $domain !== null)) {
            throw new InvalidArgumentException(sprintf(
                'Cookie "%s" uses the __Host- prefix, which requires Secure, the path "/" and no domain.',
                $name,
            ));
        }

        if (stripos($name, '__Secure-') === 0 && !$secure) {
            throw new InvalidArgumentException(sprintf(
                'Cookie "%s" uses the __Secure- prefix, which requires Secure.',
                $name,
            ));
        }
    }

    /**
     * Refuses a name outside the RFC 9110 token charset.
     *
     * @throws InvalidArgumentException
     */
    private static function assertValidHeaderName(string $name): void
    {
        if (preg_match(self::HEADER_NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Invalid HTTP header name %s: a field name may only contain RFC 9110 token characters.',
                MessageValue::quote($name),
            ));
        }
    }

    /**
     * Refuses a control character other than HTAB.
     *
     * @throws InvalidArgumentException
     */
    private static function assertValidHeaderValue(string $name, string $value): void
    {
        if (!self::isValidHeaderValue($value)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid value for HTTP header "%s": it contains a control character other than HTAB.',
                $name,
            ));
        }
    }

    /**
     * Whether a header is present, whatever its value. The name is matched case-insensitively.
     * "Set-Cookie" is also present when withCookie() added a cookie.
     */
    public function hasHeader(string $name): bool
    {
        return $this->getHeader($name) !== null || ($this->cookies !== [] && strcasecmp($name, 'set-cookie') === 0);
    }

    /**
     * The stored value of a header, or null when it is absent. The name is matched case-insensitively.
     * Cookies added by withCookie() are not headers: they are in $cookies.
     */
    public function getHeader(string $name): ?string
    {
        foreach ($this->headers as $existing => $value) {
            if (strcasecmp((string) $existing, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Whether a header is present with a non-blank value. A blank value counts as absent.
     * "Set-Cookie" is also present when withCookie() added a cookie.
     */
    public function hasNonBlankHeader(string $name): bool
    {
        return ($this->cookies !== [] && strcasecmp($name, 'set-cookie') === 0)
            || trim($this->getHeader($name) ?? '') !== '';
    }

    /**
     * Returns a copy without a header. Removing "Set-Cookie" removes the cookies of withCookie() and the constructor;
     * those PHP queued itself (session_start(), setcookie()) are still sent.
     */
    public function withoutHeader(string $name): self
    {
        $headers = $this->headers;
        unset($headers[strtolower($name)]);

        return new self(
            body: $this->body,
            status: $this->status,
            headers: $headers,
            cookies: strcasecmp($name, 'set-cookie') === 0 ? [] : $this->cookies,
        );
    }

    public function withStatus(int $status): self
    {
        return new self(
            body: $this->body,
            status: $status,
            headers: $this->headers,
            cookies: $this->cookies,
        );
    }

    /** The reason phrase of the status code, or 'Unknown Status'. */
    public function statusPhrase(): string
    {
        return self::STATUS_PHRASES[$this->status] ?? 'Unknown Status';
    }

    /** The HTTP/1.1 status line, e.g. "HTTP/1.1 200 OK". */
    public function toStatusLine(): string
    {
        return sprintf('HTTP/1.1 %d %s', $this->status, $this->statusPhrase());
    }

    /**
     * The header lines send() emits, e.g. "x-trace-id: abc", then one "set-cookie" line per cookie. Does not touch
     * the SAPI.
     *
     * @return list<string>
     */
    public function toHeaderLines(): array
    {
        $lines = [];
        foreach ($this->headers as $name => $value) {
            $lines[] = sprintf('%s: %s', $name, $value);
        }

        foreach ($this->cookies as $cookie) {
            $lines[] = 'set-cookie: ' . $cookie;
        }

        return $lines;
    }

    /** Emits the status line, headers and body to the SAPI through a default {@see SapiEmitter}. */
    public function send(): void
    {
        (new SapiEmitter())->emit($this);
    }
}
