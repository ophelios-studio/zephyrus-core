<?php

declare(strict_types=1);

namespace Zephyrus\Http;

use Zephyrus\Upload\FileUpload;
use Zephyrus\Upload\UploadException;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     * @param array<string, FileUpload|array<int, FileUpload>> $files
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $query = [],
        public array $parsedBody = [],
        public array $headers = [],
        public array $cookies = [],
        public array $attributes = [],
        public array $files = [],
        public ?string $clientIp = null,
    ) {
    }

    /**
     * Build a Request from PHP superglobals. This is the primary entry point
     * for production use in public/index.php. All superglobal arrays may be
     * overridden for testing without touching the real superglobals.
     *
     * Method override is applied when the raw method is POST:
     *   1. X-Http-Method-Override request header (highest priority).
     *   2. `_method` field in the parsed request body.
     *
     * Body parsing rules:
     *   - application/json  → JSON-decodes $rawBody (defaults to php://input).
     *   - form content types → uses $post directly.
     *   - GET / HEAD        → always empty (no body by spec).
     *
     * @param array<string, mixed>|null  $server
     * @param array<string, mixed>|null  $get
     * @param array<string, mixed>|null  $post
     * @param array<string, string>|null $cookie
     * @param array<string, mixed>|null   $files
     * @param string|null                $rawBody        Injected for testing; defaults to php://input.
     * @param string[]                   $trustedProxies IP addresses/CIDR ranges whose forwarded
     *                                                   headers are trusted. Use ['*'] for all.
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $get = null,
        ?array $post = null,
        ?array $cookie = null,
        ?array $files = null,
        ?string $rawBody = null,
        array $trustedProxies = [],
    ): self {
        $server = $server ?? $_SERVER;
        $get    = $get    ?? $_GET;
        $post   = $post   ?? $_POST;
        $cookie = $cookie ?? $_COOKIE;
        $files  = $files  ?? $_FILES;

        $method  = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $headers = self::extractHeadersFromServer($server);
        $remoteAddr = self::normalizeIp(isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null);
        $trustForwarded = self::isProxyTrusted($remoteAddr, $trustedProxies);
        $uri     = self::buildUri($server, $trustForwarded);
        $clientIp = self::resolveClientIp($server, $headers, $trustForwarded);

        $parsedBody = self::parseBody($method, $headers, $post, $rawBody);
        $method     = self::resolveMethodOverride($method, $headers, $parsedBody);

        return new self(
            method:     $method,
            uri:        $uri,
            query:      $get,
            parsedBody: $parsedBody,
            headers:    $headers,
            cookies:    $cookie,
            attributes: [],
            files:      self::normalizeFileUploads($files),
            clientIp:   $clientIp,
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     * @param array<string, FileUpload|array<int, FileUpload>> $files
     */
    public static function fromArray(
        string $method,
        string $uri,
        array $query = [],
        array $parsedBody = [],
        array $headers = [],
        array $cookies = [],
        array $attributes = [],
        array $files = [],
        ?string $clientIp = null,
    ): self {
        return new self(
            method:     strtoupper($method),
            uri:        $uri,
            query:      $query,
            parsedBody: $parsedBody,
            headers:    self::normalizeHeaders($headers),
            cookies:    $cookies,
            attributes: $attributes,
            files:      $files,
            clientIp:   $clientIp,
        );
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->parsedBody[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(string $headerName = 'Authorization', string $prefix = 'Bearer '): ?string
    {
        $value = $this->header($headerName);
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalizedPrefix = trim($prefix);
        if ($normalizedPrefix === '') {
            return $trimmed;
        }

        if (strncasecmp($trimmed, $normalizedPrefix, strlen($normalizedPrefix)) === 0) {
            $token = trim(substr($trimmed, strlen($normalizedPrefix)));
            return $token === '' ? null : $token;
        }

        return $trimmed;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function file(string $field): ?FileUpload
    {
        $entry = $this->files[$field] ?? null;

        if ($entry instanceof FileUpload) {
            return $entry;
        }

        if (is_array($entry) && $entry !== [] && $entry[0] instanceof FileUpload) {
            return $entry[0];
        }

        return null;
    }

    /**
     * @return array<int, FileUpload>
     */
    public function filesOf(string $field): array
    {
        $entry = $this->files[$field] ?? null;

        if ($entry instanceof FileUpload) {
            return [$entry];
        }

        if (is_array($entry)) {
            return array_values(array_filter($entry, static fn (mixed $value): bool => $value instanceof FileUpload));
        }

        return [];
    }

    public function path(): string
    {
        return (string) parse_url($this->uri, PHP_URL_PATH);
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function isJson(): bool
    {
        return self::isJsonContentType($this->headers['content-type'] ?? '');
    }

    public function isSecure(): bool
    {
        return str_starts_with($this->uri, 'https://');
    }

    public function clientIp(?string $default = null): ?string
    {
        if ($this->clientIp !== null) {
            return $this->clientIp;
        }

        // Check attribute (set by middleware, e.g. from a load balancer SDK).
        $attributeValue = $this->attributes['client_ip'] ?? null;
        if (is_string($attributeValue)) {
            $normalized = self::normalizeIp($attributeValue);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            method:     $this->method,
            uri:        $this->uri,
            query:      $this->query,
            parsedBody: $this->parsedBody,
            headers:    $this->headers,
            cookies:    $this->cookies,
            attributes: $attributes,
            files:      $this->files,
            clientIp:   $this->clientIp,
        );
    }

    /**
     * Return a new request with the given attributes merged into any existing
     * attributes. New keys are added; existing keys are overwritten.
     *
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            method:     $this->method,
            uri:        $this->uri,
            query:      $this->query,
            parsedBody: $this->parsedBody,
            headers:    $this->headers,
            cookies:    $this->cookies,
            attributes: array_merge($this->attributes, $attributes),
            files:      $this->files,
            clientIp:   $this->clientIp,
        );
    }

    /**
     * Extract HTTP headers from a $_SERVER-style array.
     *
     * PHP surfaces request headers in $_SERVER with the HTTP_ prefix and
     * underscores replacing hyphens (e.g. X-Request-Id → HTTP_X_REQUEST_ID).
     * A small set of headers (Content-Type, Content-Length, Content-Md5) appear
     * without the prefix. All names are lowercased for case-insensitive lookup.
     *
     * @param  array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeadersFromServer(array $server): array
    {
        $headers = [];

        // Headers that PHP does not prefix with HTTP_
        $unprefixed = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];
        foreach ($unprefixed as $key) {
            if (isset($server[$key]) && $server[$key] !== '') {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = (string) $server[$key];
            }
        }

        // All HTTP_ prefixed entries
        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Construct a full URI string from a $_SERVER-style array.
     *
     * Scheme detection: HTTPS key present and not 'off' → https, else http.
     * Host: HTTP_HOST preferred; falls back to SERVER_NAME then 'localhost'.
     * Path + query: taken verbatim from REQUEST_URI (already URL-encoded by PHP).
     *
     * @param array<string, mixed> $server
     * @param bool $trustForwarded Whether to honor forwarded headers.
     */
    private static function buildUri(array $server, bool $trustForwarded = false): string
    {
        $requestUri = (string) ($server['REQUEST_URI'] ?? '/');

        // When behind a reverse proxy, REQUEST_URI may already be absolute
        if (str_starts_with($requestUri, 'http://') || str_starts_with($requestUri, 'https://')) {
            return $requestUri;
        }

        $forwarded = [];
        if ($trustForwarded) {
            $forwardedHeader = isset($server['HTTP_FORWARDED']) ? (string) $server['HTTP_FORWARDED'] : null;
            $forwarded = self::parseForwardedHeader($forwardedHeader);
        }

        $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && $server['HTTPS'] !== 'off';

        if ($trustForwarded) {
            $scheme = $forwarded['proto']
                ?? self::firstForwardedValue($server['HTTP_X_FORWARDED_PROTO'] ?? null)
                ?? ($https ? 'https' : 'http');
        } else {
            $scheme = $https ? 'https' : 'http';
        }

        if ($trustForwarded) {
            $host = $forwarded['host']
                ?? self::firstForwardedValue($server['HTTP_X_FORWARDED_HOST'] ?? null)
                ?? (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        } else {
            $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        }

        if (!str_contains($host, ':')) {
            if ($trustForwarded) {
                $port = $forwarded['port']
                    ?? self::firstForwardedValue($server['HTTP_X_FORWARDED_PORT'] ?? null)
                    ?? (isset($server['SERVER_PORT']) ? (string) $server['SERVER_PORT'] : null);
            } else {
                $port = isset($server['SERVER_PORT']) ? (string) $server['SERVER_PORT'] : null;
            }

            if ($port !== null && $port !== '' && !self::isDefaultPortForScheme($scheme, $port)) {
                $host .= ':' . $port;
            }
        }

        return strtolower($scheme) . '://' . $host . $requestUri;
    }

    /**
     * Parse the request body into an associative array.
     *
     * - GET and HEAD never have a body.
     * - application/json → JSON-decoded from $rawBody (or php://input).
     * - form content types → $post passed through directly.
     * - Any other method with no content-type → $post if non-empty, else empty.
     *
     * @param  array<string, string> $headers
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private static function parseBody(
        string $method,
        array $headers,
        array $post,
        ?string $rawBody,
    ): array {
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return [];
        }

        $contentType = $headers['content-type'] ?? '';

        if (self::isJsonContentType($contentType)) {
            $body = $rawBody ?? (string) file_get_contents('php://input');
            if ($body === '') {
                return [];
            }

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        if (
            str_contains($contentType, 'application/x-www-form-urlencoded') ||
            str_contains($contentType, 'multipart/form-data')
        ) {
            return $post;
        }

        // Fallback: use $_POST if populated (PHP may have parsed the body already)
        return $post;
    }

    /**
     * Resolve an HTTP method override for POST requests.
     *
     * HTML forms can only send GET and POST; this convention lets them tunnel
     * PUT, PATCH, or DELETE by specifying the desired method via:
     *   - X-Http-Method-Override header (highest priority, used by AJAX clients).
     *   - `_method` hidden field in the request body.
     *
     * Only applies when the raw method is POST. The override value is uppercased.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed>  $parsedBody
     */
    private static function resolveMethodOverride(
        string $method,
        array $headers,
        array $parsedBody,
    ): string {
        if ($method !== 'POST') {
            return $method;
        }

        $override = $headers['x-http-method-override']
            ?? (isset($parsedBody['_method']) ? (string) $parsedBody['_method'] : null);

        if ($override === null) {
            return $method;
        }

        $candidate = strtoupper(trim($override));
        if (!in_array($candidate, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $method;
        }

        return $candidate;
    }

    /**
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     * @param bool $trustForwarded Whether to honor forwarded headers.
     */
    private static function resolveClientIp(array $server, array $headers, bool $trustForwarded = false): ?string
    {
        if ($trustForwarded) {
            $forwarded = self::parseForwardedHeader(isset($server['HTTP_FORWARDED']) ? (string) $server['HTTP_FORWARDED'] : null);
            $forwardedIp = self::normalizeIp($forwarded['for'] ?? null);
            if ($forwardedIp !== null) {
                return $forwardedIp;
            }

            $headerIp = self::resolveClientIpFromHeaders($headers);
            if ($headerIp !== null) {
                return $headerIp;
            }
        }

        return self::normalizeIp(isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function resolveClientIpFromHeaders(array $headers): ?string
    {
        $forwarded = self::parseForwardedHeader($headers['forwarded'] ?? null);
        $forwardedIp = self::normalizeIp($forwarded['for'] ?? null);
        if ($forwardedIp !== null) {
            return $forwardedIp;
        }

        $forwardedFor = $headers['x-forwarded-for'] ?? null;
        if (is_string($forwardedFor) && $forwardedFor !== '') {
            foreach (explode(',', $forwardedFor) as $candidate) {
                $ip = self::normalizeIp($candidate);
                if ($ip !== null) {
                    return $ip;
                }
            }
        }

        foreach (['x-real-ip', 'cf-connecting-ip', 'x-client-ip'] as $header) {
            $ip = self::normalizeIp($headers[$header] ?? null);
            if ($ip !== null) {
                return $ip;
            }
        }

        return null;
    }

    private static function normalizeIp(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value, " \t\n\r\0\x0B\"");
        if ($trimmed === '' || strtolower($trimmed) === 'unknown') {
            return null;
        }

        if (str_starts_with($trimmed, '[')) {
            $endBracket = strpos($trimmed, ']');
            if ($endBracket !== false) {
                $embedded = substr($trimmed, 1, $endBracket - 1);
                if ($embedded !== false && filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return $embedded;
                }
            }
        }

        if (filter_var($trimmed, FILTER_VALIDATE_IP)) {
            return $trimmed;
        }

        $withoutPort = explode(':', $trimmed)[0] ?? '';
        if ($withoutPort !== '' && filter_var($withoutPort, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $withoutPort;
        }

        return null;
    }

    /**
     * Check if the remote address is in the trusted proxies list.
     *
     * Supports exact IP matching and CIDR notation. The special value '*'
     * trusts all proxies (useful in development).
     *
     * @param string|null $remoteAddr     The REMOTE_ADDR (already normalized).
     * @param string[]    $trustedProxies Trusted IP addresses or CIDR ranges.
     */
    private static function isProxyTrusted(?string $remoteAddr, array $trustedProxies): bool
    {
        if ($trustedProxies === [] || $remoteAddr === null) {
            return false;
        }

        foreach ($trustedProxies as $trusted) {
            if ($trusted === '*') {
                return true;
            }

            if (str_contains($trusted, '/')) {
                if (self::ipInCidr($remoteAddr, $trusted)) {
                    return true;
                }
            } elseif ($remoteAddr === $trusted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP address falls within a CIDR range.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bitsInt = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false; // IPv4 vs IPv6 mismatch.
        }

        // Build mask.
        $totalBits = strlen($ipBin) * 8;
        if ($bitsInt < 0 || $bitsInt > $totalBits) {
            return false;
        }

        $mask = str_repeat("\xff", (int) ($bitsInt / 8));
        if ($bitsInt % 8 !== 0) {
            $mask .= chr(0xff << (8 - ($bitsInt % 8)));
        }
        $mask = str_pad($mask, strlen($ipBin), "\x00");

        return ($ipBin & $mask) === ($subnetBin & $mask);
    }

    private static function firstForwardedValue(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $parts = explode(',', (string) $value);
        $first = trim($parts[0] ?? '');

        return $first === '' ? null : strtolower($first);
    }

    /**
     * @return array{proto?: string, host?: string, port?: string, for?: string}
     */
    private static function parseForwardedHeader(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        $first = trim(explode(',', $header)[0] ?? '');
        if ($first === '') {
            return [];
        }

        $result = [];

        foreach (explode(';', $first) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($name === null || $value === null) {
                continue;
            }

            $key = strtolower(trim($name));
            $normalizedValue = trim($value, " \t\n\r\0\x0B\"");
            if ($normalizedValue === '') {
                continue;
            }

            if ($key === 'for') {
                $result[$key] = $normalizedValue;
                continue;
            }

            if (in_array($key, ['proto', 'host', 'port'], true)) {
                $result[$key] = strtolower($normalizedValue);
            }
        }

        return $result;
    }

    private static function isDefaultPortForScheme(string $scheme, string $port): bool
    {
        $normalizedScheme = strtolower($scheme);

        return ($normalizedScheme === 'http' && $port === '80')
            || ($normalizedScheme === 'https' && $port === '443');
    }

    private static function isJsonContentType(string $contentType): bool
    {
        $normalized = strtolower(trim($contentType));

        if ($normalized === '') {
            return false;
        }

        $mediaType = trim(strtok($normalized, ';') ?: '');

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    /**
     * @param array<string, mixed> $files
     * @return array<string, FileUpload|array<int, FileUpload>>
     */
    private static function normalizeFileUploads(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            try {
                $group = FileUpload::listFromPhpArray($entry);
            } catch (UploadException) {
                continue;
            }

            if ($group === []) {
                continue;
            }

            $normalized[(string) $field] = count($group) === 1 ? $group[0] : $group;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        return $normalized;
    }
}
