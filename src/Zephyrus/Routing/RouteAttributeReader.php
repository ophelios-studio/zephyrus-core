<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Zephyrus\Routing\Attribute\Delete as DeleteAttribute;
use Zephyrus\Routing\Attribute\Get as GetAttribute;
use Zephyrus\Routing\Attribute\Head as HeadAttribute;
use Zephyrus\Routing\Attribute\Middleware as MiddlewareAttribute;
use Zephyrus\Routing\Attribute\MiddlewareGroup as MiddlewareGroupAttribute;
use Zephyrus\Routing\Attribute\Options as OptionsAttribute;
use Zephyrus\Routing\Attribute\Patch as PatchAttribute;
use Zephyrus\Routing\Attribute\Post as PostAttribute;
use Zephyrus\Routing\Attribute\Put as PutAttribute;
use Zephyrus\Routing\Attribute\Root as RootAttribute;
use Zephyrus\Routing\Attribute\Route as RouteAttribute;
use Zephyrus\Routing\Exception\RouteAttributeException;

/**
 * Discovers route definitions from PHP 8 attributes on controller methods.
 *
 * Each method decorated with one or more #[Route] attributes yields a Route
 * value object with the handler string set to `ClassName@methodName`.
 */
final class RouteAttributeReader
{
    /**
     * Scans the given class and returns all Route objects derived from
     * #[Route] attributes on its public methods.
     *
     * @param class-string $className
     * @return list<Route>
     * @throws RouteAttributeException When the class cannot be reflected.
     */
    public function read(string $className): array
    {
        try {
            $reflection = new ReflectionClass($className);
        } catch (ReflectionException $e) {
            throw RouteAttributeException::unresolvableClass($className, $e);
        }

        $routes = [];
        $seenRouteNames = [];
        $rootPrefix = $this->resolveRootPrefix($reflection);

        $classMiddlewares = [
            ...$this->readMiddlewareAttributes($reflection->getAttributes(MiddlewareAttribute::class)),
            ...$this->readMiddlewareGroupAttributes($reflection->getAttributes(MiddlewareGroupAttribute::class)),
        ];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $handler = sprintf('%s@%s', $className, $method->getName());

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
