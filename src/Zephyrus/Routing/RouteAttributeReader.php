<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Zephyrus\Core\Config\EnvironmentVariable;
use Zephyrus\Routing\Attribute\Delete as DeleteAttribute;
use Zephyrus\Routing\Attribute\Get as GetAttribute;
use Zephyrus\Routing\Attribute\Head as HeadAttribute;
use Zephyrus\Routing\Attribute\Middleware as MiddlewareAttribute;
use Zephyrus\Routing\Attribute\MiddlewareGroup as MiddlewareGroupAttribute;
use Zephyrus\Routing\Attribute\Options as OptionsAttribute;
use Zephyrus\Routing\Attribute\Patch as PatchAttribute;
use Zephyrus\Routing\Attribute\Post as PostAttribute;
use Zephyrus\Routing\Attribute\Put as PutAttribute;
use Zephyrus\Routing\Attribute\RequiresEnv as RequiresEnvAttribute;
use Zephyrus\Routing\Attribute\Root as RootAttribute;
use Zephyrus\Routing\Attribute\Route as RouteAttribute;
use Zephyrus\Routing\Attribute\WithoutMiddleware as WithoutMiddlewareAttribute;
use Zephyrus\Routing\Exception\RouteAttributeException;
use Zephyrus\Routing\Exception\RouteMiddlewareException;
use Zephyrus\Routing\Exception\RouteSignatureException;

/**
 * Discovers route definitions from PHP 8 attributes on controller methods.
 *
 * Each method decorated with one or more #[Route] attributes yields a Route
 * value object with the handler string set to `ClassName@methodName`.
 */
