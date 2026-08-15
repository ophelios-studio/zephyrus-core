<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\KernelBuilder;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Security\ContentSecurityPolicy;
use Zephyrus\Security\ContentSecurityPolicyMiddleware;
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;

/**
 * Opt-in per-request CSP nonce.
 *
 * A single inline <script> otherwise forces a project to drop script-src
 * altogether, which removes the only directive that mitigates XSS. The nonce
 * keeps the inline block working while script-src stays strict.
 *
 * The nonce is strictly opt in because of a trap: under CSP Level 2 and later a
 * nonce makes browsers IGNORE 'unsafe-inline' in the same directive, so
 * injecting one automatically would stop every inline script in a project that
 * relies on 'unsafe-inline'.
 */
final class ContentSecurityPolicyNonceTest extends TestCase
{
    protected function tearDown(): void
    {
        App::reset();
        App::resetNonce();
    }

    private function policy(): ContentSecurityPolicy
    {
        return ContentSecurityPolicy::create()
            ->withDirective('default-src', "'self'")
            ->withDirective('script-src', "'self'")
            ->withDirective('style-src', "'self'");
    }

    private function kernel(ContentSecurityPolicyMiddleware $middleware): \Zephyrus\Core\HttpKernel
    {
        return KernelBuilder::create()
            ->withRouter((new Router())->get('/p', CspNonceController::class . '@ping'))
            ->withMiddleware($middleware)
            ->build();
    }

    // -- Non-breakage: default off ------------------------------------------

    /**
     * The non-breakage proof. A consumer that does not opt in gets the exact
     * header they got before nonce support existed, character for character.
     */
    public function testDefaultEmitsAByteIdenticalHeaderAndNoNonce(): void
    {
        $expected = "default-src 'self'; script-src 'self'; style-src 'self'";

        $response = $this->kernel(new ContentSecurityPolicyMiddleware($this->policy()))
            ->handle(Request::fromArray('GET', '/p'));

        self::assertSame($expected, $response->headers['content-security-policy']);
        self::assertStringNotContainsString('nonce-', $response->headers['content-security-policy']);
    }

    public function testDefaultStillSupportsRawStringPoliciesAndReportOnly(): void
    {
        $response = $this->kernel(new ContentSecurityPolicyMiddleware("default-src 'none'", reportOnly: true))
            ->handle(Request::fromArray('GET', '/p'));

        self::assertSame("default-src 'none'", $response->headers['content-security-policy-report-only']);
        self::assertArrayNotHasKey('content-security-policy', $response->headers);
    }

    public function testDefaultStillSkipsAnEmptyPolicy(): void
    {
        $response = $this->kernel(new ContentSecurityPolicyMiddleware(''))
            ->handle(Request::fromArray('GET', '/p'));

        self::assertArrayNotHasKey('content-security-policy', $response->headers);
    }

    // -- Enabled -------------------------------------------------------------

