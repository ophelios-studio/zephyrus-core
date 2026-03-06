<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Zephyrus\Routing\Attribute\Delete as DeleteAttribute;
use Zephyrus\Routing\Attribute\Get as GetAttribute;
use Zephyrus\Routing\Attribute\Patch as PatchAttribute;
use Zephyrus\Routing\Attribute\Post as PostAttribute;
use Zephyrus\Routing\Attribute\Put as PutAttribute;
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

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $className) {
                continue;
            }

            $handler = sprintf('%s@%s', $className, $method->getName());

            foreach ($this->readMethodAttributes($method) as $attr) {
                $name = $attr['name'] !== '' ? $attr['name'] : null;

                if ($name !== null) {
                    if (isset($seenRouteNames[$name])) {
                        throw RouteAttributeException::duplicateRouteName($className, $name);
                    }

                    $seenRouteNames[$name] = true;
                }

                $routes[] = Route::define(
                    method: $attr['method'],
                    path: $attr['path'],
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
     * @return list<array{method: string, path: string, constraints: array<string, string>, middlewares: array<int, string>, name: string}>
     */
    private function readMethodAttributes(ReflectionMethod $method): array
    {
        $routes = [];

        foreach ($method->getAttributes(RouteAttribute::class) as $attributeRef) {
            /** @var RouteAttribute $attr */
            $attr = $attributeRef->newInstance();
            $routes[] = [
                'method' => $attr->method,
                'path' => $attr->path,
                'constraints' => $attr->constraints,
                'middlewares' => $attr->middlewares,
                'name' => $attr->name,
            ];
        }

        $verbs = [
            GetAttribute::class => 'GET',
            PostAttribute::class => 'POST',
            PutAttribute::class => 'PUT',
            PatchAttribute::class => 'PATCH',
            DeleteAttribute::class => 'DELETE',
        ];

        foreach ($verbs as $attributeClass => $methodName) {
            foreach ($method->getAttributes($attributeClass) as $attributeRef) {
                /** @var GetAttribute|PostAttribute|PutAttribute|PatchAttribute|DeleteAttribute $attr */
                $attr = $attributeRef->newInstance();
                $routes[] = [
                    'method' => $methodName,
                    'path' => $attr->path,
                    'constraints' => $attr->constraints,
                    'middlewares' => $attr->middlewares,
                    'name' => $attr->name,
                ];
            }
        }

        return $routes;
    }
}
