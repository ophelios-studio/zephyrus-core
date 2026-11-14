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
        $reflection = $this->reflect($className);

        if (!self::requirementsMet($this->classRequirements($reflection))) {
            return [];
        }

        return array_map(
            static fn (DiscoveredRoute $discovered): Route => $discovered->route,
            $this->routesOf($className, $reflection, [], activeOnly: true),
        );
    }

    /**
     * Returns every route of the class, as read() would under any environment, each with the #[RequiresEnv] pairs
     * of its class, the parents of its class and its method. No pair is evaluated, and no route name is compared.
     *
     * @internal Read by RouteDiscovery.
     * @param class-string $className
     * @return list<DiscoveredRoute>
     * @throws RouteAttributeException When the class cannot be reflected or a #[RequiresEnv] names a variable that
     *                                 is never read as configuration.
     * @throws RouteSignatureException When a route placeholder is malformed, duplicated or reserved.
     * @throws RouteMiddlewareException When a #[WithoutMiddleware] names a class the route may not skip.
     */
    public function readWithRequirements(string $className): array
    {
        $reflection = $this->reflect($className);
        $classRequirements = $this->classRequirements($reflection);
        self::assertReadableVariables($className, $classRequirements);

        return $this->routesOf($className, $reflection, $classRequirements, activeOnly: false);
    }

    /**
     * Whether the environment holds every (variable, value) pair, each read with EnvironmentVariable::read(): the
     * one evaluation of #[RequiresEnv], shared by attribute discovery and the routes cache.
     *
     * @internal
     * @param list<array{string, string}> $requirements
     * @throws \InvalidArgumentException When a variable name is refused by EnvironmentVariable::read().
     */
    public static function requirementsMet(array $requirements): bool
    {
        foreach ($requirements as [$variable, $value]) {
            if (EnvironmentVariable::read($variable) !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param class-string $className
     * @return ReflectionClass<object>
     */
    private function reflect(string $className): ReflectionClass
    {
        try {
            return new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw RouteAttributeException::unresolvableClass($className, $e);
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     * @param list<array{string, string}> $classRequirements
     * @return list<DiscoveredRoute>
     * @throws RouteAttributeException
     * @throws RouteSignatureException
     * @throws RouteMiddlewareException
     */
    private function routesOf(
        string $className,
        ReflectionClass $reflection,
        array $classRequirements,
        bool $activeOnly,
    ): array {
        $routes = [];
        $seenRouteNames = [];
        $rootPrefix = $this->resolveRootPrefix($reflection);

        $classMiddlewares = $this->readInheritedMiddlewares($reflection);
        $classExclusions = $this->readInheritedExclusions($reflection);
        $classGroups = $this->readInheritedGroups($reflection);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $methodRequirements = $this->requirementsOf($method->getAttributes(RequiresEnvAttribute::class));
            if ($activeOnly && !self::requirementsMet($methodRequirements)) {
                continue;
            }

            if (!$activeOnly) {
                self::assertReadableVariables($className, $methodRequirements);
            }

            $handler = sprintf('%s@%s', $className, $method->getName());
            $exclusions = [
                ...$classExclusions,
                ...$this->readExclusionAttributes($method->getAttributes(WithoutMiddlewareAttribute::class)),
            ];
            $groups = [
                ...$classGroups,
                ...$this->readMiddlewareGroupAttributes($method->getAttributes(MiddlewareGroupAttribute::class)),
            ];

            foreach ($this->readMethodAttributes($method, $classMiddlewares) as $attr) {
                $name = $attr['name'] !== '' ? $attr['name'] : null;

                if ($activeOnly && $name !== null) {
                    if (isset($seenRouteNames[$name])) {
                        throw RouteAttributeException::duplicateRouteName($className, $name);
                    }

                    $seenRouteNames[$name] = true;
                }

                $routes[] = new DiscoveredRoute(
                    Route::define(
                        method: $attr['method'],
                        path: $this->joinPath($rootPrefix, $attr['path']),
                        handler: $handler,
                        constraints: $attr['constraints'],
                        middlewares: $attr['middlewares'],
                        name: $name,
                        excludedMiddlewares: $exclusions,
                    ),
                    [...$classRequirements, ...$methodRequirements],
                    $groups,
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
     * The #[RequiresEnv] pairs of the class and its parents, the class first.
     *
     * @param ReflectionClass<object> $reflection
     * @return list<array{string, string}>
     */
    private function classRequirements(ReflectionClass $reflection): array
    {
        $requirements = [];
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            array_push($requirements, ...$this->requirementsOf($current->getAttributes(RequiresEnvAttribute::class)));
        }

        return $requirements;
    }

    /**
     * @param list<\ReflectionAttribute<RequiresEnvAttribute>> $attributes
     * @return list<array{string, string}>
     */
    private function requirementsOf(array $attributes): array
    {
        $requirements = [];
        foreach ($attributes as $attributeRef) {
            $attr = $attributeRef->newInstance();
            $requirements[] = [$attr->variable, $attr->value];
        }

        return $requirements;
    }

    /**
     * @param list<array{string, string}> $requirements
     * @throws RouteAttributeException
     */
    private static function assertReadableVariables(string $className, array $requirements): void
    {
        foreach ($requirements as [$variable]) {
            try {
                // Checks the name only: the value read is discarded.
                EnvironmentVariable::read($variable);
            } catch (\InvalidArgumentException $e) {
                throw RouteAttributeException::unreadableRequirement($className, $e);
            }
        }
    }

    /**
     * The #[MiddlewareGroup] names of the class and its parents, parent-first.
     *
     * @param ReflectionClass<object> $reflection
     * @return list<string>
     */
    private function readInheritedGroups(ReflectionClass $reflection): array
    {
        $groups = [];
        foreach ($this->parentFirstChain($reflection) as $class) {
            array_push(
                $groups,
                ...$this->readMiddlewareGroupAttributes($class->getAttributes(MiddlewareGroupAttribute::class)),
            );
        }

        return $groups;
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
