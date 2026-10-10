<?php

declare(strict_types=1);

namespace Zephyrus\Http;

use Zephyrus\Routing\Route;
use Zephyrus\Routing\RouteMatch;
use Zephyrus\Upload\FileUpload;
use Zephyrus\Upload\UploadException;

/**
 * Immutable HTTP request value object.
 *
 * Exposes uri(), body(), headers() and cookies() as sub-objects. Query
 * parameters, uploaded files, route attributes and client IP are flat
 * properties with convenience accessors.
 */
final readonly class Request
{
    /**
     * Set to true by HttpKernel when no route matched (the response is a 404 or 405).
     *
     * Validating middlewares can read it to decline answering for a resource that
     * does not exist. Decorating middlewares (security headers, CSP) must ignore it
     * and keep running, so error responses still get their headers. Never set on a
     * matched route. See CsrfMiddleware for the reference use.
     */
    public const ATTRIBUTE_UNMATCHED_ROUTE = '_zephyrus.unmatched_route';

    /**
     * The placeholders this request took from its URL, keyed by name. Empty when no route matched
     * or the Request was built outside the kernel; use withRouteParameters() in tests.
     *
     * They are also merged into $attributes, so a guard must check isRouteParameter()
     * before trusting an attribute. Provenance is kept by name and survives an
     * overwrite: a later middleware setting the same name does not make the URL value
     * safe. Route registration refuses a placeholder named after a framework attribute;
     * see Route::RESERVED_PARAMETER_NAMES. See RequestAttributeGuard.
     *
     * @var array<string, string>
     */

    /**
     * Forwarding headers read by default once REMOTE_ADDR is a trusted proxy: the X-Forwarded-* family only.
     *
     * A proxy overwrites the family it manages and passes other forwarding headers
     * through, so reading one of those would trust a value the caller controls. Opt
     * into Forwarded or a vendor header by name through $trustedHeaders.
     */
    public const TRUSTED_HEADERS_DEFAULT = [
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',
    ];

    /**
     * Every forwarding header this class can read. Configuration rejects any other
     * name (SecurityConfig::fromArray()), since an unknown name would have no effect.
     */
    public const TRUSTED_HEADERS_SUPPORTED = [
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',
        'forwarded',
        'x-real-ip',
        'cf-connecting-ip',
        'x-client-ip',
    ];

    /** @var array<string, mixed> */
    public array $query;
    /** @var array<string, FileUpload|array<int, FileUpload>> */
    public array $files;
    private Uri $uri;
    private RequestBody $body;
    private HeaderBag $headerBag;
    private CookieJar $cookieJar;

    /**
     * @param RequestBody|array<string, mixed> $body
     * @param array<string, mixed>|null $query Null derives the query from the URI.
     * @param HeaderBag|array<string, mixed> $headers
     * @param CookieJar|array<string, string> $cookies
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $files Native $_FILES entries or FileUpload values.
     * @param array<string, string> $routeParameters Placeholders taken from the URL, see $routeParameters.
     * @param Route|null $matchedRoute The route this request was dispatched to, see route().
     */
    public function __construct(
        public string $method,
        Uri|string $uri,
        RequestBody|array $body = [],
        ?array $query = null,
        HeaderBag|array $headers = [],
        CookieJar|array $cookies = [],
        public array $attributes = [],
        array $files = [],
        public ?string $clientIp = null,
        string $rawBody = '',
        public array $routeParameters = [],
        private ?Route $matchedRoute = null,
    ) {
        $this->uri = self::canonicalizeUri($uri);
        $this->files = self::normalizeFileUploads($files);
        $this->query = $query ?? self::parseQueryString($this->uri->queryString());
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
     * @param string[]                   $trustedHeaders Which forwarding headers may be read once
     *                                                   the peer is a trusted proxy. Names are
     *                                                   case-insensitive. Defaults to the
     *                                                   X-Forwarded-* family; pass [] to read none.
     */
    public static function fromGlobals(
        ?array $server = null,
        ?array $get = null,
        ?array $post = null,
        ?array $cookie = null,
        ?array $files = null,
        ?string $rawBody = null,
        array $trustedProxies = [],
        array $trustedHeaders = self::TRUSTED_HEADERS_DEFAULT,
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
        // An untrusted peer yields an empty header allowlist, so downstream code checks one gate.
        $trusted = $trustForwarded ? self::normalizeTrustedHeaders($trustedHeaders) : [];
        $uri     = self::buildUri($server, $trusted, $trustedProxies);
        $clientIp = self::resolveClientIp($server, $headers, $trustForwarded, $trustedProxies, $trusted);

        $raw = $rawBody ?? (string) file_get_contents('php://input');
        $malformed  = false;
        $parsedBody = self::parseBody($method, $headers, $post, $raw, $malformed);
        $method     = self::resolveMethodOverride($method, $headers, $parsedBody);

        return new self(
            method:     $method,
            uri:        $uri,
            body:       new RequestBody($parsedBody, $raw, $malformed),
            query:      $get,
            headers:    new HeaderBag($headers),
            cookies:    new CookieJar($cookie),
            attributes: [],
            files:      $files,
            clientIp:   $clientIp,
        );
    }

    /**
     * Convenience factory for testing. Accepts plain arrays for all parameters
     * and constructs sub-objects internally. It does not parse $rawBody: to test
     * a malformed body, use the constructor with `new RequestBody([], $raw, malformed: true)`.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $query Null derives the query from the URI.
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $files Native $_FILES entries or FileUpload values.
     *
     * @throws \InvalidArgumentException When a file field holds no readable upload.
     */
    public static function fromArray(
        string $method,
        string $uri,
        array $body = [],
        ?array $query = null,
        array $headers = [],
        array $cookies = [],
        array $attributes = [],
        array $files = [],
        ?string $clientIp = null,
        string $rawBody = '',
    ): self {
        return new self(
            method:     strtoupper($method),
            uri:        $uri,
            body:       new RequestBody($body, $rawBody),
            query:      $query,
            headers:    new HeaderBag(self::normalizeHeaders($headers)),
            cookies:    new CookieJar($cookies),
            attributes: $attributes,
            files:      self::normalizeFileUploads($files, strict: true),
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
     * The request path as sent: still percent-encoded, with a leading run of slashes collapsed.
     *
     * Use this rather than uri()->path() for any decision (guards, allowlists, rate-limit
     * keys): a literal route segment is matched against these raw bytes, so a prefix
     * check here sees what the router dispatches. A parameter segment stays encoded
     * here, so read its decoded value with routeParameter(). A trailing slash is kept
     * here; the router ignores it unless trailing-slash tolerance is off.
     */
    public function path(): string
    {
        return self::collapseLeadingSlashes($this->uri->path());
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
        return $this->filesOf($field)[0] ?? null;
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

    /**
     * The value this request took from its URL under $name, or $default.
     *
     * Unlike attribute(), it never returns a value a middleware published.
     */
    public function routeParameter(string $name, ?string $default = null): ?string
    {
        return $this->routeParameters[$name] ?? $default;
    }

    /**
     * Whether $name was supplied by a URL segment of the matched route.
     *
     * An authorisation decision keyed on an attribute must check this first: a URL
     * value says nothing about the caller. See RequestAttributeGuard.
     */
    public function isRouteParameter(string $name): bool
    {
        return array_key_exists($name, $this->routeParameters);
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * The client IP: REMOTE_ADDR for an untrusted peer, or $default when the client is unknown.
     */
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
     *
     * @return array<string, mixed>
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
            method:          $this->method,
            uri:             $this->uri,
            body:            $this->body,
            query:           $this->query,
            headers:         $this->headerBag,
            cookies:         $this->cookieJar,
            attributes:      $attributes,
            files:           $this->files,
            clientIp:        $this->clientIp,
            routeParameters: $this->routeParameters,
            matchedRoute:    $this->matchedRoute,
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            method:          $this->method,
            uri:             $this->uri,
            body:            $this->body,
            query:           $this->query,
            headers:         $this->headerBag,
            cookies:         $this->cookieJar,
            attributes:      array_merge($this->attributes, $attributes),
            files:           $this->files,
            clientIp:        $this->clientIp,
            routeParameters: $this->routeParameters,
            matchedRoute:    $this->matchedRoute,
        );
    }

    /**
     * Publish placeholders into $attributes and record them in $routeParameters.
     *
     * @param array<string, string> $parameters
     */
    public function withRouteParameters(array $parameters): self
    {
        return $this->withPublishedParameters($parameters, $this->matchedRoute);
    }

    /**
     * Record the matched route and publish its parameters, as withRouteParameters() does.
     */
    public function withMatchedRoute(RouteMatch $match): self
    {
        return $this->withPublishedParameters($match->parameters, $match->route);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function withPublishedParameters(array $parameters, ?Route $route): self
    {
        return new self(
            method:          $this->method,
            uri:             $this->uri,
            body:            $this->body,
            query:           $this->query,
            headers:         $this->headerBag,
            cookies:         $this->cookieJar,
            attributes:      array_merge($this->attributes, $parameters),
            files:           $this->files,
            clientIp:        $this->clientIp,
            routeParameters: array_merge($this->routeParameters, $parameters),
            matchedRoute:    $route,
        );
    }

    /**
     * The route this request was dispatched to, or null before routing. Use it
     * to identify the handler's route; path() keeps spelling the URL as sent.
     */
    public function route(): ?Route
    {
        return $this->matchedRoute;
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
     * Canonicalise the URI of every Request, whichever construction path it came from.
     *
     * An already canonical Uri is returned unchanged.
     */
    private static function canonicalizeUri(Uri|string $uri): Uri
    {
        if (!$uri instanceof Uri) {
            return new Uri(self::canonicalizeUrl($uri));
        }

        $canonical = self::canonicalizeUrl($uri->full());

        return $canonical === $uri->full() ? $uri : new Uri($canonical);
    }

    /**
     * @return array<string, mixed>
     */
    private static function parseQueryString(string $queryString): array
    {
        parse_str($queryString, $query);

        return $query;
    }

    /**
     * Canonicalise an origin-form target ("/admin") or an absolute URL ("https://host/admin").
     *
     * Only the path is touched: the "//" after a scheme is structural and must survive.
     */
    private static function canonicalizeUrl(string $url): string
    {
        // Absolute form, anchored so a query value such as
        // "/redirect?to=http://elsewhere" is not mistaken for a scheme.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $url, $matches) !== 1) {
            return self::collapseLeadingSlashes($url);
        }

        $authorityStart = strlen($matches[0]);
        $pathStart = $authorityStart + strcspn($url, '/?#', $authorityStart);

        return substr($url, 0, $pathStart) . self::collapseLeadingSlashes(substr($url, $pathStart));
    }

    /**
     * Collapse a leading run of slashes in an origin-form request target.
     *
     * Same collapse as RouteCollection::normalizePath(): on a bare path parse_url() reads a
     * leading "//token" as an authority, so path() and the dispatched route would otherwise
     * disagree. Collapsing rather than rejecting keeps request construction free of a new
     * failure path. Interior duplicate slashes are left alone.
     */
    private static function collapseLeadingSlashes(string $requestUri): string
    {
        if (!str_starts_with($requestUri, '//')) {
            return $requestUri;
        }

        return '/' . ltrim($requestUri, '/');
    }

    /**
     * Build the absolute request URL from the server values and the forwarded headers.
     *
     * Forwarded inputs are gated by $trustedHeaders, the same allowlist as the client
     * IP. An empty list means nothing is read forwarded. X-Forwarded-Host, -Proto and
     * -Port keep their first value, so the proxy in front must overwrite them, not append.
     *
     * @param array<string, mixed> $server
     * @param list<string>         $trustedHeaders
     * @param string[]             $trustedProxies
     */
    private static function buildUri(array $server, array $trustedHeaders = [], array $trustedProxies = []): string
    {
        $requestUri = (string) ($server['REQUEST_URI'] ?? '/');

        if (preg_match('#^(https?)://#i', $requestUri, $matches) === 1) {
            return strtolower($matches[1]) . '://' . substr($requestUri, strlen($matches[0]));
        }

        if ($requestUri === '*') {
            $requestUri = '/';
        } elseif (!str_starts_with($requestUri, '/')) {
            $requestUri = '/' . $requestUri;
        }

        $forwarded = in_array('forwarded', $trustedHeaders, true)
            ? self::parseForwardedHeader(
                isset($server['HTTP_FORWARDED']) ? (string) $server['HTTP_FORWARDED'] : null,
                $trustedProxies,
            )
            : [];
        if ($forwarded === null) {
            $forwarded      = [];
            $trustedHeaders = [];
        }

        $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && $server['HTTPS'] !== 'off';

        $forwardedScheme = self::httpScheme($forwarded['proto'] ?? null)
            ?? (in_array('x-forwarded-proto', $trustedHeaders, true)
                ? self::httpScheme(self::firstForwardedValue($server['HTTP_X_FORWARDED_PROTO'] ?? null))
                : null);

        $scheme = $forwardedScheme ?? ($https ? 'https' : 'http');

        $host = self::authorityHost($forwarded['host']
            ?? (in_array('x-forwarded-host', $trustedHeaders, true)
                ? self::firstForwardedValue($server['HTTP_X_FORWARDED_HOST'] ?? null)
                : null)
            ?? (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost'));

        if (!str_contains($host, ':')) {
            // SERVER_PORT is the hop to the proxy, so it is ignored when a forwarded scheme is present.
            $port = self::portNumber($forwarded['port'] ?? null)
                ?? (in_array('x-forwarded-port', $trustedHeaders, true)
                    ? self::portNumber(self::firstForwardedValue($server['HTTP_X_FORWARDED_PORT'] ?? null))
                    : null)
                ?? ($forwardedScheme === null
                    ? self::portNumber(isset($server['SERVER_PORT']) ? (string) $server['SERVER_PORT'] : null)
                    : null);

            if ($port !== null && !self::isDefaultPortForScheme($scheme, $port)) {
                $host .= ':' . $port;
            }
        }

        return $scheme . '://' . $host . $requestUri;
    }

    /**
     * Returns the host unchanged when it is a host name or bracketed IP literal, with
     * its port only when portNumber() accepts it. Any other value is percent-encoded whole.
     */
    private static function authorityHost(string $host): string
    {
        if (preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9._~-]+)(?::([0-9]+))?$/D', $host, $matches) !== 1) {
            return rawurlencode($host);
        }

        if (isset($matches[2]) && self::portNumber($matches[2]) === null) {
            return $matches[1];
        }

        return $host;
    }

    /**
     * The port when it is a decimal number without leading zeros, at most 65535, otherwise null.
     */
    private static function portNumber(?string $port): ?string
    {
        if ($port === null || preg_match('/^[1-9][0-9]{0,4}$/D', $port) !== 1 || (int) $port > 65535) {
            return null;
        }

        return $port;
    }

    /**
     * The scheme when it is http or https, otherwise null.
     *
     * @return 'http'|'https'|null
     */
    private static function httpScheme(?string $scheme): ?string
    {
        $scheme = strtolower((string) $scheme);

        return in_array($scheme, ['http', 'https'], true) ? $scheme : null;
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
        bool &$malformed,
    ): array {
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return [];
        }

        $contentType = $headers['content-type'] ?? '';

        if (self::isJsonContentType($contentType)) {
            if (trim($rawBody, " \t\n\r") === '') {
                return [];
            }

            try {
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $malformed = true;

                return [];
            }

            if (!is_array($decoded)) {
                $malformed = true;

                return [];
            }

            return $decoded;
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

        // A non-scalar body override is ignored: casting it would raise an E_WARNING
        // ("Array to string conversion") before the kernel's error handling exists.
        $bodyOverride = $parsedBody['_method'] ?? null;

        $override = $headers['x-http-method-override']
            ?? (is_scalar($bodyOverride) ? (string) $bodyOverride : null);

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
     * Resolve the IP of the original caller, for rate limiting, allowlists and audit records.
     *
     * Headers are read only when REMOTE_ADDR is a trusted proxy and the header is in
     * $trustedHeaders. The forwarded chain is walked from the right, skipping trusted
     * proxies: a conforming proxy appends to the right, so a caller can only prepend.
     * The first untrusted hop is the client. The leftmost entry is the answer only
     * when every hop is a trusted proxy. A malformed Forwarded header yields null, not
     * REMOTE_ADDR, so an allowlist denies it.
     *
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     * @param string[]              $trustedProxies
     * @param list<string>          $trustedHeaders already normalized and gated on the peer
     */
    private static function resolveClientIp(
        array $server,
        array $headers,
        bool $trustForwarded = false,
        array $trustedProxies = [],
        array $trustedHeaders = [],
    ): ?string {
        $remoteAddr = self::normalizeIp(isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null);

        if (!$trustForwarded) {
            return $remoteAddr;
        }

        $chain = self::forwardedChain($headers, $trustedHeaders);
        if ($chain === null) {
            return null;
        }

        if ($chain !== []) {
            if ($remoteAddr !== null) {
                $chain[] = $remoteAddr;
            }

            return self::walkForwardedChain($chain, $trustedProxies);
        }

        // Single-value vendor headers are read only when no chain header yielded a hop. They
        // are trusted only if the nearest proxy overwrites them, which is why they are not in TRUSTED_HEADERS_DEFAULT.
        foreach (['x-real-ip', 'cf-connecting-ip', 'x-client-ip'] as $header) {
            if (!in_array($header, $trustedHeaders, true)) {
                continue;
            }

            $ip = self::normalizeIp($headers[$header] ?? null);
            if ($ip !== null) {
                return $ip;
            }
        }

        return $remoteAddr;
    }

    /**
     * The forwarded hops as IP addresses, outermost first, without REMOTE_ADDR.
     *
     * RFC 7239 Forwarded wins over X-Forwarded-For when both yield hops. Entries that
     * are not IPs are dropped, not fatal: the walk never reaches junk left of the client.
     * A malformed Forwarded header yields null, and the caller must not fall back to other headers.
     *
     * @param  array<string, string> $headers
     * @param  list<string>          $trustedHeaders
     * @return list<string>|null
     */
    private static function forwardedChain(array $headers, array $trustedHeaders): ?array
    {
        $chain = [];

        $forwarded = in_array('forwarded', $trustedHeaders, true) ? ($headers['forwarded'] ?? null) : null;
        if (is_string($forwarded)) {
            $elements = self::splitOutsideQuotes($forwarded, ',');
            if ($elements === null) {
                return null;
            }

            foreach ($elements as $element) {
                $ip = self::normalizeIp(self::parseForwardedElement($element)['for'] ?? null);
                if ($ip !== null) {
                    $chain[] = $ip;
                }
            }
        }

        if ($chain !== []) {
            return $chain;
        }

        $forwardedFor = in_array('x-forwarded-for', $trustedHeaders, true)
            ? ($headers['x-forwarded-for'] ?? null)
            : null;
        if (is_string($forwardedFor)) {
            foreach (explode(',', $forwardedFor) as $candidate) {
                $ip = self::normalizeIp($candidate);
                if ($ip !== null) {
                    $chain[] = $ip;
                }
            }
        }

        return $chain;
    }

    /**
     * Return the outermost hop that is not a trusted proxy, scanning from the innermost end.
     *
     * Hops are popped by membership in $trustedProxies, not by count, so adding or removing a proxy layer needs no change.
     *
     * @param list<string> $chain          outermost first, innermost last
     * @param string[]     $trustedProxies
     */
    private static function walkForwardedChain(array $chain, array $trustedProxies): ?string
    {
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!self::isProxyTrusted($chain[$i], $trustedProxies)) {
                return $chain[$i];
            }
        }

        // Every hop is trusted, so the outermost entry is the best answer available (also for ['*']).
        return $chain[0] ?? null;
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
                if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
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
     * Lowercase, trim and de-duplicate the header names, dropping any not in TRUSTED_HEADERS_SUPPORTED.
     *
     * Dropping is safe because an unknown name enables nothing; SecurityConfig::fromArray()
     * rejects it at boot, so the typo is reported there.
     *
     * @param  array<mixed> $trustedHeaders
     * @return list<string>
     */
    private static function normalizeTrustedHeaders(array $trustedHeaders): array
    {
        $normalized = [];

        foreach ($trustedHeaders as $header) {
            if (!is_string($header)) {
                continue;
            }

            $name = strtolower(trim($header));
            if (!in_array($name, self::TRUSTED_HEADERS_SUPPORTED, true)) {
                continue;
            }

            if (!in_array($name, $normalized, true)) {
                $normalized[] = $name;
            }
        }

        return $normalized;
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

            if (IpRange::contains($trusted, $remoteAddr)) {
                return true;
            }
        }

        return false;
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
     * The proto, host and port of the Forwarded element that the outermost trusted proxy appended, for buildUri().
     *
     * The walk starts from the right, where a conforming proxy appends: a caller can only
     * prepend. It stops at the first element whose "for" is not a
     * trusted proxy. When every element is trusted, the first one wins. An unclosed quoted string yields null.
     *
     * @param string[] $trustedProxies
     * @return array{proto?: string, host?: string, port?: string}|null
     */
    private static function parseForwardedHeader(?string $header, array $trustedProxies): ?array
    {
        $elements = self::splitOutsideQuotes($header ?? '', ',');
        if ($elements === null) {
            return null;
        }

        $parameters = [];
        for ($i = count($elements) - 1; $i >= 0; $i--) {
            if (trim($elements[$i]) === '') {
                continue;
            }

            $parameters = self::parseForwardedElement($elements[$i]);
            $for        = self::normalizeIp($parameters['for'] ?? null);
            if ($for === null || !self::isProxyTrusted($for, $trustedProxies)) {
                break;
            }
        }

        $result = [];
        foreach (['proto', 'host', 'port'] as $key) {
            if (isset($parameters[$key])) {
                $result[$key] = strtolower($parameters[$key]);
            }
        }

        return $result;
    }

    /**
     * Split on a separator that sits outside quoted strings. A backslash inside
     * quotes escapes the next character. Returns null when a quoted string is left open.
     *
     * @return list<string>|null
     */
    private static function splitOutsideQuotes(string $value, string $separator): ?array
    {
        $parts    = [];
        $current  = '';
        $inQuotes = false;
        $length   = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($inQuotes && $char === '\\' && $i + 1 < $length) {
                $current .= $char . $value[++$i];
                continue;
            }

            if ($char === '"') {
                $inQuotes = !$inQuotes;
            } elseif ($char === $separator && !$inQuotes) {
                $parts[]  = $current;
                $current  = '';
                continue;
            }

            $current .= $char;
        }

        if ($inQuotes) {
            return null;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Parse one RFC 7239 forwarding element ("for=1.2.3.4;proto=https") into lowercased names mapped to unquoted values.
     *
     * @return array<string, string>
     */
    private static function parseForwardedElement(string $element): array
    {
        $parameters = [];

        foreach (self::splitOutsideQuotes($element, ';') ?? [] as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, null);
            if ($name === null || $value === null) {
                continue;
            }

            $key = strtolower(trim($name));
            $normalizedValue = trim($value, " \t\n\r\0\x0B\"");
            if ($key === '' || $normalizedValue === '') {
                continue;
            }

            $parameters[$key] = $normalizedValue;
        }

        return $parameters;
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
     * @param bool $strict Throw on an unreadable entry instead of skipping it.
     * @return array<string, FileUpload|array<int, FileUpload>>
     *
     * @throws \InvalidArgumentException When $strict and a field holds no readable upload.
     */
    private static function normalizeFileUploads(array $files, bool $strict = false): array
    {
        $normalized = [];

        foreach ($files as $field => $entry) {
            $uploads = self::uploadsOf($entry);

            if ($uploads === null) {
                if ($strict) {
                    throw new \InvalidArgumentException(sprintf('File field "%s" holds no readable upload.', $field));
                }

                continue;
            }

            $normalized[(string) $field] = $uploads;
        }

        return $normalized;
    }

    /**
     * @return FileUpload|list<FileUpload>|null Null when the entry is not an upload.
     */
    private static function uploadsOf(mixed $entry): FileUpload|array|null
    {
        if ($entry instanceof FileUpload) {
            return $entry;
        }

        if (!is_array($entry)) {
            return null;
        }

        $uploads = self::uploadObjectsOf($entry);
        if ($uploads !== null) {
            return $uploads;
        }

        try {
            $group = FileUpload::listFromPhpArray($entry);
        } catch (UploadException) {
            return null;
        }

        if ($group === []) {
            return null;
        }

        return count($group) === 1 ? $group[0] : $group;
    }

    /**
     * @param array<mixed> $entry
     * @return list<FileUpload>|null The FileUpload items, or null when there are none.
     */
    private static function uploadObjectsOf(array $entry): ?array
    {
        $uploads = array_values(array_filter($entry, static fn (mixed $item): bool => $item instanceof FileUpload));

        return $uploads === [] ? null : $uploads;
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
