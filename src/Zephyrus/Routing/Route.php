<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteSignatureException;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\ContentSecurityPolicyMiddleware;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\ForceHttpsMiddleware;
use Zephyrus\Security\MaxBodySizeMiddleware;
use Zephyrus\Security\SecureHeadersMiddleware;

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

    /** Global middlewares a route may never skip; excluding one of them or a parent is refused. */
    private const UNSKIPPABLE_MIDDLEWARES = [
        ForceHttpsMiddleware::class,
        AllowedHostsMiddleware::class,
        CsrfMiddleware::class,
        MaxBodySizeMiddleware::class,
        SecureHeadersMiddleware::class,
        ContentSecurityPolicyMiddleware::class,
    ];

    /**
     * Global middleware classes or interfaces skipped when this route matches.
     *
     * @var list<class-string<MiddlewareInterface>>
     */
    public array $excludedMiddlewares;

    /**
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     * @param array<int, string> $excludedMiddlewares Global middleware classes or interfaces to skip.
     * @throws RouteSignatureException When a placeholder name is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When an excluded class is not a middleware or would skip a framework
     *                                  security middleware.
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $handler,
        public array $constraints = [],
        public array $middlewares = [],
        public ?string $name = null,
        array $excludedMiddlewares = [],
    ) {
        self::assertValidParameterNames($path);
        $this->excludedMiddlewares = self::skippableMiddlewares($excludedMiddlewares, $method . ' ' . $path);
    }

    /**
     * @param array<int, string> $middlewares
     * @return list<class-string<MiddlewareInterface>>
     * @throws RouteMiddlewareException
     */
    private static function skippableMiddlewares(array $middlewares, string $route): array
    {
        $skippable = [];

        foreach ($middlewares as $middleware) {
            if (!is_a($middleware, MiddlewareInterface::class, true)) {
                throw RouteMiddlewareException::excludedNotAMiddleware($route, $middleware);
            }

            $middleware = (new \ReflectionClass($middleware))->getName();

            foreach (self::UNSKIPPABLE_MIDDLEWARES as $security) {
                if (is_a($security, $middleware, true)) {
                    throw RouteMiddlewareException::excludedSecurityMiddleware($route, $middleware, $security);
                }
            }

            if (!in_array($middleware, $skippable, true)) {
                $skippable[] = $middleware;
            }
        }

        return $skippable;
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
     * @param array<int, string> $excludedMiddlewares Global middleware classes or interfaces to skip.
     *
     * @throws RouteSignatureException When a placeholder name is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When an excluded class is not a middleware or would skip a framework
     *                                  security middleware.
     */
    public static function define(
        string $method,
        string $path,
        string $handler,
        array $constraints = [],
        array $middlewares = [],
        ?string $name = null,
        array $excludedMiddlewares = [],
    ): self {
        $normalizedPath = '/' . trim($path, '/');

        return new self(
            method: strtoupper($method),
            path: $normalizedPath === '/' ? '/' : $normalizedPath,
            handler: $handler,
            constraints: $constraints,
            middlewares: $middlewares,
            name: $name,
            excludedMiddlewares: $excludedMiddlewares,
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
            excludedMiddlewares: $this->excludedMiddlewares,
        );
    }

    /**
     * Returns a copy that skips exactly the given global middlewares.
     *
     * @param array<int, string> $excludedMiddlewares
     * @throws RouteMiddlewareException When a class is not a middleware or would skip a framework security
     *                                  middleware.
     */
    public function withExcludedMiddlewares(array $excludedMiddlewares): self
    {
        return new self(
            method: $this->method,
            path: $this->path,
            handler: $this->handler,
            constraints: $this->constraints,
            middlewares: $this->middlewares,
            name: $this->name,
            excludedMiddlewares: $excludedMiddlewares,
        );
    }
}
