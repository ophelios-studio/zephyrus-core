<?php

declare(strict_types=1);

namespace Zephyrus\Http;

use Zephyrus\Upload\FileUpload;
use Zephyrus\Upload\UploadException;

/**
 * Immutable HTTP request value object.
 *
 * Composes structured sub-objects for the major HTTP concerns:
 *   - uri()     → Uri          (parsed URL components)
 *   - body()    → RequestBody  (parsed body data + raw content)
 *   - headers() → HeaderBag    (case-insensitive header lookup)
 *   - cookies() → CookieJar    (cookie value lookup)
 *
 * Query parameters, uploaded files, route attributes, and client IP remain
 * as flat properties with convenience accessors.
 */
final readonly class Request
{
    /**
     * Attribute set by HttpKernel when the request matched NO route, i.e. the
     * response is going to be a 404 or a 405. Its value is always true; the
     * attribute is absent whenever a route did match.
     *
     * It exists so that a global middleware which VALIDATES a request can tell
     * "this request is invalid" from "this request is fine, the URL just does
     * not exist", and decline to answer for a resource that never existed. See
     * CsrfMiddleware for the reference use, and HttpKernel::resolveAndPipe()
     * for where it is set.
     *
     * Middlewares that DECORATE a response (security headers, CSP) must ignore
     * this and keep running, otherwise an error response loses the very headers
     * the pipeline exists to add.
     *
     * The attribute is deliberately never set on a matched route.
     * HandlerResolver injects handler arguments positionally from
     * $request->attributes, so an extra entry there would shift that binding.
     */
    public const ATTRIBUTE_UNMATCHED_ROUTE = '_zephyrus.unmatched_route';

    private Uri $uri;
    private RequestBody $body;
    private HeaderBag $headerBag;
    private CookieJar $cookieJar;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $attributes
     * @param array<string, FileUpload|array<int, FileUpload>> $files
     */
    public function __construct(
        public string $method,
        Uri|string $uri,
        RequestBody|array $body = [],
        public array $query = [],
        HeaderBag|array $headers = [],
        CookieJar|array $cookies = [],
        public array $attributes = [],
        public array $files = [],
        public ?string $clientIp = null,
        string $rawBody = '',
    ) {
        $this->uri = $uri instanceof Uri ? $uri : new Uri($uri);
        $this->body = $body instanceof RequestBody
            ? $body
            : new RequestBody($body, $rawBody);
        $this->headerBag = $headers instanceof HeaderBag
            ? $headers
            : new HeaderBag(self::normalizeHeaders($headers));
        $this->cookieJar = $cookies instanceof CookieJar
            ? $cookies
            : new CookieJar($cookies);
    }

    /**
     * Build a Request from PHP superglobals. This is the primary entry point
     * for production use in public/index.php.
     *
     * @param array<string, mixed>|null  $server
     * @param array<string, mixed>|null  $get
     * @param array<string, mixed>|null  $post
     * @param array<string, string>|null $cookie
     * @param array<string, mixed>|null  $files
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

        $raw = $rawBody ?? (string) file_get_contents('php://input');
        $parsedBody = self::parseBody($method, $headers, $post, $raw);
        $method     = self::resolveMethodOverride($method, $headers, $parsedBody);

        return new self(
            method:     $method,
            uri:        new Uri($uri),
            body:       new RequestBody($parsedBody, $raw),
            query:      $get,
            headers:    new HeaderBag($headers),
            cookies:    new CookieJar($cookie),
            attributes: [],
            files:      self::normalizeFileUploads($files),
            clientIp:   $clientIp,
        );
    }

    /**
     * Convenience factory for testing. Accepts plain arrays for all parameters
     * and constructs sub-objects internally.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     * @param array<string, FileUpload|array<int, FileUpload>> $files
     */
    public static function fromArray(
        string $method,
        string $uri,
        array $body = [],
        array $query = [],
        array $headers = [],
        array $cookies = [],
        array $attributes = [],
        array $files = [],
        ?string $clientIp = null,
        string $rawBody = '',
    ): self {
        return new self(
            method:     strtoupper($method),
            uri:        new Uri(self::canonicalizeRequestTarget($uri)),
            body:       new RequestBody($body, $rawBody),
            query:      $query,
            headers:    new HeaderBag(self::normalizeHeaders($headers)),
            cookies:    new CookieJar($cookies),
            attributes: $attributes,
            files:      $files,
            clientIp:   $clientIp,
        );
    }

    // ------------------------------------------------------------------
    // Sub-object accessors
    // ------------------------------------------------------------------

    public function uri(): Uri
    {
        return $this->uri;
    }

    /**
     * The canonical request path, i.e. the exact path the router dispatches on.
     *
     * ALWAYS PREFER THIS over uri()->path() for any decision about a request:
     * guards, allowlists, exclusion patterns, rate-limit keys, audit records.
     * The two used to be able to disagree, and a leading "//" was enough to do
     * it: uri()->path() reported "//x/admin/secret" while the router dispatched
     * "/admin/secret", so a path-based guard inspected one route and a different
     * one executed. See canonicalizeRequestTarget() for the mechanism.
     *
     * Both entry points, fromGlobals() and fromArray(), canonicalise before the
     * Uri is built, so in practice this equals uri()->path(). It normalises
     * again here so the guarantee also holds for a Request assembled by hand
     * through the constructor.
     */
    public function path(): string
    {
        return self::canonicalizeRequestTarget($this->uri->path());
    }

    public function body(): RequestBody
    {
        return $this->body;
    }

    public function headers(): HeaderBag
    {
        return $this->headerBag;
    }

    public function cookies(): CookieJar
    {
        return $this->cookieJar;
    }

    // ------------------------------------------------------------------
    // Flat accessors (query, files, attributes, method, client IP)
    // ------------------------------------------------------------------

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
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

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function clientIp(?string $default = null): ?string
    {
        if ($this->clientIp !== null) {
            return $this->clientIp;
        }

        $attributeValue = $this->attributes['client_ip'] ?? null;
        if (is_string($attributeValue)) {
            $normalized = self::normalizeIp($attributeValue);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return $default;
    }

    // ------------------------------------------------------------------
    // Convenience accessors
    // ------------------------------------------------------------------

    /**
     * Get a parameter from the request body first, then query string.
     */
    public function getParameter(string $name, mixed $default = null): mixed
    {
        if ($this->body->has($name)) {
            return $this->body->get($name);
        }
        return $this->query[$name] ?? $default;
    }

    /**
     * Get all parameters merged from body and query.
     */
    public function getParameters(): array
    {
        return array_merge($this->query, $this->body->all());
    }

    /**
     * Get a header value by name.
     */
    public function getHeader(string $name, ?string $default = null): ?string
    {
        return $this->headerBag->get($name, $default);
    }

    // ------------------------------------------------------------------
    // Immutable attribute mutation
    // ------------------------------------------------------------------

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            method:     $this->method,
            uri:        $this->uri,
            body:       $this->body,
            query:      $this->query,
            headers:    $this->headerBag,
            cookies:    $this->cookieJar,
            attributes: $attributes,
            files:      $this->files,
            clientIp:   $this->clientIp,
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
            body:       $this->body,
            query:      $this->query,
            headers:    $this->headerBag,
            cookies:    $this->cookieJar,
            attributes: array_merge($this->attributes, $attributes),
            files:      $this->files,
            clientIp:   $this->clientIp,
        );
    }

    // ------------------------------------------------------------------
    // Private: fromGlobals helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $server
     * @return array<string, string>
     */
    private static function extractHeadersFromServer(array $server): array
    {
        $headers = [];

        $unprefixed = ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'];
        foreach ($unprefixed as $key) {
            if (isset($server[$key]) && $server[$key] !== '') {
                $name = strtolower(str_replace('_', '-', $key));
                $headers[$name] = (string) $server[$key];
            }
        }

        foreach ($server as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * @param array<string, mixed> $server
     */
    /**
     * Collapse a leading run of slashes in an origin-form request target.
     *
     * WHY THIS EXISTS. The path was being parsed twice, by two callers, from
     * two different strings, and they disagreed:
     *
     *   - Here, parse_url() runs on the FULL url ("scheme://host" . target), so
     *     "//x/admin/secret" keeps its leading slashes and uri()->path()
     *     reports "//x/admin/secret".
     *   - RouteCollection::normalizePath() runs parse_url() on the BARE path,
     *     where a leading "//token" reads as an AUTHORITY, so it returned
     *     "/admin/secret" and dispatched the protected route.
     *
     * The request therefore executed one route while every path-based check saw
     * another: a guard doing str_starts_with($request->path(), '/admin') was
     * handed "//x/admin/secret", returned false, and waved the request through
     * to /admin/secret. The framework's own CsrfMiddleware was bypassable this
     * way whenever an exclusion pattern was unanchored.
     *
     * Collapsing was chosen over rejecting the target. It is a normalisation
     * rather than a new failure path, so nothing that used to be served starts
     * erroring: request construction happens at the very top of the lifecycle,
     * before the kernel's error handling exists, which is a poor place to
     * introduce a throw. After collapsing, "//x/admin/secret" resolves to
     * "/x/admin/secret" and 404s, and "//admin" resolves to "/admin" where the
     * guard now sees "/admin" and blocks correctly. Either way the two views
     * agree, which is the property that actually closes the hole.
     *
     * No legitimate client sends a "//"-prefixed origin-form target, so no real
     * request changes behaviour. Interior duplicate slashes ("/a//b") are left
     * alone: both parsers already agree on those, so there is nothing to fix.
     */
    private static function canonicalizeRequestTarget(string $requestUri): string
    {
        if (!str_starts_with($requestUri, '//')) {
            return $requestUri;
        }

        return '/' . ltrim($requestUri, '/');
    }

    private static function buildUri(array $server, bool $trustForwarded = false): string
    {
        $requestUri = (string) ($server['REQUEST_URI'] ?? '/');

        if (str_starts_with($requestUri, 'http://') || str_starts_with($requestUri, 'https://')) {
            return $requestUri;
        }

        $requestUri = self::canonicalizeRequestTarget($requestUri);

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
     * @param  array<string, string> $headers
     * @param  array<string, mixed>  $post
     * @return array<string, mixed>
     */
    private static function parseBody(
        string $method,
        array $headers,
        array $post,
        string $rawBody,
    ): array {
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return [];
        }

        $contentType = $headers['content-type'] ?? '';

        if (self::isJsonContentType($contentType)) {
            if ($rawBody === '') {
                return [];
            }

            try {
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
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

        return $post;
    }

    /**
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
     * @param string[] $trustedProxies
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
            return false;
        }

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
