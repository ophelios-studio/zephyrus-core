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

    /**
     * The names this request took FROM ITS URL, i.e. the matched route's
     * placeholders. Empty for a request that matched no route, and empty for
     * any Request built outside the kernel.
     *
     * WHY PROVENANCE IS RECORDED AT ALL. The matched route parameters are
     * merged into $attributes before the global pipeline runs, and the
     * attribute namespace is shared with everything a middleware publishes.
     * Nothing distinguished the two, so an attribute a middleware publishes
     * CONDITIONALLY could be supplied unconditionally by a URL segment. The
     * exploitable ordering is the natural one, because a publishing middleware
     * normally only sets its attribute when there is a session to read:
     *
     *   route /reports/{role}, guard on the "role" attribute
     *   anonymous, no session:  GET /reports/admin -> 200 CONFIDENTIAL REPORTS
     *   logged-in viewer:       GET /reports/admin -> 401 (session overwrites it)
     *
     * Merging is KEPT, because route parameters reaching $attributes is the
     * documented contract five production applications are built on, and
     * withdrawing it would break every one of them. What changes is that a
     * consumer of an attribute can now ask where the value came from, and the
     * framework's own RequestAttributeGuard refuses to authorise on a
     * route-sourced name. Route registration refuses a placeholder named after
     * a framework attribute outright; see Route::RESERVED_PARAMETER_NAMES.
     *
     * Provenance is recorded BY NAME and survives an overwrite, which is
     * deliberate and fail-closed: a middleware that sets the same name later
     * does not make the URL-supplied value safe, it only makes the attack
     * conditional on the middleware not running.
     *
     * @var array<string, string>
     */

    /**
     * The forwarding headers read by default once REMOTE_ADDR is a trusted
     * proxy: the X-Forwarded-* family, and nothing else.
     *
     * WHY THE SET IS NARROWER THAN "EVERY FORWARDING HEADER". Trusting a proxy is
     * not the same as trusting every header a caller can name. A proxy manages
     * ONE family and passes the rest through untouched, so reading a header the
     * proxy never writes hands the caller a field it fully controls. Under the
     * previous "read whatever is present" behaviour, a deployment behind an
     * nginx that manages only X-Forwarded-* could be told any client IP the
     * caller liked, just by sending a Forwarded or an X-Real-IP header of its
     * own. Walking the X-Forwarded-For chain correctly does not help when the
     * chain being walked is not the one the proxy wrote.
     *
     * The X-Forwarded-* family is what the overwhelmingly common proxy actually
     * writes. Everything else is opt-in by name, so an operator running a proxy
     * that emits RFC 7239 Forwarded declares it and gets it.
     */
    public const TRUSTED_HEADERS_DEFAULT = [
        'x-forwarded-for',
        'x-forwarded-host',
        'x-forwarded-proto',
        'x-forwarded-port',
    ];

    /**
     * Every forwarding header this class knows how to read, in any role. A name
     * outside this list cannot change behaviour whatever it is set to, which is
     * why configuration layers validate against it: silently accepting a typo
     * would leave an operator believing they trust a header they do not. See
     * SecurityConfig::fromArray(), which rejects an unknown name outright.
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

    private Uri $uri;
    private RequestBody $body;
    private HeaderBag $headerBag;
    private CookieJar $cookieJar;

    /**
     * @param RequestBody|array<string, mixed> $body
     * @param array<string, mixed> $query
     * @param HeaderBag|array<string, mixed> $headers
     * @param CookieJar|array<string, string> $cookies
     * @param array<string, mixed> $attributes
     * @param array<string, FileUpload|array<int, FileUpload>> $files
     * @param array<string, string> $routeParameters Names this request took from
     *   its URL. See the property docblock; the values are also present in
     *   $attributes, this records WHERE THEY CAME FROM.
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
        public array $routeParameters = [],
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
        // An untrusted peer collapses to an empty allowlist, so the two gates
        // ("is this proxy trusted" and "may this header be read") stay a single
        // question everywhere downstream.
        $trusted = $trustForwarded ? self::normalizeTrustedHeaders($trustedHeaders) : [];
        $uri     = self::buildUri($server, $trusted);
        $clientIp = self::resolveClientIp($server, $headers, $trustForwarded, $trustedProxies, $trusted);

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
     * The canonical request path: the raw, still percent-encoded target, with a
     * leading run of slashes collapsed.
     *
     * ALWAYS PREFER THIS over uri()->path() for any decision about a request:
     * guards, allowlists, exclusion patterns, rate-limit keys, audit records.
     * The two used to be able to disagree, and a leading "//" was enough to do
     * it: uri()->path() reported "//x/admin/secret" while the router dispatched
     * "/admin/secret", so a path-based guard inspected one route and a different
     * one executed. See canonicalizeUrl() for the mechanism.
     *
     * ## What this string is, exactly, and what it is not
     *
     * This docblock used to claim the value was "the exact path the router
     * dispatches on". IT WAS NOT, and the pattern it recommended was the
     * exploitable one. The router rawurldecode()d every segment before
     * matching, so "/%61dmin/secret" dispatched "/admin/secret" while this
     * method returned "/%61dmin/secret" and a guard written as
     * str_starts_with($request->path(), '/admin') waved it through. Measured
     * through the real kernel, 401 became 200.
     *
     * The router was changed rather than this method: a LITERAL route segment
     * is now compared byte for byte against the raw request segment, so the
     * literal part of the dispatched route and the literal part of this string
     * are the same bytes. That is the property a prefix guard or an anchored
     * exclusion pattern actually needs. See
     * RouteCollection::extractParameters(), which also explains why decoding
     * this string instead would have been lossy.
     *
     * Two residual differences remain, and both are safe to rely on:
     *
     *   - A PARAMETER segment appears here percent-encoded and reaches the
     *     handler decoded. Read the decoded value with routeParameter().
     *   - A trailing slash survives here; the router ignores it unless
     *     trailing-slash tolerance is switched off. A pattern anchored with "$"
     *     therefore does not match the slashed form, which fails closed.
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

    /**
     * The value this request took from its URL under $name, and nothing else.
     *
     * Namespaced on purpose: unlike attribute(), it cannot return a value a
     * middleware published, and it cannot be shadowed by one. Use it wherever
     * the URL is the intended source. See $routeParameters.
     */
    public function routeParameter(string $name, ?string $default = null): ?string
    {
        return $this->routeParameters[$name] ?? $default;
    }

    /**
     * Whether $name was supplied by a URL segment of the matched route.
     *
     * A security decision keyed on an attribute must consult this: a value the
     * caller chose in the URL is not evidence about the caller. See
     * $routeParameters and RequestAttributeGuard.
     */
    public function isRouteParameter(string $name): bool
    {
        return array_key_exists($name, $this->routeParameters);
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
        );
    }

    /**
     * Publish the matched route's placeholders onto the request.
     *
     * They land in $attributes exactly as withAttributes() would put them
     * there, which is the contract handlers and middlewares are written
     * against, AND in $routeParameters, which records that the URL is where
     * they came from. HttpKernel calls this instead of withAttributes() so that
     * provenance exists for every request the framework routes.
     *
     * @param array<string, string> $parameters
     */
    public function withRouteParameters(array $parameters): self
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

    /**
     * Build the absolute request URL.
     *
     * Every forwarded input here is gated on the SAME allowlist that gates the
     * client IP. An operator who drops x-forwarded-host from the list must stop
     * having their Host decided by that header, otherwise the setting would only
     * govern half of what it names.
     *
     * $trustedHeaders is already empty when the peer is not a trusted proxy, so
     * an empty list means "read nothing forwarded" and needs no separate flag.
     *
     * @param array<string, mixed> $server
     * @param list<string>         $trustedHeaders
     */
    private static function buildUri(array $server, array $trustedHeaders = []): string
    {
        $requestUri = (string) ($server['REQUEST_URI'] ?? '/');

        if (str_starts_with($requestUri, 'http://') || str_starts_with($requestUri, 'https://')) {
            return $requestUri;
        }

        $forwarded = in_array('forwarded', $trustedHeaders, true)
            ? self::parseForwardedHeader(isset($server['HTTP_FORWARDED']) ? (string) $server['HTTP_FORWARDED'] : null)
            : [];

        $https = isset($server['HTTPS']) && $server['HTTPS'] !== '' && $server['HTTPS'] !== 'off';

        $scheme = $forwarded['proto']
            ?? (in_array('x-forwarded-proto', $trustedHeaders, true)
                ? self::firstForwardedValue($server['HTTP_X_FORWARDED_PROTO'] ?? null)
                : null)
            ?? ($https ? 'https' : 'http');

        $host = $forwarded['host']
            ?? (in_array('x-forwarded-host', $trustedHeaders, true)
                ? self::firstForwardedValue($server['HTTP_X_FORWARDED_HOST'] ?? null)
                : null)
            ?? (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');

        if (!str_contains($host, ':')) {
            $port = $forwarded['port']
                ?? (in_array('x-forwarded-port', $trustedHeaders, true)
                    ? self::firstForwardedValue($server['HTTP_X_FORWARDED_PORT'] ?? null)
                    : null)
                ?? (isset($server['SERVER_PORT']) ? (string) $server['SERVER_PORT'] : null);

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

        // A NON-SCALAR "_method" used to reach a (string) cast and raise
        // "Array to string conversion" from inside fromGlobals(). In the
        // reference bootstrap that runs BEFORE the kernel exists, so the notice
        // escapes every error-handling seam the framework has: "_method[]=PUT"
        // was a one-parameter way to make the entry point emit a PHP warning.
        // A body override that is not a scalar simply is not an override.
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
     * A header is only ever read when it appears in $trustedHeaders. Trusting the
     * peer is a separate question from trusting a given header: a proxy manages
     * one family and passes the rest through untouched, so a header the proxy
     * does not write is still caller-controlled no matter who the peer is. See
     * TRUSTED_HEADERS_DEFAULT.
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
        if ($chain !== []) {
            if ($remoteAddr !== null) {
                $chain[] = $remoteAddr;
            }

            return self::walkForwardedChain($chain, $trustedProxies);
        }

        // Single-value vendor headers, opt-in by name and consulted only when
        // neither chain header yielded a hop. They carry no ordering, so the walk
        // above has nothing to work on: they are worth exactly as much as the
        // nearest proxy's willingness to OVERWRITE, rather than pass through,
        // whatever the caller sent under the same name. That is precisely why
        // they are absent from TRUSTED_HEADERS_DEFAULT.
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
     * Either header is skipped entirely when it is not in $trustedHeaders.
     *
     * @param  array<string, string> $headers
     * @param  list<string>          $trustedHeaders
     * @return list<string>
     */
    private static function forwardedChain(array $headers, array $trustedHeaders): array
    {
        $chain = [];

        $forwarded = in_array('forwarded', $trustedHeaders, true) ? ($headers['forwarded'] ?? null) : null;
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
     * Lowercase, trim and de-duplicate the configured header names, so a YAML
     * file may spell them "X-Forwarded-For", and drop any name this class cannot
     * read.
     *
     * DROPPING rather than throwing is deliberate HERE, and it is not the whole
     * story. A name outside TRUSTED_HEADERS_SUPPORTED cannot enable anything, so
     * keeping it would change nothing, while a throw would introduce a new
     * failure path at the very top of the lifecycle, before the kernel's error
     * handling exists. The loud half lives where an operator's typo actually
     * originates: SecurityConfig::fromArray() REJECTS an unknown name at boot,
     * so a misspelling is reported rather than silently believed.
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