    public function testNonceIsAddedOnlyToTheRequestedDirectives(): void
    {
        $response = $this->kernel(
            new ContentSecurityPolicyMiddleware($this->policy(), nonceDirectives: ['script-src']),
        )->handle(Request::fromArray('GET', '/p'));

        $header = $response->headers['content-security-policy'];

        self::assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $header);
        // Untouched directives stay exactly as configured.
        self::assertStringContainsString("default-src 'self';", $header);
        self::assertStringContainsString("style-src 'self'", $header);
        self::assertSame(1, substr_count($header, 'nonce-'));
    }

    public function testNonceCanBeAddedToSeveralDirectives(): void
    {
        $response = $this->kernel(
            new ContentSecurityPolicyMiddleware($this->policy(), nonceDirectives: ['script-src', 'style-src']),
        )->handle(Request::fromArray('GET', '/p'));

        self::assertSame(2, substr_count($response->headers['content-security-policy'], 'nonce-'));
    }

    public function testNonceIsBase64OfSixteenRandomBytes(): void
    {
        $this->kernel(new ContentSecurityPolicyMiddleware($this->policy(), nonceDirectives: ['script-src']))
            ->handle(Request::fromArray('GET', '/p'));

        $decoded = base64_decode(App::nonce(), true);

        self::assertNotFalse($decoded, 'nonce must be valid base64');
        self::assertSame(16, strlen($decoded), 'nonce must carry 128 bits of entropy');
    }

    public function testTwoResponsesCarryDifferentNonces(): void
    {
        $kernel = $this->kernel(
            new ContentSecurityPolicyMiddleware($this->policy(), nonceDirectives: ['script-src']),
        );

        $first  = $kernel->handle(Request::fromArray('GET', '/p'))->headers['content-security-policy'];
        $second = $kernel->handle(Request::fromArray('GET', '/p'))->headers['content-security-policy'];

        self::assertNotSame($first, $second, 'a nonce must never be reused across responses');
    }

    /**
     * The value a template reads through nonce() must be the value the header
     * carries, otherwise the inline block is blocked. The controller stands in
     * for the template: it renders nonce() into the body during $next.
     */
    public function testNonceReachableFromATemplateMatchesTheHeader(): void
    {
        $response = $this->kernel(
            new ContentSecurityPolicyMiddleware($this->policy(), nonceDirectives: ['script-src']),
        )->handle(Request::fromArray('GET', '/p'));

        $rendered = $response->body;

        self::assertNotSame('', $rendered);
        self::assertStringContainsString("'nonce-" . $rendered . "'", $response->headers['content-security-policy']);
    }

    // -- The 'unsafe-inline' trap --------------------------------------------

    public function testNonceDoesNotRewriteAPolicyContainingUnsafeInline(): void
    {
        $policy = ContentSecurityPolicy::create()
            ->withDirective('script-src', ["'self'", "'unsafe-inline'"]);

        $response = $this->kernel(new ContentSecurityPolicyMiddleware($policy, nonceDirectives: ['script-src']))
            ->handle(Request::fromArray('GET', '/p'));

        $header = $response->headers['content-security-policy'];

        // The framework reports the conflict but never edits the policy: the
        // consumer's declared directive is emitted as written, plus the nonce.
        self::assertStringContainsString("'unsafe-inline'", $header);
        self::assertStringContainsString('nonce-', $header);
    }

    public function testDebugModeWarnsWhenANonceMeetsUnsafeInline(): void
    {
        App::setConfiguration(Configuration::fromArray(['application' => ['debug' => true]]));

        $policy = ContentSecurityPolicy::create()
            ->withDirective('script-src', ["'self'", "'unsafe-inline'"]);

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $this->kernel(new ContentSecurityPolicyMiddleware($policy, nonceDirectives: ['script-src']))
                ->handle(Request::fromArray('GET', '/p'));
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('script-src', $warnings[0]);
        self::assertStringContainsString("'unsafe-inline'", $warnings[0]);
    }

    public function testNoWarningWhenDebugIsOff(): void
    {
        App::setConfiguration(Configuration::fromArray(['application' => ['debug' => false]]));

        $policy = ContentSecurityPolicy::create()
            ->withDirective('script-src', ["'self'", "'unsafe-inline'"]);

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $this->kernel(new ContentSecurityPolicyMiddleware($policy, nonceDirectives: ['script-src']))
                ->handle(Request::fromArray('GET', '/p'));
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
    }

    public function testNoWarningWhenTheDirectiveHasNoUnsafeInline(): void
    {
        App::setConfiguration(Configuration::fromArray(['application' => ['debug' => true]]));

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            $this->kernel(new ContentSecurityPolicyMiddleware($this->policy(), nonceDirectives: ['script-src']))
                ->handle(Request::fromArray('GET', '/p'));
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $warnings);
    }

    // -- Misuse --------------------------------------------------------------

    public function testRawStringPolicyWithNonceDirectivesIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A nonce requires a ContentSecurityPolicy instance');

        new ContentSecurityPolicyMiddleware("script-src 'self'", nonceDirectives: ['script-src']);
    }

    // -- Interaction with SecureHeadersMiddleware ----------------------------

    /**
     * Pins a known collision rather than hiding it. Global middlewares write
     * headers as the response unwinds, so the FIRST registered one writes last
     * and wins. SecureHeadersMiddleware also emits Content-Security-Policy when
     * its config sets csp, so registering it first silently discards the
     * nonce'd policy built here.
     */
    public function testSecureHeadersRegisteredFirstSilentlyReplacesTheNoncedPolicy(): void
    {
        $cspMiddleware = new ContentSecurityPolicyMiddleware($this->policy(), nonceDirectives: ['script-src']);
        $secureHeaders = new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'none'"]));

        $correct = KernelBuilder::create()
            ->withRouter((new Router())->get('/p', CspNonceController::class . '@ping'))
            ->withMiddleware($cspMiddleware)
            ->withMiddleware($secureHeaders)
            ->build()
            ->handle(Request::fromArray('GET', '/p'));

        $collided = KernelBuilder::create()
            ->withRouter((new Router())->get('/p', CspNonceController::class . '@ping'))
            ->withMiddleware($secureHeaders)
            ->withMiddleware($cspMiddleware)
            ->build()
            ->handle(Request::fromArray('GET', '/p'));

        self::assertStringContainsString('nonce-', $correct->headers['content-security-policy']);
        // The documented failure: register SecureHeaders first with a csp set
        // and the nonce is gone.
        self::assertSame("default-src 'none'", $collided->headers['content-security-policy']);
        self::assertStringNotContainsString('nonce-', $collided->headers['content-security-policy']);
    }
}

/** Stands in for a template: renders the nonce into the body during $next. */
final class CspNonceController
{
    public function ping(): Response
    {
        return Response::text(nonce());
    }
}
