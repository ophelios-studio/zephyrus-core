<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Closure;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Throwable;
use Zephyrus\Controller\ControllerLifecycleInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\HandlerResolverException;
use Zephyrus\Routing\Exception\RouteParameterException;

/**
 * Resolves ClassName@method handler strings into Response values.
 *
 * Dispatches to a controller method using reflection-based argument injection,
 * in this order, per parameter:
 * - Parameters type-hinted as Request receive the current Request instance.
 * - Parameters whose name matches a request attribute are injected from
 *   $request->attribute($name), cast to the declared scalar type. The route
 *   parameters are attributes (HttpKernel hydrates them before the pipeline),
 *   and so is anything a middleware chose to publish.
 * - Parameters that match nothing by name fall back to POSITION, but only over
 *   the route parameters of the matched route that no earlier parameter already
 *   consumed by name. See resolvePositionalPool() for why the pool is that
 *   narrow.
 * - Parameters with default values fall back silently.
 * - All other unresolvable parameters throw HandlerResolverException.
 *
 * An optional factory callable may be provided to integrate a DI container;
 * its signature is (class-string): object. When omitted, classes are
 * instantiated with no constructor arguments.
 */
final class HandlerResolver
{
    /**
     * @var Closure(class-string): object
     */
    private Closure $factory;

    /**
     * @param callable(class-string): object|null $factory
     */
    public function __construct(?callable $factory = null)
    {
        $this->factory = $factory !== null
            ? Closure::fromCallable($factory)
            : static fn (string $class): object => new $class();
    }

    /**
     * Resolves and invokes the handler, returning the controller's Response.
     *
     * This method matches the signature expected by RouteDispatcher's $resolver
     * callable: (RouteMatch, Request): Response.
     */
    public function resolve(RouteMatch $match, Request $request): Response
    {
        [$class, $method] = $this->parseHandler($match->route->handler);

        try {
            $controller = ($this->factory)($class);
        } catch (Throwable $e) {
            throw HandlerResolverException::unresolvableClass($class, $e);
        }

        // before() hook — short-circuit if a Response is returned.
        if ($controller instanceof ControllerLifecycleInterface) {
            $early = $controller->before($request);

            if ($early !== null) {
                return $early;
            }
        }

        $response = $this->invoke($controller, $class, $method, $request, $match);

        // after() hook — may decorate the handler's Response.
        if ($controller instanceof ControllerLifecycleInterface) {
            $response = $controller->after($request, $response);
        }

        return $response;
    }

    // -------------------------------------------------------------------------

    /**
     * Parses a "ClassName@method" string into [$class, $method].
     *
     * @return array{string, string}
     */
    private function parseHandler(string $handler): array
    {
        if (!str_contains($handler, '@')) {
            throw HandlerResolverException::invalidHandlerFormat($handler);
        }

        [$class, $method] = explode('@', $handler, 2);

        if ($class === '' || $method === '') {
            throw HandlerResolverException::invalidHandlerFormat($handler);
        }

        return [$class, $method];
    }

    /**
     * Invokes $method on $controller, injecting arguments by type/name/position.
     */
    private function invoke(
        object $controller,
        string $class,
        string $method,
        Request $request,
        RouteMatch $match,
    ): Response {
        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (ReflectionException $e) {
            throw HandlerResolverException::unresolvableMethod($class, $method, $e);
        }

        $args = [];

        // Route parameters of THIS route, in path order, minus the ones an
        // earlier handler parameter already took by name. Only these are
        // eligible for the positional fallback.
        $positionalPool = $this->resolvePositionalPool($match, $request);

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // Type-hinted as Request → inject the current request.
            if ($this->acceptsRequestType($type)) {
                $args[] = $request;
                continue;
            }

            // Named attribute (a route parameter, or a value a middleware
            // published onto the request). Binding by name is the contract;
            // position is only ever a fallback.
            if (array_key_exists($name, $request->attributes)) {
                $attrValue = $request->attributes[$name];
                $args[] = $this->castToType($attrValue, $type, $class, $method, $name);
                unset($positionalPool[$name]);
                continue;
            }

            // Positional fallback: take the next route parameter nobody has
            // claimed by name yet.
            if ($positionalPool !== []) {
                $attrValue = array_shift($positionalPool);
                $args[] = $this->castToType($attrValue, $type, $class, $method, $name);
                continue;
            }

            // Fall back to a declared default value.
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw HandlerResolverException::unresolvedParameter($class, $method, $name);
        }

