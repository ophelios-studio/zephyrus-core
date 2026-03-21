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
 * Dispatches to a controller method using reflection-based argument injection:
 * - Parameters type-hinted as Request receive the current Request instance.
 * - Parameters whose name matches a route attribute (hydrated by RouteDispatcher)
 *   are injected from $request->attribute($name), cast to the declared scalar type.
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

        $response = $this->invoke($controller, $class, $method, $request);

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
     * Invokes $method on $controller, injecting arguments by type/name.
     */
    private function invoke(object $controller, string $class, string $method, Request $request): Response
    {
        try {
            $reflection = new ReflectionMethod($controller, $method);
        } catch (ReflectionException $e) {
            throw HandlerResolverException::unresolvableMethod($class, $method, $e);
        }

        $args = [];

        // Build positional list of route attribute values for fallback
        // when parameter names don't match placeholder names.
        $positionalAttributes = array_values($request->attributes);
        $positionalIndex = 0;

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // Type-hinted as Request → inject the current request.
            if ($this->acceptsRequestType($type)) {
                $args[] = $request;
                continue;
            }

            // Named attribute from the route (e.g. path parameter or any
            // value previously hydrated into request attributes).
            if (array_key_exists($name, $request->attributes)) {
                $attrValue = $request->attributes[$name];
                $args[] = $this->castToType($attrValue, $type, $class, $method, $name);
                $positionalIndex++;
                continue;
            }

            // Positional fallback: inject route attributes by position
            // when the parameter name doesn't match any placeholder name.
            if ($positionalIndex < count($positionalAttributes)) {
                $attrValue = $positionalAttributes[$positionalIndex];
                $args[] = $this->castToType($attrValue, $type, $class, $method, $name);
                $positionalIndex++;
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
