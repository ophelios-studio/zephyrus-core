<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
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
 * The security: block must not be inert: declared keys have to reach a middleware. The framework
 * registers none itself (applications register their own, and a second copy would run a second
 * CsrfMiddleware on every request), so build() refuses to start and names the missing middleware.
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

        // A plain-HTTP request is redirected rather than served.
        self::assertSame(308, $application->handle(Request::fromArray('GET', 'http://app.test/x'))->status);
    }

    public function testAConfigurationWithNoSecuritySectionIsUnaffected(): void
    {
        // An absent section is not reported, although the defaults still yield values for it.
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

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('nameOnlyMiddlewareProvider')]
    public function testASecurityMiddlewareRegisteredOnlyUnderARouteNameRefusesToBoot(
        array $configuration,
        string $qualifiedSetting,
        MiddlewareInterface $middleware,
    ): void {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray($configuration)
                ->withRouter(new Router())
                ->registerMiddleware('guard', $middleware)
                ->build();

            self::fail(sprintf('build() accepted %s that only a route name references', $qualifiedSetting));
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($qualifiedSetting, $exception->getMessage());
            self::assertStringContainsString('withMiddleware()', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, MiddlewareInterface}>
     */
    public static function nameOnlyMiddlewareProvider(): iterable
    {
        yield 'forceHttps' => [
            ['security' => ['force_https' => true]],
            'security.forceHttps',
            new ForceHttpsMiddleware(),
        ];

        yield 'allowedHosts' => [
            ['security' => ['allowed_hosts' => ['app.test']]],
            'security.allowedHosts',
            new AllowedHostsMiddleware(['app.test']),
        ];

        yield 'csrf' => [
            ['security' => ['csrf' => ['enabled' => true]]],
            'security.csrf',
            new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()),
        ];

        yield 'maxBodySize' => [
            ['security' => ['max_body_size' => 2_097_152]],
            'security.maxBodySize',
            new MaxBodySizeMiddleware(2_097_152),
        ];
    }

    public function testCsrfRegisteredGloballyBootsNormally(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['csrf' => ['enabled' => true]]])
            ->withRouter(new Router())
            ->withMiddleware(new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()))
            ->build();

        self::assertInstanceOf(Application::class, $application);
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
        // Limit of the check: the middleware is final, so a wrapper hides it from instanceof.
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
    // Body size limit middleware
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
