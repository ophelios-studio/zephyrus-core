<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Throwable;
use Zephyrus\Exceptions\MessageValue;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Security\CsrfMiddleware;

final class RouteMiddlewareException extends ZephyrusRuntimeException
{
    private const int MAX_LISTED_ROUTES = 3;

    public static function unknownMiddleware(string $name): self
    {
        return new self(sprintf('Unknown route middleware: %s', $name));
    }

    /**
     * @param list<array{name: string, method: string, path: string}> $unknown The unregistered names, in route order.
     * @param list<string> $registered The registered route middleware names.
     */
    public static function unregisteredRouteMiddleware(array $unknown, array $registered): self
    {
        $groups = [];
        foreach ($unknown as $entry) {
            $groups[$entry['name']] ??= ['name' => $entry['name'], 'routes' => []];
            $groups[$entry['name']]['routes'][] = sprintf(
                '%s %s',
                MessageValue::escapeControls($entry['method']),
                MessageValue::escapeControls($entry['path']),
            );
        }

        $entries = [];
        foreach ($groups as $group) {
            $entries[] = self::describeUnknownName($group['name'], $group['routes']);
        }

        $single = count($groups) === 1;
        $lead = $single ? 'Route middleware is not registered' : 'Route middlewares are not registered';
        $target = $single ? 'it' : 'each';
        $names = $registered === []
            ? 'no middleware is registered'
            : sprintf('registered names: %s', MessageValue::quoteList($registered));

        return new self(sprintf(
            '%s: %s; %s. Register %s with registerMiddleware() before build(), or, for a group, '
            . 'define it with Router::middlewareGroup() before the routes that name it.',
            $lead,
            implode(', ', $entries),
            $names,
            $target,
        ));
    }

    /**
     * @param list<string> $routes The "METHOD /path" of each route naming the middleware.
     */
    private static function describeUnknownName(string $name, array $routes): string
    {
        $quoted = MessageValue::quote($name);
        $count = count($routes);

        if ($count === 1) {
            return sprintf('%s on %s', $quoted, $routes[0]);
        }

        return sprintf(
            '%s on %d routes (%s%s)',
            $quoted,
            $count,
            implode(', ', array_slice($routes, 0, self::MAX_LISTED_ROUTES)),
            $count > self::MAX_LISTED_ROUTES ? ', ...' : '',
        );
    }

    public static function resolutionFailed(string $name, Throwable $previous): self
    {
        return new self(
            sprintf('Unable to resolve route middleware "%s": %s', $name, $previous->getMessage()),
            0,
            $previous,
        );
    }

    /**
     * @param array<int, string> $stack
     */
    public static function circularGroupReference(array $stack, string $name): self
    {
        return new self(sprintf(
            'Circular middleware group reference detected: %s',
            implode(' -> ', [...$stack, $name]),
        ));
    }

    public static function excludedNotAMiddleware(string $subject, string $class): self
    {
        return new self(sprintf(
            '%s cannot skip "%s": it does not exist or does not implement %s. '
            . 'Pass the class of a global middleware, for example SessionMiddleware::class',
            $subject,
            $class,
            MiddlewareInterface::class,
        ));
    }

    /**
     * @internal
     *
     * @param list<string> $classes The classes of the group that can be skipped.
     */
    public static function excludedMiddlewareGroup(string $subject, string $name, array $classes): self
    {
        $hint = $classes === []
            ? ' with no class that can be skipped'
            : sprintf(': list its classes instead (%s)', implode(', ', $classes));

        return new self(sprintf('%s cannot skip "%s": "%s" is a middleware group%s', $subject, $name, $name, $hint));
    }

    public static function excludedSecurityMiddleware(string $subject, string $class, string $security): self
    {
        $reason = $class === $security
            ? 'it is a framework security middleware'
            : sprintf('it would skip the framework security middleware %s', $security);
        $message = sprintf('%s cannot skip "%s": %s', $subject, $class, $reason);

        if ($security === CsrfMiddleware::class) {
            $message .= '. Exempt the path under security.csrf.exceptions instead';
        }

        return new self($message);
    }
}
