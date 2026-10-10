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

    private const string WHOLE_PLACEHOLDER_PATTERN = '/^\{[^{}]+\}$/D';
    private const string PLACEHOLDER_PATTERN = '/\{[^{}]+\}/';

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
     * @throws RouteSignatureException When a placeholder name is malformed, duplicated or reserved, a placeholder
     *                                  shares its segment with text, or a segment wrapped in braces is not a valid placeholder.
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
        $this->excludedMiddlewares = self::skippableMiddlewares(
            $excludedMiddlewares,
            sprintf('Route "%s %s"', $method, $path),
        );
    }

    /**
     * Resolves excluded middleware names to their class names, refusing any that may not be skipped.
     *
     * @param array<int, string> $middlewares
     * @param string $subject Route or group label used in the refusal message, such as Route "GET /x".
     * @return list<class-string<MiddlewareInterface>>
     * @throws RouteMiddlewareException
     *
     * @internal Read by Route and Router only.
     */
    public static function skippableMiddlewares(array $middlewares, string $subject): array
    {
        $skippable = [];

        foreach ($middlewares as $middleware) {
            if (!is_a($middleware, MiddlewareInterface::class, true)) {
                throw RouteMiddlewareException::excludedNotAMiddleware($subject, $middleware);
            }

            $middleware = (new \ReflectionClass($middleware))->getName();

            $security = self::protectingSecurityMiddleware($middleware);

            if ($security !== null) {
                throw RouteMiddlewareException::excludedSecurityMiddleware($subject, $middleware, $security);
            }

            if (!in_array($middleware, $skippable, true)) {
                $skippable[] = $middleware;
            }
        }

        return $skippable;
    }

    /**
     * Whether skippableMiddlewares() would accept the name: a middleware class that is not, and
     * is not a parent of, a framework security middleware.
     *
     * @internal Read by Router only.
     */
    public static function isSkippable(string $middleware): bool
    {
        return is_a($middleware, MiddlewareInterface::class, true)
            && self::protectingSecurityMiddleware((new \ReflectionClass($middleware))->getName()) === null;
    }

    private static function protectingSecurityMiddleware(string $middleware): ?string
    {
        foreach (self::UNSKIPPABLE_MIDDLEWARES as $security) {
            if (is_a($security, $middleware, true)) {
                return $security;
            }
        }

        return null;
    }

    /**
     * Validates every placeholder in a route path: each must fill a whole segment, with a valid, unique name.
     *
     * @throws RouteSignatureException
     */
    private static function assertValidParameterNames(string $path): void
    {
        $seen = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (preg_match(self::WHOLE_PLACEHOLDER_PATTERN, $segment) !== 1) {
                self::assertNoBraces($path, $segment);

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
     * A segment that is not one whole placeholder is a literal, unless it holds a placeholder next to text or is
     * wrapped in braces without being a placeholder. A brace that closes no placeholder inside text stays literal.
     *
     * @throws RouteSignatureException
     */
    private static function assertNoBraces(string $path, string $segment): void
    {
        $remainder = preg_replace(self::PLACEHOLDER_PATTERN, '', $segment);

        if ($remainder === $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                throw self::invalidPlaceholder($path, $segment);
            }

            return;
        }

        if (preg_match('/[{}]/', (string) $remainder) === 1) {
            throw self::invalidPlaceholder($path, $segment);
        }

        throw new RouteSignatureException(sprintf(
            'Invalid route path "%s": segment "%s" mixes a placeholder with text; capture the whole '
            . 'segment with a constraint or give the placeholder its own segment',
            $path,
            $segment,
        ));
    }

    private static function invalidPlaceholder(string $path, string $segment): RouteSignatureException
    {
        return new RouteSignatureException(sprintf(
            'Invalid route path "%s": segment "%s" is not a valid placeholder; write {name}, where name '
            . 'starts with a letter or underscore and holds only letters, digits and underscores',
            $path,
            $segment,
        ));
    }

    /**
     * Builds a route with a normalised path and an upper-case method.
     *
     * @param array<string, string> $constraints
     * @param array<int, string> $middlewares
     * @param array<int, string> $excludedMiddlewares Global middleware classes or interfaces to skip.
     *
     * @throws RouteSignatureException When a placeholder name is malformed, duplicated or reserved, a placeholder
     *                                  shares its segment with text, or a segment wrapped in braces is not a valid placeholder.
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
