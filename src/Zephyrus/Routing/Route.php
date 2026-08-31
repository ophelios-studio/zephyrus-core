<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Http\Request;
use Zephyrus\Routing\Exception\RouteSignatureException;

final readonly class Route
{
    /**
     * The grammar every route placeholder name must satisfy.
     *
     * The matcher used to accept ANY brace-delimited segment, so "{a b}",
     * "{0}", "{client_ip}" and "{_zephyrus.unmatched_route}" were all valid
     * placeholder names. Three separate problems followed, and all three are
     * closed at registration because that is the only place they can be closed
     * loudly.
     *
     *  - A NUMERIC name is renumbered by array_merge(), which is how a matched
     *    parameter reaches the request attributes. "{0}" and "{1}" on one path
     *    therefore did not keep the values they matched.
     *  - A name outside [a-zA-Z0-9_]+ was unreachable for URL GENERATION:
     *    RouteUrlGenerator scans for that grammar, so it emitted the raw
     *    placeholder into the URL instead of throwing, while PASSING the same
     *    name threw "Unexpected route parameter". One name grammar now governs
     *    both directions.
     *  - A name that collides with a framework attribute let the URL supply a
     *    value the framework publishes. See RESERVED_PARAMETER_NAMES.
     */
    public const PARAMETER_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /**
     * Attribute names a route parameter may not take, because the framework
     * itself publishes them and reading one is a trust decision.
     *
     * "client_ip" is read by Request::clientIp() as a fallback source for the
     * caller's address, so a route "/x/{client_ip}" let the URL choose the
     * value a rate limiter or an audit record keys on. Anything under the
     * "_zephyrus" prefix is framework-internal, ATTRIBUTE_UNMATCHED_ROUTE being
     * the live example.
     */
    public const RESERVED_PARAMETER_NAMES = ['client_ip'];

    /** Prefix reserved for framework-published attributes. */
    private const RESERVED_PARAMETER_PREFIX = '_zephyrus';

    /**
     * A ready-made constraint for the common "an identifier in a URL" case:
     * ASCII letters, digits, underscore and hyphen, and nothing else.
     *
     * The DEFAULT pattern for an unconstrained placeholder is "[^/]+", which is
     * byte-oriented and therefore admits anything that is not a slash. Invalid
     * UTF-8 is now refused by the matcher outright (see
     * RouteCollection::isWellFormedValue()), but a route that wants a narrow,
     * obviously safe segment should say so:
     *
     *   $router->get('/docs/{slug}', 'DocController@show', ['slug' => Route::SAFE_SLUG]);
     */
    public const SAFE_SLUG = '[A-Za-z0-9_-]+';

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     * @throws RouteSignatureException When a placeholder name is malformed, duplicated or reserved.
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $handler,
        public array $constraints = [],
        public array $middlewares = [],
        public ?string $name = null,
    ) {
        self::assertValidParameterNames($path);
    }

    /**
     * Validate every whole-segment placeholder in a route path.
     *
     * Membership mirrors RouteCollection::isParameterSegment() exactly: the
     * WHOLE segment is a placeholder or none of it is. A segment such as
     * "x{y}z" is a literal to the matcher and is left alone here for the same
     * reason.
     *
     * @throws RouteSignatureException
     */
    private static function assertValidParameterNames(string $path): void
    {
        $seen = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (strlen($segment) <= 2 || !str_starts_with($segment, '{') || !str_ends_with($segment, '}')) {
                continue;
            }

            $name = substr($segment, 1, -1);

            if (preg_match(self::PARAMETER_NAME_PATTERN, $name) !== 1) {
                throw new RouteSignatureException(sprintf(
                    'Invalid route parameter name "%s" on route "%s": a placeholder must match %s',
                    $name,
                    $path,
                    self::PARAMETER_NAME_PATTERN,
                ));
            }

            if (
                in_array($name, self::RESERVED_PARAMETER_NAMES, true)
                || str_starts_with($name, self::RESERVED_PARAMETER_PREFIX)
                || $name === Request::ATTRIBUTE_UNMATCHED_ROUTE
            ) {
                throw new RouteSignatureException(sprintf(
                    'Reserved route parameter name "%s" on route "%s": the framework publishes this '
                    . 'attribute itself, so a URL segment must not be able to supply it',
                    $name,
                    $path,
                ));
            }

            if (in_array($name, $seen, true)) {
                throw new RouteSignatureException(sprintf(
                    'Duplicate route parameter name "%s" on route "%s": only the last occurrence would '
                    . 'survive, so the earlier segment would match unchecked',
                    $name,
                    $path,
                ));
            }

            $seen[] = $name;
        }
    }

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     */
    public static function define(
        string $method,
        string $path,
        string $handler,
        array $constraints = [],
        array $middlewares = [],
        ?string $name = null,
    ): self {
        $normalizedPath = '/' . trim($path, '/');

        return new self(
            method: strtoupper($method),
            path: $normalizedPath === '/' ? '/' : $normalizedPath,
            handler: $handler,
            constraints: $constraints,
            middlewares: $middlewares,
            name: $name,
        );
    }

    public function matchesMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function withName(string $name): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            handler: $this->handler,
            constraints: $this->constraints,
            middlewares: $this->middlewares,
            name: $name,
        );
    }
}
