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
     * The attribute is deliberately never set on a matched route: it describes
     * a routing FAILURE, and a request that matched has none to describe.
     *
     * That is now a statement of meaning rather than a precaution.
     * HandlerResolver used to fall back to injecting handler arguments by
     * position over ALL of $request->attributes, so any extra entry shifted
     * that binding; its positional pool is the matched route's own parameters
     * now, and an attribute cannot reach a handler except by name.
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
        $this->uri = self::canonicalizeUri($uri);
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
        $clientIp = self::resolveClientIp($server, $headers, $trustForwarded, $trustedProxies);

        $raw = $rawBody ?? (string) file_get_contents('php://input');
        $parsedBody = self::parseBody($method, $headers, $post, $raw);
        $method     = self::resolveMethodOverride($method, $headers, $parsedBody);

        return new self(
            method:     $method,
            uri:        $uri,
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
            uri:        $uri,
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
     * one executed. See canonicalizeUrl() for the mechanism.
     *
     * Every construction path canonicalises through the constructor, so this
     * equals uri()->path() for any Request that exists. The extra normalisation
     * here is belt and braces and is a no-op in practice.
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
     * THE single canonicalization point. Every Request funnels through here,
     * because every construction path ends at the constructor: fromGlobals(),
     * fromArray(), the with*() clones, and a hand-rolled `new Request(...)`.
     *
     * It was three separate call sites before, one per entry point, and that is
     * how fromArray() came to disagree with fromGlobals() for the same request:
     * the absolute-URL branch simply never called it. Three places that each
     * have to remember a rule is the same hazard the rule exists to fix.
     *
     * An already-canonical Uri is returned untouched rather than rebuilt, so
     * the with*() clones cost nothing.
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
     * Canonicalize a URL in either form: an origin-form request target
     * ("/admin", "//x/admin") or an absolute URL ("https://host//x/admin").
     *
     * Only the PATH component is touched. The "//" separating a scheme from its
     * authority is structural and must survive, which is the whole reason this
     * cannot just collapse leading slashes on the raw string.
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
    private static function collapseLeadingSlashes(string $requestUri): string
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
     * Resolve the IP of the ORIGINAL caller, i.e. the value a rate limiter, an
     * allowlist or an audit record should key on.
     *
     * A forwarding header is only ever read when the peer we actually spoke to
     * (REMOTE_ADDR) is a configured trusted proxy. REMOTE_ADDR is the one address
     * the SAPI hands us that a caller cannot forge; every header can be sent by
     * anyone. When the peer is not trusted, NO header is consulted at all.
     *
     * Once the peer IS trusted, the header still cannot be believed at face
     * value. Every conforming reverse proxy APPENDS the peer it saw to the RIGHT
     * of whatever chain came in, so the LEFTMOST entry is simply what the
     * original caller wrote there. Reading it let a caller choose its own
     * throttle bucket, rotate it at will, or pin somebody else's:
     *
     *   caller sends      X-Forwarded-For: 192.0.2.66
     *   proxy appends     X-Forwarded-For: 192.0.2.66, 198.51.100.7
     *   REMOTE_ADDR       (the proxy)
     *
     * The chain is therefore ordered outermost (the caller) first and innermost
     * (the nearest proxy) last, with REMOTE_ADDR closing it as the innermost hop
     * of all. It is walked from the RIGHT, popping hops that are themselves
     * trusted proxies; the first hop that is NOT trusted is the furthest point we
     * can still vouch for, and that is the client. Above, the proxy pops and
     * 198.51.100.7 answers, so the forged 192.0.2.66 is never reached.
     *
     * @param array<string, mixed>  $server
     * @param array<string, string> $headers
     * @param string[]              $trustedProxies
     */
    private static function resolveClientIp(
        array $server,
        array $headers,
        bool $trustForwarded = false,
        array $trustedProxies = [],
    ): ?string {
        $remoteAddr = self::normalizeIp(isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null);

        if (!$trustForwarded) {
            return $remoteAddr;
        }

        $chain = self::forwardedChain($headers);
        if ($chain !== []) {
            if ($remoteAddr !== null) {
                $chain[] = $remoteAddr;
            }

            return self::walkForwardedChain($chain, $trustedProxies);
        }

        // Single-value vendor headers, consulted only when neither chain header
        // yielded a hop. They carry no ordering, so the walk above has nothing to
        // work on: they are worth exactly as much as the nearest proxy's
        // willingness to OVERWRITE, rather than pass through, whatever the caller
        // sent under the same name.
        foreach (['x-real-ip', 'cf-connecting-ip', 'x-client-ip'] as $header) {
            $ip = self::normalizeIp($headers[$header] ?? null);
            if ($ip !== null) {
                return $ip;
            }
        }

        return $remoteAddr;
    }

    /**
     * The forwarded hops as IP addresses, ordered outermost (the original caller)
     * first and innermost (the proxy nearest to us) last. REMOTE_ADDR is NOT
     * included here; the caller appends it.
     *
     * The RFC 7239 Forwarded header wins over the de facto X-Forwarded-For when
     * both yield hops. Both are comma separated and both are appended to by each
     * hop, so the same ordering holds for either.
     *
     * Entries that do not normalize to an IP (junk, "unknown", an empty slot from
     * a trailing comma) are DROPPED rather than failing the whole chain, and that
     * is deliberate. Junk to the LEFT of the real client is never reached, since
     * the walk starts from the right and stops at the first untrusted hop. Junk
     * cannot appear to the RIGHT of the real client either, because everything
     * right of it was appended by our own trusted proxies. Please do not turn
     * this back into a hard failure.
     *
     * @param  array<string, string> $headers
     * @return list<string>
     */
    private static function forwardedChain(array $headers): array
    {
        $chain = [];

        $forwarded = $headers['forwarded'] ?? null;
        if (is_string($forwarded)) {
            foreach (explode(',', $forwarded) as $element) {
                $ip = self::normalizeIp(self::parseForwardedElement($element)['for'] ?? null);
                if ($ip !== null) {
                    $chain[] = $ip;
                }
            }
        }

        if ($chain !== []) {
            return $chain;
        }

        $forwardedFor = $headers['x-forwarded-for'] ?? null;
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
     * Return the outermost hop that is not itself a trusted proxy, scanning from
     * the innermost end. Popping by the configured trusted LIST rather than by a
     * hop count is what keeps this correct when a deployment adds or removes a
     * layer of proxies.
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

        // Every hop is trusted infrastructure, so the caller itself sits inside
        // it and the outermost entry is the best answer available. This is also
        // what keeps trustedProxies: ['*'] returning the leftmost entry.
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
     * The connection parameters of the FIRST forwarding element, for buildUri().
     * The client identity is deliberately not exposed here: resolveClientIp()
     * needs every element, not the first one, and asking this helper for a "for"
     * is what produced the leftmost-entry bug in the first place.
     *
     * @return array{proto?: string, host?: string, port?: string}
     */
    private static function parseForwardedHeader(?string $header): array
    {
        if ($header === null) {
            return [];
        }

        $parameters = self::parseForwardedElement(explode(',', $header)[0] ?? '');

        $result = [];
        foreach (['proto', 'host', 'port'] as $key) {
            if (isset($parameters[$key])) {
                $result[$key] = strtolower($parameters[$key]);
            }
        }

        return $result;
    }

    /**
     * Parse ONE RFC 7239 forwarding element ("for=1.2.3.4;proto=https") into its
     * parameters: lowercased names mapped to unquoted values. Values keep their
     * original case, callers normalize when they need to.
     *
     * @return array<string, string>
     */
    private static function parseForwardedElement(string $element): array
    {
        $parameters = [];

        foreach (explode(';', $element) as $pair) {
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
