<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

/**
 * Authorises a request when a named attribute holds one of the allowed values.
 *
 * An attribute name supplied by a route placeholder is always refused, by provenance
 * and before the value is read, even if a middleware later overwrote it
 * (see Request::isRouteParameter()). A URL value is caller input, and a publishing middleware
 * leaves it in place for an anonymous request. Route registration refuses placeholders named
 * after framework attributes (Route::RESERVED_PARAMETER_NAMES); application attributes
 * cannot be checked there, so this guard fails closed instead.
 */
final class RequestAttributeGuard implements AuthGuardInterface
{
    /**
     * @param list<scalar|null> $allowedValues
     */
    public function __construct(
        private readonly string $attribute,
        private readonly array $allowedValues,
    ) {
    }

    public function isAuthorized(Request $request): bool
    {
        if ($request->isRouteParameter($this->attribute)) {
            return false;
        }

        $value = $request->attribute($this->attribute);

        if (is_array($value) || is_object($value)) {
            return false;
        }

        return in_array($value, $this->allowedValues, true);
    }
}
