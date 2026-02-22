<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Routing\Exception\RouteUrlGenerationException;

final readonly class RouteUrlGenerator
{
    public function __construct(private RouteCollection $routes)
    {
    }

    /**
     * @param array<string, scalar> $parameters
     */
    public function generate(string $routeName, array $parameters = []): string
    {
        $route = $this->routes->findByName($routeName);

        if ($route === null) {
            throw new RouteUrlGenerationException(sprintf('Unknown route name: %s', $routeName));
        }

        $path = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            static function (array $matches) use ($parameters, $routeName): string {
                $name = $matches[1];

                if (!array_key_exists($name, $parameters)) {
                    throw new RouteUrlGenerationException(sprintf(
                        'Missing route parameter "%s" for route "%s"',
                        $name,
                        $routeName,
                    ));
                }

                return rawurlencode((string) $parameters[$name]);
            },
            $route->path,
        );

        return $path ?? $route->path;
    }
}
