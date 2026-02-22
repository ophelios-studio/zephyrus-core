<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
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

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(RouteAttribute::class);

            foreach ($attributes as $attributeRef) {
                /** @var RouteAttribute $attr */
                $attr = $attributeRef->newInstance();
                $handler = sprintf('%s@%s', $className, $method->getName());
                $name = $attr->name !== '' ? $attr->name : null;

                $routes[] = Route::define(
                    method: $attr->method,
                    path: $attr->path,
                    handler: $handler,
                    constraints: $attr->constraints,
                    middlewares: $attr->middlewares,
                    name: $name,
                );
            }
        }

        return $routes;
    }
}
