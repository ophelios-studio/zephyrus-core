<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Core\App;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Middleware that appends a Content Security Policy header on responses.
 *
 * - Accepts either a prebuilt ContentSecurityPolicy object or a raw header string.
 * - Skips header emission when the policy resolves to an empty string.
 * - Supports report-only mode via Content-Security-Policy-Report-Only.
 * - Optionally mints a per-request nonce, see below. Off by default.
 *
 * ## Per-request nonce (opt in)
 *
 * A single inline <script> is enough to force a project to drop script-src
 * entirely, which throws away the one directive that actually mitigates XSS.
 * A nonce keeps that inline block working while script-src stays strict.
 *
 * Pass the directives that should receive the nonce:
 *
 *   $policy = (new ContentSecurityPolicy())
 *       ->withDirective('default-src', "'self'")
 *       ->withDirective('script-src', "'self'");
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new ContentSecurityPolicyMiddleware($policy, nonceDirectives: ['script-src']))
 *       ->build();
 *
 * In a template, emit it on the inline block:
 *
 *   <script nonce="{nonce()}">...</script>
 *
 * The nonce is minted before the request reaches the handler, so the value the
 * template reads through nonce() is the value that ends up in the header. It is
 * 16 CSPRNG bytes, base64 encoded, regenerated for every request and never
 * reused across responses.
 *
 * ## The 'unsafe-inline' trap
 *
 * Under CSP Level 2 and later, a nonce in a directive makes browsers IGNORE
 * 'unsafe-inline' in that same directive. So adding a nonce to a script-src of
 * "'self' 'unsafe-inline'" does not loosen anything, it silently stops every
 * inline script in the project from running. That is why this is opt in per
 * directive and never automatic: enabling it is a decision about the
 * application's own markup, not something a framework can infer.
 *
 * When debug is on, a combination of a nonce and 'unsafe-inline' in the same
 * directive raises an E_USER_WARNING naming the directive. The policy is never
 * rewritten: silently editing a security policy would be worse than the warning.
 *
 * ## Ordering against SecureHeadersMiddleware
 *
 * SecureHeadersMiddleware also emits Content-Security-Policy when its config
 * carries a csp value, and global middlewares write their headers while the
 * response unwinds, so the FIRST REGISTERED middleware writes last and wins.
 *
 * Register this middleware BEFORE SecureHeadersMiddleware, or leave
 * SecureHeadersConfig::csp empty. Registering SecureHeaders first with a csp
 * set silently replaces the policy built here, nonce included, and the inline
 * scripts relying on that nonce stop running with nothing to explain why. Only
 * one of the two should own the CSP header.
 */
final readonly class ContentSecurityPolicyMiddleware implements MiddlewareInterface
{
    private string $policy;

    private ?ContentSecurityPolicy $nonceTemplate;

    /** @var list<string> */
    private array $nonceDirectives;

    /**
     * @param list<string> $nonceDirectives Directives that receive the
     *   per-request nonce, e.g. ['script-src']. Empty (the default) disables
     *   nonce generation entirely and leaves the emitted header untouched.
     *
     * @throws InvalidArgumentException When nonce directives are requested for
     *   a raw string policy, which cannot be extended safely.
     */
    public function __construct(
        ContentSecurityPolicy|string $policy,
        private bool $reportOnly = false,
        array $nonceDirectives = [],
    ) {
        $this->policy = $policy instanceof ContentSecurityPolicy
            ? $policy->toHeaderValue()
            : trim($policy);

        $this->nonceDirectives = array_values($nonceDirectives);

        if ($this->nonceDirectives !== [] && !$policy instanceof ContentSecurityPolicy) {
            throw new InvalidArgumentException(
                'A nonce requires a ContentSecurityPolicy instance, a raw policy string cannot be extended.',
            );
        }

        $this->nonceTemplate = $policy instanceof ContentSecurityPolicy ? $policy : null;
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->nonceDirectives === [] || $this->nonceTemplate === null) {
            // Unchanged path: no nonce is minted and the header is byte for
            // byte what it was before nonce support existed.
            /** @var Response $response */
            $response = $next($request);

            if ($this->policy === '') {
                return $response;
            }

            return $response->withHeader($this->headerName(), $this->policy);
        }

        // Mint BEFORE the handler runs, so the template reads the same value
        // that this response's header will carry.
        App::resetNonce();
        $nonce = App::nonce();

        $policy = $this->nonceTemplate;
        foreach ($this->nonceDirectives as $directive) {
            $this->warnOnUnsafeInline($policy, $directive);
            $policy = $policy->appendNonce($directive, $nonce);
        }

        /** @var Response $response */
        $response = $next($request);

        $headerValue = $policy->toHeaderValue();
        if ($headerValue === '') {
            return $response;
        }

        return $response->withHeader($this->headerName(), $headerValue);
    }

    /**
     * Warn, in debug only, when a nonce is being added to a directive that also
     * carries 'unsafe-inline'. Browsers ignore 'unsafe-inline' once a nonce is
     * present, so this combination silently disables the project's inline
     * scripts, which is exactly the failure that is hard to attribute later.
     */
    private function warnOnUnsafeInline(ContentSecurityPolicy $policy, string $directive): void
    {
        $configuration = App::getConfiguration();
        if ($configuration === null || !$configuration->application->debug) {
            return;
        }

        $values = $policy->toArray()[strtolower(trim($directive))] ?? [];
        if (!in_array("'unsafe-inline'", $values, true)) {
            return;
        }

        trigger_error(
            sprintf(
                "Content-Security-Policy: a nonce was added to %s, which also contains 'unsafe-inline'. "
                . "Browsers ignore 'unsafe-inline' when a nonce is present, so inline scripts without the "
                . 'nonce attribute will stop running. Remove one of the two.',
                strtolower(trim($directive)),
            ),
            E_USER_WARNING,
        );
    }

    private function headerName(): string
    {
        return $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }
}