        return $reflection->invoke($controller, ...$args);
    }

    /**
     * Builds the ordered pool the positional fallback draws from.
     *
     * The pool is the matched route's OWN parameters, in path order, and
     * nothing else. It used to be array_values($request->attributes), which is
     * a wider set: HttpKernel hydrates the route parameters into the attributes
     * before the global pipeline runs, so every attribute a middleware adds
     * afterwards (a session, a resolved locale, a tenant) landed in the same
     * positional list. A handler declaring one more parameter than its route
     * has placeholders, the ordinary way to serve /policies/{type} and
     * /policies/{type}/{productId} from one method, therefore received the
     * first middleware attribute instead of its declared default. The value was
     * a plausible string, so it failed deep inside the handler rather than at
     * the boundary.
     *
     * Membership is read off the route PATH rather than off the match, so the
     * pool is right for both wirings in use: HttpKernel merges the match's
     * parameters into the attributes before dispatch, while a caller driving
     * this resolver directly may hydrate the attributes itself. Values still
     * come from the request attributes when present, so a middleware that
     * deliberately REWRITES a route parameter keeps winning; only the
     * membership of the pool is narrowed, not the source of truth.
     *
     * @return array<string, mixed>
     */
    private function resolvePositionalPool(RouteMatch $match, Request $request): array
    {
        $pool = [];

        foreach ($this->routeParameterNames($match->route->path) as $name) {
            if (array_key_exists($name, $request->attributes)) {
                $pool[$name] = $request->attributes[$name];
                continue;
            }

            if (array_key_exists($name, $match->parameters)) {
                $pool[$name] = $match->parameters[$name];
            }
        }

        return $pool;
    }

    /**
     * The placeholder names of a route path, in path order.
     *
     * Mirrors how RouteCollection recognises a parameter segment: the WHOLE
     * segment is a placeholder, never a fragment of one.
     *
     * @return array<int, string>
     */
    private function routeParameterNames(string $path): array
    {
        $names = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if (strlen($segment) > 2 && str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $names[] = substr($segment, 1, -1);
            }
        }

        return $names;
    }

    private function acceptsRequestType(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName() === Request::class;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $candidate) {
                if ($candidate instanceof ReflectionNamedType && $candidate->getName() === Request::class) {
                    return true;
                }
            }
        }

        return false;
    }

    private function castToType(
        mixed $value,
        ?ReflectionType $type,
        string $class,
        string $method,
        string $parameter,
    ): mixed {
        if ($type === null) {
            return $value;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $candidate) {
                try {
                    return $this->castToNamedType($value, $candidate, $class, $method, $parameter);
                } catch (RouteParameterException) {
                    continue;
                }
            }

            throw new RouteParameterException(
                $class,
                $method,
                $parameter,
                $this->describeType($type),
                $value,
            );
        }

        return $this->castToNamedType($value, $type, $class, $method, $parameter);
    }

    private function castToNamedType(
        mixed $value,
        ReflectionNamedType $type,
        string $class,
        string $method,
        string $parameter,
    ): mixed {
        $typeName = $type->getName();

        if ($value === null) {
            if ($type->allowsNull()) {
                return null;
            }

            throw new RouteParameterException($class, $method, $parameter, $typeName, $value);
        }

        return match ($typeName) {
            'int' => $this->toInt($value, $class, $method, $parameter),
            'float' => $this->toFloat($value, $class, $method, $parameter),
            'bool' => $this->toBool($value, $class, $method, $parameter),
            'string' => $this->toString($value, $class, $method, $parameter),
            default => $value,
        };
    }

    private function toInt(mixed $value, string $class, string $method, string $parameter): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RouteParameterException($class, $method, $parameter, 'int', $value);
    }

    private function toFloat(mixed $value, string $class, string $method, string $parameter): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw new RouteParameterException($class, $method, $parameter, 'float', $value);
    }

    private function toBool(mixed $value, string $class, string $method, string $parameter): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        throw new RouteParameterException($class, $method, $parameter, 'bool', $value);
    }

    private function toString(mixed $value, string $class, string $method, string $parameter): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw new RouteParameterException($class, $method, $parameter, 'string', $value);
    }

    private function describeType(ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map(static fn (ReflectionNamedType $candidate): string => $candidate->getName(), $type->getTypes()));
        }

        return 'mixed';
    }
}
