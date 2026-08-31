<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\Request;

/**
 * Authorises a request when a named attribute holds one of the allowed values.
 *
 * ## A URL SEGMENT CAN NEVER SATISFY THIS GUARD
 *
 * The matched route's placeholders are merged into the attribute namespace
 * before the global pipeline runs, so a route parameter named after the guarded
 * attribute used to supply it straight from the URL. The exploitable ordering is
 * the natural one, because a publishing middleware only sets its attribute when
 * there is a session to read it from:
 *
 *   route /reports/{role}, guard on "role", values ['admin']
 *   anonymous, no session:  GET /reports/admin -> 200 CONFIDENTIAL REPORTS
 *   logged-in viewer:       GET /reports/admin -> 401 (the session overwrites it)
 *
 * A guard reads EVIDENCE ABOUT THE CALLER, and a value the caller typed into
 * the URL is not that, whatever a middleware may or may not have overwritten it
 * with afterwards. So a route-sourced name is refused outright, by provenance
 * rather than by value: see Request::isRouteParameter().
 *
 * Route registration is the loud half of the same defence, refusing a
 * placeholder named after an attribute the framework itself publishes; see
 * Route::RESERVED_PARAMETER_NAMES. A guard over an APPLICATION attribute cannot
 * be checked that way, because the builder cannot know which names an
 * application intends to guard, so this class fails closed instead.
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
        // Provenance first: a name the URL supplied is refused even when a
        // middleware has since overwritten it with an allowed value. See the
        // class docblock.
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
