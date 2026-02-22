<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Routing\Exception\RouteUrlGenerationException;

final readonly class RouteUrlGenerator
{
    public function __construct(
        private RouteCollection $routes,
        private ?string $baseUrl = null,
    ) {
    }

    /**
     * @param array<string, scalar> $parameters
     * @param array<string, scalar|array<scalar>> $query
     */
    public function generate(string $routeName, array $parameters = [], array $query = []): string
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

        $resolvedPath = $path ?? $route->path;

        $url = $resolvedPath;

        if ($query !== []) {
            ksort($query);
            $queryString = http_build_query($query, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
            $url = $queryString === '' ? $url : $url . '?' . $queryString;
        }

        if ($this->baseUrl === null) {
            return $url;
        }

        return rtrim($this->baseUrl, '/') . $url;
    }
}
