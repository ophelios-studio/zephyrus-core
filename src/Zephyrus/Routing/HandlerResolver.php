<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Closure;
use ReflectionException;
use ReflectionIntersectionType;
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
 * Resolves "ClassName@method" handler strings into Response values.
 *
 * Each controller parameter is resolved in this order:
 * - a Request type-hint receives the current request;
 * - a name matching a placeholder of the matched route takes the route value,
 *   never a request attribute, so a middleware cannot replace a constrained
 *   segment;
 * - a name matching a request attribute takes that attribute, cast to the
 *   declared type;
 * - otherwise the next unclaimed route parameter, by position;
 * - otherwise the declared default value;
 * - otherwise HandlerResolverException.
 *
 * The optional factory (class-string): object builds controllers. Without it,
 * controllers are instantiated with no constructor arguments.
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
     * Matches the RouteDispatcher resolver signature (RouteMatch, Request): Response.
     *
     * @throws HandlerResolverException When the handler, class, method or a parameter cannot be resolved.
     * @throws RouteParameterException When a route value or request attribute value cannot be cast to the
     *                                 declared parameter type.
     */
    public function resolve(RouteMatch $match, Request $request): Response
    {
        [$class, $method] = $this->parseHandler($match->route->handler);

        if (!class_exists($class) && !interface_exists($class)) {
            throw HandlerResolverException::missingClass($class);
        }

        try {
            $controller = ($this->factory)($class);
        } catch (Throwable $e) {
            throw HandlerResolverException::unresolvableClass($class, $e);
        }

        if ($controller instanceof ControllerLifecycleInterface) {
            $early = $controller->before($request);

            if ($early !== null) {
                return $early;
            }
        }

        $response = $this->invoke($controller, $class, $method, $request, $match);

        if ($controller instanceof ControllerLifecycleInterface) {
            $response = $controller->after($request, $response);
        }

        return $response;
    }

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
     * Invokes $method on $controller, injecting arguments by type, name then position.
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

        // Positional fallback draws only from this route's placeholders not already claimed by name.
        $positionalPool = $this->resolvePositionalPool($match, $request);
        $routeParameterNames = $this->routeParameterNames($match->route->path);

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();

            if ($this->acceptsRequestType($type)) {
                $args[] = $request;
                continue;
            }

            // A route placeholder is always read from the match: an attribute must never replace a constrained segment.
            if (in_array($name, $routeParameterNames, true) && array_key_exists($name, $match->parameters)) {
                $args[] = $this->castToType($match->parameters[$name], $type, $class, $method, $name);
                unset($positionalPool[$name]);
                continue;
            }

            // A value a middleware published on the request. Position is only a fallback.
            if (array_key_exists($name, $request->attributes)) {
                $attrValue = $request->attributes[$name];
                $args[] = $this->castToType($attrValue, $type, $class, $method, $name);
                unset($positionalPool[$name]);
                continue;
            }

            if ($positionalPool !== []) {
                $attrValue = array_shift($positionalPool);
                $args[] = $this->castToType($attrValue, $type, $class, $method, $name);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw HandlerResolverException::unresolvedParameter($class, $method, $name);
        }

        return $reflection->invoke($controller, ...$args);
    }

    /**
     * Builds the ordered pool the positional fallback draws from: the matched route's placeholders, in path order.
     *
     * Never the whole attribute bag, or middleware attributes would shift later parameters off their defaults.
     * Values come from the match first, and from the attributes only for a placeholder the match does not carry.
     *
     * @return array<string, mixed>
     */
    private function resolvePositionalPool(RouteMatch $match, Request $request): array
    {
        $pool = [];

        foreach ($this->routeParameterNames($match->route->path) as $name) {
            if (array_key_exists($name, $match->parameters)) {
                $pool[$name] = $match->parameters[$name];
                continue;
            }

            if (array_key_exists($name, $request->attributes)) {
                $pool[$name] = $request->attributes[$name];
            }
        }

        return $pool;
    }

    /**
     * The placeholder names of a route path, in path order. A placeholder is a whole segment, as in RouteCollection.
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
            // DNF members may be intersections, which have no coercion rule: only named members are cast.
            $hasCompositeMember = false;

            foreach ($type->getTypes() as $candidate) {
                if (!$candidate instanceof ReflectionNamedType) {
                    $hasCompositeMember = true;
                    continue;
                }

                try {
                    return $this->castToNamedType($value, $candidate, $class, $method, $parameter);
                } catch (RouteParameterException) {
                    continue;
                }
            }

            // An intersection member may accept the untouched value, so it is not rejected here.
            if ($hasCompositeMember) {
                return $value;
            }

            throw new RouteParameterException(
                $class,
                $method,
                $parameter,
                $this->describeType($type),
                $value,
            );
        }

        // Any other type is an intersection: no coercion applies, PHP enforces it at invoke().
        if (!$type instanceof ReflectionNamedType) {
            // Intersections are non-nullable, so null is refused here.
            if ($value === null) {
                throw new RouteParameterException(
                    $class,
                    $method,
                    $parameter,
                    $this->describeType($type),
                    $value,
                );
            }

            return $value;
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

    /**
     * Converts to int, refusing digits PHP cannot represent.
     *
     * (int) saturates silently, so the canonical digits must survive a round trip: "007" passes, an out-of-range value fails.
     */
    private function toInt(mixed $value, string $class, string $method, string $parameter): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            $negative = str_starts_with($value, '-');
            $digits = ltrim($negative ? substr($value, 1) : $value, '0');
            if ($digits === '') {
                $digits = '0';
            }

            $canonical = ($negative && $digits !== '0' ? '-' : '') . $digits;
            $converted = (int) $value;

            if ((string) $converted === $canonical) {
                return $converted;
            }
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

        // Recursive, because a DNF union nests an intersection.
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map($this->describeType(...), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map($this->describeType(...), $type->getTypes()));
        }

        return 'mixed';
    }
}
