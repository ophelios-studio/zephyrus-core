<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Middleware that auto-starts the PHP session and injects the SessionManager
 * into the request attributes.
 *
 * When added to the global middleware pipeline, every downstream middleware
 * and controller can access the session via:
 *
 *   $session = $request->attribute('session');
 *   $session->get('user');
 *
 * The middleware:
 * 1. Starts the session using the provided SessionConfig.
 * 2. Injects the SessionManager into the request as attribute 'session'.
 * 3. Passes the request to the next handler.
 *
 * Usage:
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new SessionMiddleware($config->session))
 *       ->build();
 */
final class SessionMiddleware implements MiddlewareInterface
{
    private SessionManager $session;
    private SessionConfig $config;

    /**
     * @param SessionConfig       $config  Session configuration.
     * @param SessionManager|null $session Optional custom SessionManager (useful for testing).
     */
    public function __construct(SessionConfig $config, ?SessionManager $session = null)
    {
        $this->config = $config;
        $this->session = $session ?? new SessionManager();
    }

    public function process(Request $request, callable $next): Response
    {
        $this->session->start($this->config);

        $request = $request->withAttribute('session', $this->session);

        return $next($request);
    }

    /**
     * Get the session manager instance (useful for testing).
     */
    public function getSessionManager(): SessionManager
    {
        return $this->session;
    }
}
