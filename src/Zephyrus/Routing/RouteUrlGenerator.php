<?php

declare(strict_types=1);

namespace Zephyrus\Routing;

use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Routing\Exception\RouteSignatureException;
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
     * Builds the URL of a named route.
     *
     * Placeholder values are rawurlencoded (a slash becomes %2F), query keys are
     * sorted and encoded as RFC 3986, and leading "#" characters are stripped from
     * the fragment before it is encoded.
     *
     * @param array<string, scalar> $parameters
     * @param array<string, scalar|array<scalar>> $query
     *
     * @throws RouteUrlGenerationException When the route is unknown or a parameter is missing, unexpected or invalid.
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
                    throw RouteUrlGenerationException::missingParameter($name, $routeName);
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
            $query = $this->normalizeQuery($query);
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
                throw RouteUrlGenerationException::unexpectedParameter((string) $name, $routeName);
            }
        }
    }

    private function assertParameterMatchesConstraints(Route $route, string $routeName, string $name, string $value): void
    {
        $pattern = $route->constraints[$name] ?? null;
        if ($pattern === null) {
            return;
        }

        // The D modifier must match RouteCollection::compileConstraintRegex(): without it "$" accepts a trailing newline.
        $regex = '~^(?:' . str_replace('~', '\\~', $pattern) . ')$~D';
        if (@preg_match($regex, '') === false) {
            throw RouteUrlGenerationException::constraintViolation(
                $name,
                $routeName,
                sprintf('invalid constraint pattern "%s"', $pattern),
            );
        }

        if (preg_match($regex, $value) !== 1) {
            throw RouteUrlGenerationException::constraintViolation(
                $name,
                $routeName,
                sprintf('value %s does not satisfy constraint "%s"', MessageValue::quote($value), $pattern),
            );
        }
    }

    /**
     * Builds the URL of a named route and signs it.
     *
     * @param array<string, scalar> $parameters
     * @param array<string, scalar|array<scalar>> $query
     *
     * @throws RouteUrlGenerationException As generate(), or when no RouteSignature is configured.
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
     * Builds the URL of a named route and signs it, expiring $ttlSeconds from now.
     *
     * @param array<string, scalar> $parameters
     * @param array<string, scalar|array<scalar>> $query
     *
     * @throws RouteUrlGenerationException When $ttlSeconds is not positive, no RouteSignature is configured, or generate() fails.
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

    /**
     * Builds the URL of a named route and signs it, expiring at an absolute instant.
     *
     * @param array<string, scalar> $parameters
     * @param array<string, scalar|array<scalar>> $query
     *
     * @throws RouteUrlGenerationException When no RouteSignature is configured or generate() fails.
     * @throws RouteSignatureException When $expiresAt is not after the current time.
     */
    public function generateTemporarySignedUntil(
        string $routeName,
        int $expiresAt,
        array $parameters = [],
        array $query = [],
        ?string $fragment = null,
        ?int $now = null,
    ): string {
        return $this->requireSignature()->signTemporaryUntil(
            $this->generate($routeName, $parameters, $query, $fragment),
            expiresAt: $expiresAt,
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

    /**
     * @param array<string, scalar|array<scalar>> $query
     * @return array<string, scalar|array<scalar>>
     */
    private function normalizeQuery(array $query): array
    {
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                $query[$key] = $this->normalizeQueryArray($value);
            }
        }

        ksort($query);

        return $query;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function normalizeQueryArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalizeQueryArray($item);
            }
        }

        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    private function requireSignature(): RouteSignature
    {
        if ($this->signature === null) {
            throw new RouteUrlGenerationException('Cannot generate signed URL without a RouteSignature instance');
        }

        return $this->signature;
    }
}