final class RouteAttributeReader
{
    /**
     * Returns the routes declared by verb or route attributes on public methods declared by the class itself
     * (inherited methods are not read). Returns none when a class-level #[RequiresEnv] fails, and skips a
     * method whose #[RequiresEnv] fails.
     *
     * @param class-string $className
     * @return list<Route>
     * @throws RouteAttributeException When the class cannot be reflected or two routes share a name.
     * @throws RouteSignatureException When a route placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When a #[WithoutMiddleware] names a class the route may not skip.
     */
    public function read(string $className): array
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw RouteAttributeException::unresolvableClass($className, $e);
        }

        if (!$this->satisfiesInheritedEnvRequirements($reflection)) {
            return [];
        }

        $routes = [];
        $seenRouteNames = [];
        $rootPrefix = $this->resolveRootPrefix($reflection);

        $classMiddlewares = $this->readInheritedMiddlewares($reflection);
        $classExclusions = $this->readInheritedExclusions($reflection);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            if (!$this->satisfiesEnvRequirements($method->getAttributes(RequiresEnvAttribute::class))) {
                continue;
            }

            $handler = sprintf('%s@%s', $className, $method->getName());
            $exclusions = [
                ...$classExclusions,
                ...$this->readExclusionAttributes($method->getAttributes(WithoutMiddlewareAttribute::class)),
            ];

            foreach ($this->readMethodAttributes($method, $classMiddlewares) as $attr) {
                $name = $attr['name'] !== '' ? $attr['name'] : null;

                if ($name !== null) {
                    if (isset($seenRouteNames[$name])) {
                        throw RouteAttributeException::duplicateRouteName($className, $name);
                    }

                    $seenRouteNames[$name] = true;
                }

                $routes[] = Route::define(
                    method: $attr['method'],
                    path: $this->joinPath($rootPrefix, $attr['path']),
                    handler: $handler,
                    constraints: $attr['constraints'],
                    middlewares: $attr['middlewares'],
                    name: $name,
                    excludedMiddlewares: $exclusions,
                );
            }
        }

        return $routes;
    }

    /**
     * @param list<string> $classMiddlewares
     * @return list<array{method: string, path: string, constraints: array<string, string>, middlewares: array<int, string>, name: string}>
     */
    private function readMethodAttributes(ReflectionMethod $method, array $classMiddlewares = []): array
    {
        $routes = [];
        $methodMiddlewares = [
            ...$this->readMiddlewareAttributes($method->getAttributes(MiddlewareAttribute::class)),
            ...$this->readMiddlewareGroupAttributes($method->getAttributes(MiddlewareGroupAttribute::class)),
        ];

        foreach ($method->getAttributes(RouteAttribute::class) as $attributeRef) {
            /** @var RouteAttribute $attr */
            $attr = $attributeRef->newInstance();
            $routes[] = [
                'method' => $attr->method,
                'path' => $attr->path,
                'constraints' => $attr->constraints,
                'middlewares' => [...$classMiddlewares, ...$methodMiddlewares, ...$attr->middlewares],
                'name' => $attr->name,
            ];
        }

        $verbs = [
            GetAttribute::class => 'GET',
            HeadAttribute::class => 'HEAD',
            OptionsAttribute::class => 'OPTIONS',
            PostAttribute::class => 'POST',
            PutAttribute::class => 'PUT',
            PatchAttribute::class => 'PATCH',
            DeleteAttribute::class => 'DELETE',
        ];

        foreach ($verbs as $attributeClass => $methodName) {
            foreach ($method->getAttributes($attributeClass) as $attributeRef) {
                /** @var GetAttribute|HeadAttribute|OptionsAttribute|PostAttribute|PutAttribute|PatchAttribute|DeleteAttribute $attr */
                $attr = $attributeRef->newInstance();
                $routes[] = [
                    'method' => $methodName,
                    'path' => $attr->path,
                    'constraints' => $attr->constraints,
                    'middlewares' => [...$classMiddlewares, ...$methodMiddlewares, ...$attr->middlewares],
                    'name' => $attr->name,
                ];
            }
        }

        return $routes;
    }

    /**
     * Collect middleware names from #[Middleware] and #[MiddlewareGroup] attributes
     * on the given class AND all its parent classes (parent-first order).
     *
     * @param ReflectionClass<object> $reflection
     * @return list<string>
     */
    private function readInheritedMiddlewares(ReflectionClass $reflection): array
    {
        $middlewares = [];
        foreach ($this->parentFirstChain($reflection) as $class) {
            array_push(
                $middlewares,
                ...$this->readMiddlewareAttributes($class->getAttributes(MiddlewareAttribute::class)),
                ...$this->readMiddlewareGroupAttributes($class->getAttributes(MiddlewareGroupAttribute::class)),
            );
        }

        return $middlewares;
    }

    /**
     * Collects the #[WithoutMiddleware] classes of the class and its parents, parent-first.
     *
     * @param ReflectionClass<object> $reflection
     * @return list<string>
     */
    private function readInheritedExclusions(ReflectionClass $reflection): array
    {
        $exclusions = [];
        foreach ($this->parentFirstChain($reflection) as $class) {
            array_push(
                $exclusions,
                ...$this->readExclusionAttributes($class->getAttributes(WithoutMiddlewareAttribute::class)),
            );
        }

        return $exclusions;
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @return list<ReflectionClass<object>>
     */
    private function parentFirstChain(ReflectionClass $reflection): array
    {
        $chain = [];
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $chain[] = $current;
        }

        return array_reverse($chain);
    }

    /**
     * @param list<\ReflectionAttribute<WithoutMiddlewareAttribute>> $attributes
     * @return list<string>
     */
    private function readExclusionAttributes(array $attributes): array
    {
        $exclusions = [];

        foreach ($attributes as $attributeRef) {
            $exclusions[] = $attributeRef->newInstance()->middleware;
        }

        return $exclusions;
    }

    /**
     * @param list<\ReflectionAttribute<MiddlewareAttribute>> $attributes
     * @return list<string>
     */
    private function readMiddlewareAttributes(array $attributes): array
    {
        $middlewares = [];

        foreach ($attributes as $attributeRef) {
            /** @var MiddlewareAttribute $attr */
            $attr = $attributeRef->newInstance();
            $middlewares[] = $attr->name;
        }

        return $middlewares;
    }

    /**
     * @param list<\ReflectionAttribute<MiddlewareGroupAttribute>> $attributes
     * @return list<string>
     */
    private function readMiddlewareGroupAttributes(array $attributes): array
    {
        $middlewares = [];

        foreach ($attributes as $attributeRef) {
            /** @var MiddlewareGroupAttribute $attr */
            $attr = $attributeRef->newInstance();
            $middlewares[] = $attr->name;
        }

        return $middlewares;
    }

    /**
     * Walks the class hierarchy (parent-first) collecting #[Root] prefixes,
     * then appends the current class prefix. Returns the combined prefix string.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function resolveRootPrefix(ReflectionClass $reflection): string
    {
        $segments = [];

        $ancestors = [];
        $parent = $reflection->getParentClass();
        while ($parent !== false) {
            $ancestors[] = $parent;
            $parent = $parent->getParentClass();
        }

        foreach (array_reverse($ancestors) as $ancestor) {
            $attrs = $ancestor->getAttributes(RootAttribute::class);
            if ($attrs !== []) {
                $segments[] = $attrs[0]->newInstance()->prefix;
            }
        }

        $attrs = $reflection->getAttributes(RootAttribute::class);
        if ($attrs !== []) {
            $segments[] = $attrs[0]->newInstance()->prefix;
        }

        $combined = '';
        foreach ($segments as $segment) {
            $combined = $this->joinPath($combined, $segment);
        }

        return $combined;
    }

    /**
     * Checks that all #[RequiresEnv] attributes are satisfied by the
     * current environment. Returns false if any requirement fails.
     *
     * @param list<\ReflectionAttribute<RequiresEnvAttribute>> $attributes
     */
    private function satisfiesEnvRequirements(array $attributes): bool
    {
        foreach ($attributes as $attributeRef) {
            /** @var RequiresEnvAttribute $attr */
            $attr = $attributeRef->newInstance();
            if (EnvironmentVariable::read($attr->variable) !== $attr->value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check #[RequiresEnv] on the class and all its parents.
     *
     * @param ReflectionClass<object> $reflection
     */
    private function satisfiesInheritedEnvRequirements(ReflectionClass $reflection): bool
    {
        $current = $reflection;
        while ($current !== false) {
            if (!$this->satisfiesEnvRequirements($current->getAttributes(RequiresEnvAttribute::class))) {
                return false;
            }
            $current = $current->getParentClass();
        }
        return true;
    }

    private function joinPath(string $prefix, string $path): string
    {
        $left = trim($prefix, '/');
        $right = trim($path, '/');

        if ($left === '' && $right === '') {
            return '/';
        }

        if ($left === '') {
            return '/' . $right;
        }

        if ($right === '') {
            return '/' . $left;
        }

        return '/' . $left . '/' . $right;
    }
}
