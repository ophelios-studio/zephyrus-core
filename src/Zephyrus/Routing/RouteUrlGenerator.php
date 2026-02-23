<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Routing\Exception\RouteUrlGenerationException;

final readonly class RouteUrlGenerator
{
    public function __construct(
        private RouteCollection $routes,
        private ?string $baseUrl = null,
        private ?RouteSignature $signature = null,
    ) {
    }

    /**
     * @param array<string, scalar> $parameters
     * @param array<string, scalar|array<scalar>> $query
     */
    public function generate(string $routeName, array $parameters = [], array $query = [], ?string $fragment = null): string
    {
        $route = $this->routes->findByName($routeName);

        if ($route === null) {
            throw new RouteUrlGenerationException(sprintf('Unknown route name: %s', $routeName));
        }

        $placeholderNames = $this->extractPlaceholderNames($route->path);
        $this->assertNoUnexpectedParameters($parameters, $placeholderNames, $routeName);

        $path = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            function (array $matches) use ($parameters, $routeName, $route): string {
                $name = $matches[1];

                if (!array_key_exists($name, $parameters)) {
                    throw new RouteUrlGenerationException(sprintf(
                        'Missing route parameter "%s" for route "%s"',
                        $name,
                        $routeName,
                    ));
                }

                $value = (string) $parameters[$name];
                $this->assertParameterMatchesConstraints($route, $routeName, $name, $value);

                return rawurlencode($value);
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

        if ($this->baseUrl !== null) {
            $url = rtrim($this->baseUrl, '/') . $url;
        }

        return $this->appendFragment($url, $fragment);
    }

    /**
     * @return array<int, string>
     */
    private function extractPlaceholderNames(string $path): array
    {
        preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $path, $matches);

        $names = $matches[1] ?? [];

        return array_values(array_unique(array_map(static fn (mixed $name): string => (string) $name, $names)));
    }

    /**
     * @param array<string, scalar> $parameters
     * @param array<int, string> $placeholders
     */
    private function assertNoUnexpectedParameters(array $parameters, array $placeholders, string $routeName): void
    {
        foreach ($parameters as $name => $_value) {
            if (!in_array($name, $placeholders, true)) {
                throw new RouteUrlGenerationException(sprintf(
                    'Unexpected route parameter "%s" for route "%s"',
                    $name,
                    $routeName,
                ));
            }
        }
    }

    private function assertParameterMatchesConstraints(Route $route, string $routeName, string $name, string $value): void
    {
        $pattern = $route->constraints[$name] ?? null;
        if ($pattern === null) {
            return;
        }

        $regex = '~^(?:' . str_replace('~', '\\~', $pattern) . ')$~';
        if (@preg_match($regex, '') === false) {
            throw new RouteUrlGenerationException(sprintf(
                'Invalid constraint pattern "%s" for parameter "%s" on route "%s"',
                $pattern,
                $name,
                $routeName,
            ));
        }

        if (preg_match($regex, $value) !== 1) {
            throw new RouteUrlGenerationException(sprintf(
                'Route parameter "%s" value "%s" does not satisfy constraint "%s" for route "%s"',
                $name,
                $value,
                $pattern,
                $routeName,
            ));
        }
    }

    /**
     * @param array<string, scalar> $parameters
     * @param array<string, scalar|array<scalar>> $query
     */
    public function generateSigned(
        string $routeName,
        array $parameters = [],
        array $query = [],
        ?string $fragment = null,
    ): string {
        return $this->requireSignature()->sign($this->generate($routeName, $parameters, $query, $fragment));
    }

    /**
     * @param array<string, scalar> $parameters
     * @param array<string, scalar|array<scalar>> $query
     */
    public function generateTemporarySigned(
        string $routeName,
        int $ttlSeconds,
        array $parameters = [],
        array $query = [],
        ?string $fragment = null,
        ?int $now = null,
    ): string {
        if ($ttlSeconds <= 0) {
            throw new RouteUrlGenerationException('Temporary signed URL TTL must be greater than zero seconds');
        }

        return $this->requireSignature()->signTemporary(
            $this->generate($routeName, $parameters, $query, $fragment),
            ttlSeconds: $ttlSeconds,
            now: $now,
        );
    }

    private function appendFragment(string $url, ?string $fragment): string
    {
        if ($fragment === null || $fragment === '') {
            return $url;
        }

        $normalized = ltrim($fragment, '#');
        if ($normalized === '') {
            return $url;
        }

        return $url . '#' . rawurlencode($normalized);
    }

    private function requireSignature(): RouteSignature
    {
        if ($this->signature === null) {
            throw new RouteUrlGenerationException('Cannot generate signed URL without a RouteSignature instance');
        }

        return $this->signature;
    }
}
