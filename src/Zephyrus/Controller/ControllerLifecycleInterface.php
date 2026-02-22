<?php

declare(strict_types=1);

namespace Zephyrus\Controller;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Optional lifecycle hooks for controller classes.
 *
 * Implement this interface (or extend Controller, which provides default
 * no-op implementations) to intercept dispatch at the controller level:
 *
 * - `before()` is called *before* the handler method. Return a Response to
 *   short-circuit dispatch (useful for authorization guards, rate limiting,
 *   early redirects). Return null to continue normal dispatch.
 *
 * - `after()` is called *after* the handler method returns its Response.
 *   May inspect, decorate, or replace the response (useful for adding
 *   security headers, audit logging, response normalization). Must return
 *   a Response.
 *
 * ## Example
 *
 * ```php
 * final class SecuredController extends Controller
 * {
 *     public function before(Request $request): ?Response
 *     {
 *         if (!$this->isAuthenticated($request)) {
 *             return $this->respond(['error' => 'Unauthorized'], 401);
 *         }
 *         return null;
 *     }
 *
 *     public function after(Request $request, Response $response): Response
 *     {
 *         return $response->withHeader('X-Frame-Options', 'DENY');
 *     }
 * }
 * ```
 */
interface ControllerLifecycleInterface
{
    /**
     * Pre-dispatch hook. Return a Response to halt dispatch; return null
     * to allow the handler method to be invoked normally.
     */
    public function before(Request $request): ?Response;

    /**
     * Post-dispatch hook. Receives the Response produced by the handler
     * method and must return a (possibly modified) Response.
     */
    public function after(Request $request, Response $response): Response;
}
