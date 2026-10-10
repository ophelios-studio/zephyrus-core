<?php

declare(strict_types=1);

namespace Zephyrus\Routing\Exception;

use Throwable;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Security\CsrfMiddleware;

final class RouteMiddlewareException extends ZephyrusRuntimeException
{
    public static function unknownMiddleware(string $name): self
    {
        return new self(sprintf('Unknown route middleware: %s', $name));
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
