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
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;

/**
 * The security: block must not be inert: declared keys have to reach a global middleware. The framework
 * registers none itself (applications mount their own, and a second copy would run each security middleware
 * twice on every request), so build() refuses to start and says how to mount the missing middleware.
 */
final class SecurityConfigurationWiringTest extends TestCase
{
    private const array DECLARED_HEADERS = ['security' => ['headers' => ['csp' => "default-src 'self'"]]];

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
            self::assertStringContainsString(
                'security.forceHttps is not enforced: mount ' . ForceHttpsMiddleware::class . ' with withMiddleware()',
                $message,
            );
            self::assertStringContainsString(
                'security.allowedHosts is not enforced: mount ' . AllowedHostsMiddleware::class
                . ' with withMiddleware()',
                $message,
            );
            self::assertStringContainsString(
                'security.csrf is not enforced: mount ' . CsrfMiddleware::class . ' with withMiddleware()',
                $message,
            );
            self::assertStringContainsString(
                'security.maxBodySize is not enforced: mount ' . MaxBodySizeMiddleware::class
                . ' with withMiddleware()',
                $message,
            );
            self::assertStringContainsString(
                'Mount the middleware(s) globally with withMiddleware(), or acknowledge the gap explicitly with',
                $message,
            );
            self::assertStringNotContainsString('register', $message);
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

    public function testDeclaredHeadersWithoutTheMiddlewareRefuseToBoot(): void
    {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray(self::DECLARED_HEADERS)
                ->withRouter(new Router())
                ->build();

            self::fail('build() accepted declared security headers that nothing sends.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'security.headers is not enforced: mount new ' . SecureHeadersMiddleware::class
                . "(...) globally with withMiddleware(), built from the configuration's security->headers",
                $exception->getMessage(),
            );
        }
    }

    public function testDeclaredHeadersWithTheMiddlewareMountedBootNormally(): void
    {
        $application = $this->bootWithDeclaredHeaders(
            new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"])),
        );

        self::assertInstanceOf(Application::class, $application);
    }

    public function testDeclaredHeadersWithTheMiddlewareMountedWithAnotherConfigurationRefuseToBoot(): void
    {
        try {
            $this->bootWithDeclaredHeaders(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()));

            self::fail('build() accepted a declared csp that the mounted middleware never sends.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'security.headers is not enforced: give the global ' . SecureHeadersMiddleware::class
                . " the configuration's security->headers: the global instance carries another configuration",
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('remove the extra ones', $exception->getMessage());
        }
    }

    public function testAGlobalInstanceOnAnotherConfigurationIsNotDescribedAsRouteOnly(): void
    {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray(self::DECLARED_HEADERS)
                ->withRouter(new Router())
                ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
                ->registerMiddleware(
                    'sec',
                    new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"])),
                )
                ->build();

            self::fail('build() accepted a global SecureHeadersMiddleware with another configuration.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'the global instance carries another configuration',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString("registered only under route name 'sec'", $exception->getMessage());
        }
    }

    public function testDeclaredHeadersRefuseToBootWhenBothMountedMiddlewaresDiffer(): void
    {
        try {
            $this->bootWithDeclaredHeaders(
                new SecureHeadersMiddleware(SecureHeadersConfig::defaults()),
                new SecureHeadersMiddleware(SecureHeadersConfig::defaults()),
            );

            self::fail('build() accepted two global SecureHeadersMiddleware with another configuration.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'all 2 global instances carry another configuration',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('remove the extra ones', $exception->getMessage());
        }
    }

    public function testDeclaredHeadersRefuseToBootWhenTheFirstOfTwoMountedMiddlewaresDiffers(): void
    {
        try {
            $this->bootWithDeclaredHeaders(
                new SecureHeadersMiddleware(SecureHeadersConfig::defaults()),
                new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"])),
            );

            self::fail('build() accepted a second global SecureHeadersMiddleware with another configuration.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                '1 of the 2 global instances carries another configuration',
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                'give every global ' . SecureHeadersMiddleware::class,
                $exception->getMessage(),
            );
        }
    }

    public function testDeclaredHeadersRefuseToBootWhenTheLastOfTwoMountedMiddlewaresDiffers(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->bootWithDeclaredHeaders(
            new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"])),
            new SecureHeadersMiddleware(SecureHeadersConfig::defaults()),
        );
    }

    public function testDeclaredHeadersBootWhenBothMountedMiddlewaresCarryThem(): void
    {
        $application = $this->bootWithDeclaredHeaders(
            new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"])),
            new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"])),
        );

        self::assertInstanceOf(Application::class, $application);
    }

    public function testDeclaredHeadersWithAnotherConfigurationAcknowledgedBootNormally(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(self::DECLARED_HEADERS)
            ->withAcknowledgedSecurityKeys(['headers'])
            ->withRouter(new Router())
            ->withMiddleware(new SecureHeadersMiddleware(SecureHeadersConfig::defaults()))
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    public function testDeclaredHeadersAcknowledgedBootNormally(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(self::DECLARED_HEADERS)
            ->withAcknowledgedSecurityKeys(['headers'])
            ->withRouter(new Router())
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    public function testUndeclaredHeadersBootWithoutTheMiddleware(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['csrf' => ['enabled' => false]]])
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
            self::assertStringContainsString(
                "it is registered only under route name 'guard'",
                $exception->getMessage(),
            );
        }
    }

    public function testARouteNameOnlyCsrfMiddlewareAlsoPointsAtTheExemptions(): void
    {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray(['security' => ['csrf' => ['enabled' => true]]])
                ->withRouter(new Router())
                ->registerMiddleware('api', new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()))
                ->registerMiddleware('admin', new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()))
                ->build();

            self::fail('build() accepted a csrf middleware that only route names reference');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'security.csrf is not enforced: mount ' . CsrfMiddleware::class
                . ' with withMiddleware() and exempt routes through security.csrf.exceptions; '
                . "it is registered only under route names 'api', 'admin'",
                $exception->getMessage(),
            );
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

    /**
     * Builds an application declaring security.headers with the given middlewares mounted globally.
     */
    private function bootWithDeclaredHeaders(MiddlewareInterface ...$middlewares): Application
    {
        $builder = ApplicationBuilder::create()
            ->withConfigurationArray(self::DECLARED_HEADERS)
            ->withRouter(new Router());

        foreach ($middlewares as $middleware) {
            $builder = $builder->withMiddleware($middleware);
        }

        return $builder->build();
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
