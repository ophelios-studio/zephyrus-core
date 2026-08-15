<?php

declare(strict_types=1);

namespace Zephyrus\Session;

use Zephyrus\Core\App;
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
 *
 * ## BEHAVIOUR CHANGE: this now runs on 404 and 405 responses
 *
 * Global middlewares used to be skipped entirely when no route matched,
 * because the routing exception was raised before the pipeline was built. They
 * now wrap error responses too, so that a 404 carries the security headers a
 * 200 carries. The side effect is that this middleware runs on requests that
 * match no route, and start() is eager, so **every 404 now creates a session**.
 * Laravel and Symfony behave the same way, but it is a real change.
 *
 * Measured, per 404, with this middleware registered globally:
 *
 *   before: no session, no save-handler call, no Set-Cookie
 *   after:  1 session, save-handler open + read + write + close, 1 Set-Cookie
 *
 * With a database-backed handler (see DatabaseSessionHandler) that read plus
 * write is 3 queries: 1 SELECT to read, then 1 SELECT + 1 INSERT to write. An
 * unauthenticated 404 crawl therefore becomes session-table INSERTs at the rate
 * the crawler sends requests.
 *
 * The framework ships no path-scoped registration, so if that matters for your
 * application, wrap this middleware to skip the traffic you do not want
 * sessions for (unauthenticated probes, health checks, static asset paths):
 *
 *   final class PathScopedMiddleware implements MiddlewareInterface
 *   {
 *       public function __construct(
 *           private readonly MiddlewareInterface $inner,
 *           private readonly string $skipPattern,
 *       ) {
 *       }
 *
 *       public function process(Request $request, callable $next): Response
 *       {
 *           // path(), never uri()->path(): the latter can differ from the
 *           // route that actually dispatches, which turns a skip rule into a
 *           // bypass.
 *           if (preg_match($this->skipPattern, $request->path()) === 1) {
 *               return $next($request);
 *           }
 *
 *           return $this->inner->process($request, $next);
 *       }
 *   }
 *
 * Register short-circuiting middlewares such as ForceHttpsMiddleware and
 * AllowedHostsMiddleware BEFORE this one, so a request they reject never opens
 * a session at all.
 *
 * To drop the 404 cost specifically, skip on Request::ATTRIBUTE_UNMATCHED_ROUTE,
 * which HttpKernel sets when nothing matched. This middleware does NOT do that
 * itself: Laravel and Symfony both start a session on a 404, and a session is
 * not a security gate whose absence would change an answer, so the framework
 * leaves the choice to the application rather than making it silently.
 *
 * start() is deliberately eager. Deferring it until the session is first read
 * or written would change when the session cookie is emitted, which is a
 * session-fixation relevant property, so it is not something this middleware
 * decides silently.
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

        // Make the session available both via request attribute and the
        // global App facade so that the session() helper works everywhere.
        App::setSession($this->session);
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
