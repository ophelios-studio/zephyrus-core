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
 * Resolves ClassName@method handler strings into Response values.
 *
 * Dispatches to a controller method using reflection-based argument injection,
 * in this order, per parameter:
 * - Parameters type-hinted as Request receive the current Request instance.
 * - Parameters NAMED AFTER A PLACEHOLDER of the matched route are injected from
 *   the route match, never from the request attributes. See invoke() for why
 *   attribute-wins was withdrawn.
 * - Parameters whose name matches a request attribute are injected from
 *   $request->attribute($name), cast to the declared scalar type. That is how a
 *   middleware publishes a value to a handler.
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
        $routeParameterNames = $this->routeParameterNames($match->route->path);

        foreach ($reflection->getParameters() as $param) {
            $type = $param->getType();
            $name = $param->getName();

            // Type-hinted as Request → inject the current request.
            if ($this->acceptsRequestType($type)) {
                $args[] = $request;
                continue;
            }

            // A PLACEHOLDER OF THIS ROUTE is taken from the match, never from
            // the attributes.
            //
            // The attributes used to win, and that was framed as a feature: a
            // middleware could "deliberately rewrite a route parameter". What
            // it actually meant is that a route constraint validated the URL
            // SEGMENT and then something else was handed to the handler, with
            // the replacement never constraint-checked. Measured:
            //
            //   Route constraint on {docId}: strict UUID.
            //   GET /docs/<valid-uuid> + header X-Doc-Id: ../../../etc/passwd
            //     -> 200 LOADING FILE: /var/docs/../../../etc/passwd.pdf
            //
            // A constraint that is not authoritative for its own handler
            // argument is not a constraint. A middleware that genuinely needs
            // to influence a handler publishes an attribute under a name that
            // is NOT a placeholder of the route, which still binds by name
            // below.
            if (in_array($name, $routeParameterNames, true) && array_key_exists($name, $match->parameters)) {
                $args[] = $this->castToType($match->parameters[$name], $type, $class, $method, $name);
                unset($positionalPool[$name]);
                continue;
            }

            // Named attribute (a value a middleware published onto the
            // request, or a route parameter hydrated by a caller driving this
            // resolver directly). Binding by name is the contract; position is
            // only ever a fallback.
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
     * this resolver directly may hydrate the attributes itself.
     *
     * VALUES COME FROM THE MATCH FIRST, and fall back to the attributes only
     * for a placeholder the match does not carry, which is the direct-caller
     * wiring above. The attributes used to win here too, so a middleware could
     * replace a constraint-checked segment with anything at all and the
     * replacement reached the handler unchecked. See invoke().
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
            // A DNF type such as "int|(Countable&Stringable)" is a union whose
            // members are NOT all named: getTypes() hands back the nested
            // ReflectionIntersectionType too, and passing that to
            // castToNamedType() was a TypeError on a live request.
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

            // No named member accepted the value, but a composite member is
            // still standing and carries no coercion rule of its own, so the
            // value goes through untouched. Throwing here instead would reject
            // an object that actually satisfies the intersection.
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

        // Anything that is not a named type is an intersection (the only other
        // ReflectionType on PHP 8.4/8.5, and the positive test keeps a future
        // fourth one out of castToNamedType too). Every member of an
        // intersection is a class or an interface, so no coercion applies and
        // the value passes through exactly as it already does for a named class
        // type. PHP's own parameter check enforces the intersection at invoke().
        if (!$type instanceof ReflectionNamedType) {
            // An intersection never allows null, so null is refused at this
            // boundary rather than deeper, matching a non-nullable named type.
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
     * Convert a value to int, refusing anything this platform cannot represent.
     *
     * The digit test used to be the whole check, and (int) then SATURATED
     * silently: "/n/9999999999999999999999" answered 200 with
     * id=9223372036854775807, so two distinct URLs collapsed to one argument
     * and a lookup, an audit record or an ownership check keyed on the wrong
     * row. That also contradicted this class's own contract, which is to throw
     * RouteParameterException for a value it cannot represent.
     *
     * Representability is proven by ROUND-TRIPPING rather than by comparing
     * against PHP_INT_MAX as a string, so leading zeros ("007") and "-0" keep
     * working exactly as before.
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

        // Recursive, because a DNF union nests an intersection. The closure
        // this replaced declared ReflectionNamedType, so describing such a
        // union was itself a TypeError inside the error path.
        if ($type instanceof ReflectionUnionType) {
            return implode('|', array_map($this->describeType(...), $type->getTypes()));
        }

        if ($type instanceof ReflectionIntersectionType) {
            return implode('&', array_map($this->describeType(...), $type->getTypes()));
        }

        return 'mixed';
    }
}
