<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use InvalidArgumentException;
use Zephyrus\Core\App;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;

/**
 * Appends a Content-Security-Policy header to responses that do not already carry one with a non-blank value.
 *
 * Accepts a ContentSecurityPolicy object or a raw string, and sends nothing when the policy is
 * blank. Report-only mode uses Content-Security-Policy-Report-Only.
 *
 * Nonces are opt-in per directive. A nonce is 16 CSPRNG bytes, base64 encoded, regenerated for
 * every request and never reused across requests. The policy is rebuilt per request from the
 * immutable template, so the header always carries that request's nonce.
 *
 *   $policy = ContentSecurityPolicy::create()
 *       ->withDirective('default-src', "'self'")
 *       ->withDirective('script-src', "'self'");
 *
 *   $kernel = KernelBuilder::create()
 *       ->withMiddleware(new ContentSecurityPolicyMiddleware($policy, nonceDirectives: ['script-src']))
 *       ->build();
 *
 * Template: <script nonce="{nonce()}">...</script>
 *
 * A nonce or hash makes browsers ignore 'unsafe-inline' in the same directive, which would silently
 * stop inline scripts. Debug mode warns about that combination for a ContentSecurityPolicy object only,
 * and the policy is never rewritten.
 *
 * Register this middleware AFTER SecureHeadersMiddleware, or leave its csp empty: the innermost
 * middleware writes the header first and wins, and KernelBuilder::build() refuses the reverse order
 * among global middlewares when the csp is set.
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
     * @throws InvalidArgumentException When the policy holds a control character.
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
            : trim($policy, SecureHeadersConfig::TRIMMED_CHARACTERS);

        if (!Response::isValidHeaderValue($this->policy)) {
            throw new InvalidArgumentException(sprintf(
                'The %s value contains a control character; write it on one line or build it with ContentSecurityPolicy.',
                $this->headerName(),
            ));
        }

        $this->nonceDirectives = array_values($nonceDirectives);

        if ($this->nonceDirectives !== [] && !$policy instanceof ContentSecurityPolicy) {
            throw new InvalidArgumentException(
                'A nonce requires a ContentSecurityPolicy instance, a raw policy string cannot be extended.',
            );
        }

        $this->nonceTemplate = $policy instanceof ContentSecurityPolicy ? $policy : null;
    }

    /**
     * Whether this middleware can send a non-blank enforced header on a response.
     *
     * @internal Read by KernelBuilder only.
     */
    public function sendsEnforcedPolicy(): bool
    {
        return !$this->reportOnly && ($this->policy !== '' || $this->nonceDirectives !== []);
    }

    public function process(Request $request, callable $next): Response
    {
        if ($this->nonceTemplate !== null) {
            $this->warnOnUnsafeInline($this->nonceTemplate);
        }

        if ($this->nonceDirectives === [] || $this->nonceTemplate === null) {
            // No nonce: the static policy is sent as configured.
            /** @var Response $response */
            $response = $next($request);

            if ($this->policy === '' || $response->hasNonBlankHeader($this->headerName())) {
                return $response;
            }

            return $response->withHeader($this->headerName(), $this->policy);
        }

        // Minted before the handler, so nonce() in the template matches the header.
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

        if ($response->hasNonBlankHeader($this->headerName())) {
            $this->warnNoncePolicyNotApplied();

            return $response;
        }

        return $response->withHeader($this->headerName(), $headerValue);
    }

    /** Debug only: a header already on the response wins, so the nonce policy is not sent. */
    private function warnNoncePolicyNotApplied(): void
    {
        if (!self::isDebug()) {
            return;
        }

        trigger_error(
            sprintf(
                'Content-Security-Policy: the nonce policy was not applied because %s is already set on the '
                . 'response, by SecureHeadersMiddleware registered after this middleware or by a route. '
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

    /** Debug only: warns about 'unsafe-inline' beside a nonce or hash in the same directive. */
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

    private function headerName(): string
    {
        return $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }
}
