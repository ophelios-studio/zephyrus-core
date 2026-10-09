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
 *   $policy = ContentSecurityPolicy::create()
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
 * When debug is on, 'unsafe-inline' next to a nonce, or next to a hash in the
 * same directive, raises an E_USER_WARNING naming the directive. Matching is
 * case-insensitive, as browsers match keywords and hash algorithms. Only a
 * ContentSecurityPolicy object is inspected: a raw string policy is sent as
 * written. The policy is never rewritten: silently editing a security policy
 * would be worse than the warning.
 *
 * ## Ordering against SecureHeadersMiddleware
 *
 * SecureHeadersMiddleware also emits Content-Security-Policy when its config
 * carries a csp value. Both middlewares leave a header the response already
 * carries alone, and the response unwinds from the innermost middleware (the
 * one registered LAST) outwards, so the innermost writer wins.
 *
 * Register this middleware AFTER SecureHeadersMiddleware, or leave
 * SecureHeadersConfig::csp empty. Registered BEFORE it, this middleware is the
 * outer one, so SecureHeadersMiddleware's csp, written first from the inside,
 * reaches the client: the policy built here, nonce included, silently
 * disappears and the inline scripts relying on that nonce stop running. Only
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
        if ($this->nonceTemplate !== null) {
            $this->warnOnUnsafeInline($this->nonceTemplate);
        }

        if ($this->nonceDirectives === [] || $this->nonceTemplate === null) {
            // Unchanged path: no nonce is minted and the header is byte for
            // byte what it was before nonce support existed.
            /** @var Response $response */
            $response = $next($request);

            if ($this->policy === '' || $this->routeSetsHeader($response)) {
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
            $policy = $policy->appendNonce($directive, $nonce);
        }

        /** @var Response $response */
        $response = $next($request);

        $headerValue = $policy->toHeaderValue();
        if ($headerValue === '') {
            return $response;
        }

        if ($this->routeSetsHeader($response)) {
            $this->warnNoncePolicyNotApplied();

            return $response;
        }

        return $response->withHeader($this->headerName(), $headerValue);
    }

    /**
     * Debug only. The usual cause is SecureHeadersMiddleware registered before
     * this middleware: it wrote its csp first from the inside, so the nonce
     * policy is silently dropped. Say so, with the fix.
     */
    private function warnNoncePolicyNotApplied(): void
    {
        if (!self::isDebug()) {
            return;
        }

        trigger_error(
            sprintf(
                'Content-Security-Policy: the nonce policy was not applied because %s is already set on the '
                . 'response, by SecureHeadersMiddleware registered before this middleware or by a route. '
                . 'Register ContentSecurityPolicyMiddleware after SecureHeadersMiddleware, or leave '
                . 'SecureHeadersConfig::csp empty.',
                $this->headerName(),
            ),
            E_USER_WARNING,
        );
    }

    private static function isDebug(): bool
    {
        $configuration = App::getConfiguration();

        return $configuration !== null && $configuration->application->debug;
    }

    /**
     * Warn, in debug only, about 'unsafe-inline' in a directive that a nonce or
     * a hash also covers. Browsers ignore 'unsafe-inline' once either is
     * present, so the keyword looks harmless today and silently activates the
     * day the nonce or hash is removed. A nonce being added makes it worse: the
     * inline scripts stop running right away.
     */
    private function warnOnUnsafeInline(ContentSecurityPolicy $policy): void
    {
        if (!self::isDebug()) {
            return;
        }

        $nonceDirectives = array_map(
            static fn (string $directive): string => strtolower(trim($directive)),
            $this->nonceDirectives,
        );

        foreach ($policy->toArray() as $name => $values) {
            $directive = (string) $name;
            if (!self::containsUnsafeInline($values)) {
                continue;
            }

            $hasNonce = in_array($directive, $nonceDirectives, true);
            if (!$hasNonce && !self::containsHashSource($values)) {
                continue;
            }

            $message = $hasNonce
                ? "Content-Security-Policy: a nonce was added to %s, which also contains 'unsafe-inline'. "
                    . "Browsers ignore 'unsafe-inline' when a nonce is present, so inline scripts without the "
                    . 'nonce attribute will stop running. Remove one of the two.'
                : "Content-Security-Policy: %s contains a hash and 'unsafe-inline'. "
                    . "Browsers ignore 'unsafe-inline' when a hash is present, so the keyword has no effect "
                    . "today and silently activates the day the hash is removed. Remove one of the two.";

            trigger_error(sprintf($message, $directive), E_USER_WARNING);
        }
    }

    /**
     * @param list<string> $values
     */
    private static function containsUnsafeInline(array $values): bool
    {
        foreach ($values as $value) {
            if (strtolower($value) === "'unsafe-inline'") {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $values
     */
    private static function containsHashSource(array $values): bool
    {
        foreach ($values as $value) {
            if (preg_match("/^'sha(?:256|384|512)-/Di", $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /** A blank value counts as absent, so it does not block the configured policy. */
    private function routeSetsHeader(Response $response): bool
    {
        return trim($response->getHeader($this->headerName()) ?? '') !== '';
    }

    private function headerName(): string
    {
        return $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }
}
