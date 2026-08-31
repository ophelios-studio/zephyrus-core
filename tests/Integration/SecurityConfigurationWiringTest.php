<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Application;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Http\Request;
use Zephyrus\Http\Response;
use Zephyrus\Routing\Router;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\CsrfConfig;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\CsrfTokenManagerInterface;
use Zephyrus\Security\ForceHttpsMiddleware;
use Zephyrus\Security\MaxBodySizeMiddleware;

/**
 * The whole `security:` block was inert.
 *
 * withConfiguration() wires localization, application.debug and the timezone;
 * KernelBuilder::build() never reads Configuration at all. So forceHttps,
 * csrfEnabled, allowedHosts and maxBodySize were parsed, type-validated,
 * range-checked, unit-tested, echoed by Configuration::toArray() and connected
 * to nothing. Measured against the pre-fix code with all four declared ON: a
 * plain-HTTP, forged-Host, tokenless 5 MiB POST was served with 200.
 *
 * The framework still wires NOTHING, on purpose. Applications register their
 * own CsrfMiddleware from their own bootstrap, and a framework that started
 * registering one too would hand them two on every request. So build() refuses
 * to start and names the gap instead.
 */
final class SecurityConfigurationWiringTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function lockedDownSecurity(): array
    {
        return [
            'security' => [
                'force_https' => true,
                'allowed_hosts' => ['app.test'],
                'max_body_size' => 2_097_152,
                'csrf' => ['enabled' => true],
            ],
        ];
    }

    public function testAnUnwiredSecurityBlockRefusesToBootAndNamesEveryGap(): void
    {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray($this->lockedDownSecurity())
                ->withRouter(new Router())
                ->build();

            self::fail('build() accepted a security block that enforces nothing');
        } catch (ConfigurationException $exception) {
            $message = $exception->getMessage();

            self::assertStringContainsString('security.forceHttps', $message);
            self::assertStringContainsString('security.allowedHosts', $message);
            self::assertStringContainsString('security.csrf', $message);
            self::assertStringContainsString('security.maxBodySize', $message);

            // It must say what to DO, not merely that something is wrong.
            self::assertStringContainsString(ForceHttpsMiddleware::class, $message);
            self::assertStringContainsString(AllowedHostsMiddleware::class, $message);
            self::assertStringContainsString(CsrfMiddleware::class, $message);
            self::assertStringContainsString(MaxBodySizeMiddleware::class, $message);
        }
    }

    public function testAFullyWiredSecurityBlockBootsNormally(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray($this->lockedDownSecurity())
            ->withRouter(new Router())
            ->withMiddleware(new ForceHttpsMiddleware())
            ->withMiddleware(new AllowedHostsMiddleware(['app.test']))
            ->withMiddleware(new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()))
            ->withMiddleware(new MaxBodySizeMiddleware(2_097_152))
            ->build();

        self::assertInstanceOf(Application::class, $application);

        // And the settings now actually DO something: a plain-HTTP request is
        // redirected instead of being served, which is the whole point.
        self::assertSame(308, $application->handle(Request::fromArray('GET', 'http://app.test/x'))->status);
    }

    public function testAConfigurationWithNoSecuritySectionIsUnaffected(): void
    {
        // Non-breakage, and the reason declared-key tracking exists: an absent
        // section still yields csrfEnabled = true and maxBodySize = 2 MB from
        // the DEFAULTS, and must not be reported.
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['application' => ['debug' => false]])
            ->withRouter(new Router())
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    public function testSettingsThatREQUESTNothingAreNeverReported(): void
    {
        // Declared, but each one asks for no protection: false, empty, empty.
        $application = ApplicationBuilder::create()
            ->withConfigurationArray([
                'security' => [
                    'force_https' => false,
                    'allowed_hosts' => [],
                    'trusted_proxies' => [],
                    'csrf' => ['enabled' => false],
                    'max_body_size' => 0,
                ],
            ])
            ->withRouter(new Router())
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    public function testAnAcknowledgedKeyIsAcceptedAndTheRestStillReported(): void
    {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray($this->lockedDownSecurity())
                ->withRouter(new Router())
                ->withMiddleware(new ForceHttpsMiddleware())
                ->withMiddleware(new AllowedHostsMiddleware(['app.test']))
                ->withMiddleware(new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()))
                // Capped by nginx rather than by a middleware.
                ->withAcknowledgedSecurityKeys(['security.maxBodySize'])
                ->build();
        } catch (ConfigurationException $exception) {
            self::fail('an acknowledged key was still reported: ' . $exception->getMessage());
        }

        $this->expectException(ConfigurationException::class);
        ApplicationBuilder::create()
            ->withConfigurationArray($this->lockedDownSecurity())
            ->withRouter(new Router())
            ->withAcknowledgedSecurityKeys(['maxBodySize'])
            ->build();
    }

    public function testTheCheckCanBeTurnedOffWholesale(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray($this->lockedDownSecurity())
            ->withRouter(new Router())
            ->withoutSecurityWiringCheck()
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    public function testAWrappingDecoratorIsNotDetectedAndMustBeAcknowledged(): void
    {
        // The stated limit of the check, asserted rather than assumed. The
        // framework's security middlewares are final, so a consumer that needs
        // to decorate one WRAPS it, and instanceof cannot see through that.
        $wrapped = new WrappingMiddleware(new ForceHttpsMiddleware());

        try {
            ApplicationBuilder::create()
                ->withConfigurationArray(['security' => ['force_https' => true]])
                ->withRouter(new Router())
                ->withMiddleware($wrapped)
                ->build();

            self::fail('a wrapped middleware was detected, which this check cannot do');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('security.forceHttps', $exception->getMessage());
        }

        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['force_https' => true]])
            ->withRouter(new Router())
            ->withMiddleware($wrapped)
            ->withAcknowledgedSecurityKeys(['forceHttps'])
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    // =====================================================================
    // The middleware the key had no consumer for
    // =====================================================================

    public function testMaxBodySizeMiddlewareRefusesAnOversizedBody(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_024);
        $request = Request::fromArray(
            'POST',
            '/upload',
            headers: ['content-length' => '2048'],
        );

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
        self::assertStringNotContainsString('HANDLED', $response->body);
    }

    public function testMaxBodySizeMiddlewareMeasuresTheRawBodyWhenNoLengthIsDeclared(): void
    {
        $middleware = new MaxBodySizeMiddleware(4);
        $request = Request::fromArray('POST', '/upload', rawBody: 'abcdefgh');

        $response = $middleware->process($request, static fn (): Response => Response::text('HANDLED'));

        self::assertSame(413, $response->status);
    }

    public function testMaxBodySizeMiddlewareLetsAnAcceptableBodyThrough(): void
    {
        $middleware = new MaxBodySizeMiddleware(1_024);
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '512']);

        self::assertSame(
            'HANDLED',
            $middleware->process($request, static fn (): Response => Response::text('HANDLED'))->body,
        );
    }

    public function testMaxBodySizeMiddlewareTreatsZeroAsUnlimited(): void
    {
        $middleware = new MaxBodySizeMiddleware(0);
        $request = Request::fromArray('POST', '/upload', headers: ['content-length' => '999999999']);

        self::assertSame(
            'HANDLED',
            $middleware->process($request, static fn (): Response => Response::text('HANDLED'))->body,
        );
    }
}

// ===========================================================================
// Fixtures
// ===========================================================================

final class WiringTokenManager implements CsrfTokenManagerInterface
{
    public function getToken(): string
    {
        return 'token';
    }

    public function isTokenValid(string $token): bool
    {
        return $token === 'token';
    }
}

final class WrappingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly MiddlewareInterface $inner)
    {
    }

    public function process(Request $request, callable $next): Response
    {
        return $this->inner->process($request, $next);
    }
}
