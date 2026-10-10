<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Routing\Exception\RouteSignatureException;

final readonly class Route
{
    /**
     * Grammar every route placeholder name must satisfy, enforced at registration.
     *
     * It keeps numeric names (renumbered by array_merge()) out of both matching and URL generation,
     * and any name RouteUrlGenerator would leave unsubstituted, since it only replaces {[a-zA-Z0-9_]+}.
     */
    public const PARAMETER_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /**
     * Parameter names the framework publishes itself, which a URL segment must not supply.
     *
     * "client_ip" is read by Request::clientIp() as a fallback source for the client address.
     * Names under the "_zephyrus" prefix are refused too.
     */
    public const RESERVED_PARAMETER_NAMES = ['client_ip'];

    /** Prefix reserved for framework-published attributes. */
    private const RESERVED_PARAMETER_PREFIX = '_zephyrus';

    /**
     * Constraint for an identifier segment: ASCII letters, digits, underscore and hyphen.
     *
     * An unconstrained placeholder accepts any valid UTF-8 value without "/" or NUL,
     * so narrow segments should use it:
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
     * Validates every whole-segment placeholder in a route path.
     *
     * A segment such as "x{y}z" is a literal, as in RouteCollection::isParameterSegment().
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
     * Builds a route with a normalised path and an upper-case method.
     *
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     *
     * @throws RouteSignatureException When a placeholder name is malformed, duplicated or reserved.
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
