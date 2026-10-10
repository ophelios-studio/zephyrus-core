<?php

declare(strict_types=1);

namespace Zephyrus\Controller;

use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Optional hooks around a controller's handler method, called by HandlerResolver.
 *
 * Order: before(), then the handler, then after(). A Response returned by before()
 * is sent as is, skipping the handler and after(). An exception thrown by the
 * handler also skips after().
 *
 * ```php
 * final class SecuredController extends Controller
 * {
 *     public function before(Request $request): ?Response
 *     {
 *         parent::before($request);
 *
 *         if (!$this->isAuthenticated($request)) { // your own check
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
     * Return a Response to halt dispatch, or null to invoke the handler.
     */
    public function before(Request $request): ?Response;

    /**
     * Return the handler's Response, possibly modified.
     */
    public function after(Request $request, Response $response): Response;
}
