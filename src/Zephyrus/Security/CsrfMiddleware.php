<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

use function htmlspecialchars;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trim;

/**
 * Middleware that enforces synchronizer-token CSRF protection.
 *
 * Safe HTTP methods (GET, HEAD, OPTIONS, TRACE) pass through unchanged.
 * State-changing methods (POST, PUT, PATCH, DELETE) must supply a valid CSRF
 * token or the middleware returns a 403 Forbidden response without calling
 * the inner handler.
 *
 * Token lookup order (first match wins):
 *   1. Request body field (default: "_csrf_token", via CsrfConfig::bodyField).
 *   2. Request header (default: "X-CSRF-Token", via CsrfConfig::headerName).
 *
 * Both sources are checked so that traditional HTML forms and AJAX/fetch
 * clients can both authenticate their requests with the same token.
 *
 * Path exclusions
 * ---------------
 * Paths whose URI matches any PCRE pattern in CsrfConfig::excludedPathPatterns
 * are exempt from CSRF checks entirely.  This is useful for webhook receivers,
 * public API endpoints protected by other means (bearer tokens, HMAC, …), or
 * health-check routes.
 *
 *   $config = CsrfConfig::fromArray([
 *       'excluded_path_patterns' => ['#^/webhooks/#', '#^/api/public#'],
 *   ]);
 *   $mw = new CsrfMiddleware($sessionManager, $config);
 *
 * Unmatched routes
 * ----------------
 * A request that matched no route is never gated. When HttpKernel finds no
 * route it flags the request with Request::ATTRIBUTE_UNMATCHED_ROUTE, and this
 * middleware passes it straight through so the response is the 404 or 405 it
 * should be, not a 403. There is no resource to protect when nothing matched,
 * and a security-shaped error there hides an ordinary wrong-URL bug. The error
 * response still travels through the rest of the global pipeline, so it keeps
 * its security headers.
 *
 * The token is validated by the injected CsrfTokenManagerInterface using a
 * constant-time comparison; the middleware itself does not generate tokens.
 *
 * Usage:
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new CsrfMiddleware($sessionManager))
 *       ->build();
 *
 *   // In a template, embed the token:
 *   <input type="hidden" name="_csrf_token" value="<?= $manager->getToken() ?>">
 *
 *   // Or via AJAX header:
 *   fetch('/api/action', {
 *       method: 'POST',
 *       headers: { 'X-CSRF-Token': csrfToken },
 *   });
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    /** HTTP methods that do not mutate server state and are exempt from CSRF checks. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    /** Values that disable auto-injection when used with the data-csrf attribute. */
    private const INJECTION_SKIP_VALUES = ['off', 'false', '0', 'skip', 'disabled', 'disable', 'manual'];

    public function __construct(
        private readonly CsrfTokenManagerInterface $tokenManager,
        private readonly CsrfConfig $config = new CsrfConfig(),
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->config->enabled
            && !$this->isUnmatchedRoute($request)
            && !in_array($request->method, self::SAFE_METHODS, true)
            && !$this->isPathExcluded($request)
            && !$this->isTokenValid($request)
        ) {
            return Response::json(
                ['error' => 'Invalid or missing CSRF token.'],
                403,
            );
        }

        /** @var Response $response */
        $response = $next($request);

        if (!$this->config->injectToken) {
            return $response;
        }

        return $this->injectTokenIntoHtmlForms($response);
    }

    /**
     * Returns true when no route matched, so this request is heading for a 404
     * or a 405 and there is nothing to protect.
     *
     * CSRF defends a RESOURCE against a state change triggered by a third-party
     * site. When routing found nothing, no handler runs and no state changes,
     * so validating a token guards nothing. Answering 403 there would replace a
     * plain "that URL does not exist" with a security-shaped error, and send
     * whoever debugs it hunting a token or signature problem when the real
     * fault is the URL: a stale webhook or a renamed endpoint is the common
     * case. It also buys no secrecy, because GET is a safe method and already
     * reveals the same 404.
     *
     * The 404 or 405 still leaves through the rest of the global pipeline, so
     * it keeps every security header a matched response would carry.
     */
    private function isUnmatchedRoute(Request $request): bool
    {
        return $request->attribute(Request::ATTRIBUTE_UNMATCHED_ROUTE) === true;
    }

    /**
     * Returns true when the request path matches any of the configured
     * exclusion patterns and should bypass CSRF validation.
     */
    private function isPathExcluded(Request $request): bool
    {
        if ($this->config->excludedPathPatterns === []) {
            return false;
        }

        $path = $request->uri()->path();
        foreach ($this->config->excludedPathPatterns as $pattern) {
            if (@preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the submitted CSRF token from the request (body or header) and
     * delegate validation to the injected token manager.
     */
    private function isTokenValid(Request $request): bool
    {
        // Body field takes precedence over the header.
        $submitted = $request->body()->get($this->config->bodyField)
            ?? $request->headers()->get($this->config->headerName);

        if ($submitted === null || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return $this->tokenManager->isTokenValid($submitted);
    }

    private function injectTokenIntoHtmlForms(Response $response): Response
    {
        if ($response->body === '' || !$this->isHtmlResponse($response)) {
            return $response;
        }

        $token = htmlspecialchars($this->tokenManager->getToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $field = htmlspecialchars($this->config->bodyField, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rawField = $this->config->bodyField;

        $injectedBody = preg_replace_callback(
            '/<form\b[^>]*>.*?<\/form>/is',
            static function (array $match) use ($field, $rawField, $token): string {
                $formHtml = $match[0];

                if (!preg_match('/<form\b[^>]*>/i', $formHtml, $openTagMatch)) {
                    return $formHtml;
                }

                $openTag = $openTagMatch[0];

                if (!self::formRequiresCsrfToken($openTag)) {
                    return $formHtml;
                }

                if (self::formOptedOutOfInjection($openTag)) {
                    return $formHtml;
                }

                if (self::formAlreadyContainsTokenField($formHtml, $rawField)) {
                    return $formHtml;
                }

                return preg_replace(
                    '/<form\b[^>]*>/i',
                    sprintf(
                        "\$0\n" . '<input type="hidden" name="%s" value="%s">',
                        $field,
                        $token,
                    ),
                    $formHtml,
                    1,
                ) ?? $formHtml;
            },
            $response->body,
        );

        if ($injectedBody === null) {
            return $response;
        }

        return new Response($injectedBody, $response->status, $response->headers);
    }

    private static function formRequiresCsrfToken(string $formTag): bool
    {
        if (preg_match('/\bmethod\s*=\s*["\']?([a-zA-Z]+)["\']?/i', $formTag, $matches) !== 1) {
            return false;
        }

        return in_array(strtoupper($matches[1]), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private static function formAlreadyContainsTokenField(string $formHtml, string $fieldName): bool
    {
        $quotedField = preg_quote($fieldName, '/');

        return preg_match('/<input\b[^>]*\bname\s*=\s*["\']' . $quotedField . '["\'][^>]*>/i', $formHtml) === 1;
    }

    private static function formOptedOutOfInjection(string $formTag): bool
    {
        if (preg_match('/\bdata-csrf\b/i', $formTag) !== 1) {
            return false;
        }

        if (preg_match('/\bdata-csrf\s*=\s*(["\'])(.*?)\1/i', $formTag, $match) === 1) {
            $value = strtolower(trim($match[2]));

            return $value === '' || in_array($value, self::INJECTION_SKIP_VALUES, true);
        }

        if (preg_match('/\bdata-csrf\s*=\s*([^\s>"\']+)/i', $formTag, $match) === 1) {
            $value = strtolower(trim($match[1]));

            return $value === '' || in_array($value, self::INJECTION_SKIP_VALUES, true);
        }

        // Boolean attribute (no explicit value) means \"don't touch this form\".
        return true;
    }

    private function isHtmlResponse(Response $response): bool
    {
        foreach ($response->headers as $header => $value) {
            if (strtolower($header) === 'content-type' && str_contains(strtolower($value), 'text/html')) {
                return true;
            }
        }

        return false;
    }
}
