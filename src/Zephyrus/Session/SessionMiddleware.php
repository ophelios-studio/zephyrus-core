<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Core\App;
use Zephyrus\Core\Config\SessionConfig;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Starts the PHP session and injects the SessionManager into the request attribute 'session'.
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new SessionMiddleware($config->session))
 *       ->build();
 *
 * Global middleware also runs on 404 and 405 responses, and start() is eager, so a request that matches no
 * route starts a session. To skip sessions for some paths, wrap this middleware and test $request->path()
 * (not uri()->path(), which can differ from the dispatched route). Register short-circuiting middleware
 * such as ForceHttpsMiddleware before this one, so rejected requests never open a session.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    private SessionManager $session;
    private SessionConfig $config;

    /**
     * @param SessionConfig       $config  Session configuration.
     * @param SessionManager|null $session Optional custom SessionManager.
     */
    public function __construct(SessionConfig $config, ?SessionManager $session = null)
    {
        $this->config = $config;
        $this->session = $session ?? new SessionManager();
    }

    public function process(Request $request, callable $next): Response
    {
        // A forwarded protocol counts here only when the trusted-proxy allowlist accepts it.
        $this->session->start($this->config, $request->uri()->isSecure());

        // Also exposed through the App facade, so the session() helper works everywhere.
        App::setSession($this->session);
        $request = $request->withAttribute('session', $this->session);

        return $next($request);
    }

    /**
     * Get the session manager instance.
     */
    public function getSessionManager(): SessionManager
    {
        return $this->session;
    }
}
