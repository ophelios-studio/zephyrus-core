<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Closure;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Exception\HandlerResolverException;

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

        $controller = ($this->factory)($class);

        return $this->invoke($controller, $class, $method, $request);
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

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // Type-hinted as Request → inject the current request.
            if ($type instanceof ReflectionNamedType && $type->getName() === Request::class) {
                $args[] = $request;
                continue;
            }

            // Named attribute from the route (e.g. path parameter or any
            // value previously hydrated into request attributes).
            $attrValue = $request->attribute($name);

            if ($attrValue !== null) {
                $args[] = $this->castToType($attrValue, $type);
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
     * Casts a route attribute string to the declared parameter type when the
     * type is a known scalar; returns the original value for mixed/untyped.
     */
    private function castToType(mixed $value, ?ReflectionNamedType $type): mixed
    {
        if ($type === null) {
            return $value;
        }

        return match ($type->getName()) {
            'int'   => (int) $value,
            'float' => (float) $value,
            'bool'  => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
