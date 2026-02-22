<?php

declare(strict_types=1);

namespace Zephyrus\Http;

final readonly class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $method,
        public string $uri,
        public array $query = [],
        public array $parsedBody = [],
        public array $headers = [],
        public array $cookies = [],
        public array $attributes = [],
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
     * @param string|null                $rawBody  Injected for testing; defaults to php://input.
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $get = null,
        ?array $post = null,
        ?array $cookie = null,
        ?string $rawBody = null,
    ): self {
        $server = $server ?? $_SERVER;
        $get    = $get    ?? $_GET;
        $post   = $post   ?? $_POST;
        $cookie = $cookie ?? $_COOKIE;

        $method  = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        $headers = self::extractHeadersFromServer($server);
        $uri     = self::buildUri($server);

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
        );
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $parsedBody
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     */
    public static function fromArray(
        string $method,
        string $uri,
        array $query = [],
        array $parsedBody = [],
        array $headers = [],
        array $cookies = [],
        array $attributes = [],
    ): self {
        return new self(
            method:     strtoupper($method),
            uri:        $uri,
            query:      $query,
            parsedBody: $parsedBody,
            headers:    self::normalizeHeaders($headers),
            cookies:    $cookies,
            attributes: $attributes,
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

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
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
        return str_contains($this->headers['content-type'] ?? '', 'application/json');
    }

    public function isSecure(): bool
    {
        return str_starts_with($this->uri, 'https://');
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
        );
    }

    /**
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
            attributes: $attributes,
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
     */
    private static function buildUri(array $server): string
    {
        $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && $server['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host   = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');

        $requestUri = (string) ($server['REQUEST_URI'] ?? '/');

        // When behind a reverse proxy, REQUEST_URI may already be absolute
        if (str_starts_with($requestUri, 'http://') || str_starts_with($requestUri, 'https://')) {
            return $requestUri;
        }

        return $scheme . '://' . $host . $requestUri;
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

        if (str_contains($contentType, 'application/json')) {
            $body = $rawBody ?? (string) file_get_contents('php://input');
            if ($body === '') {
                return [];
            }
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
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

        return $override !== null ? strtoupper($override) : $method;
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
