<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Application;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Config\Configuration;
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
                'security.forceHttps is not enforced as declared: mount ' . ForceHttpsMiddleware::class . ' with withMiddleware()',
                $message,
            );
            self::assertStringContainsString(
                'security.allowedHosts is not enforced as declared: mount new ' . AllowedHostsMiddleware::class
                . "(...) globally with withMiddleware(), built from the configuration's security->allowedHosts",
                $message,
            );
            self::assertStringContainsString(
                'security.csrf is not enforced as declared: mount new ' . CsrfMiddleware::class
                . "(...) globally with withMiddleware(), built from the configuration's security through "
                . 'CsrfConfig::fromSecurityConfig()',
                $message,
            );
            self::assertStringContainsString(
                'security.maxBodySize is not enforced as declared: mount new ' . MaxBodySizeMiddleware::class
                . "(...) globally with withMiddleware(), built from the configuration's security->maxBodySize",
                $message,
            );
            self::assertStringStartsWith(
                "Configuration declares security settings that this application does not enforce as declared:\n",
                $message,
            );
            self::assertStringContainsString(
                'Apply the fix given for each setting, or acknowledge the gap explicitly with',
                $message,
            );
            self::assertStringNotContainsString('(s)', $message);
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
                'security.headers is not enforced as declared: mount new ' . SecureHeadersMiddleware::class
                . "(...) globally with withMiddleware(), built from the configuration's security->headers",
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('declaredValueProvider')]
    public function testAGlobalMiddlewareCarryingAnotherValueRefusesToBoot(
        array $configuration,
        string $setting,
        string $expectedInstruction,
        MiddlewareInterface $carryingAnotherValue,
        MiddlewareInterface $carryingTheDeclaredValue,
    ): void {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray($configuration)
                ->withRouter(new Router())
                ->withMiddleware($carryingAnotherValue)
                ->build();

            self::fail(sprintf('build() accepted a global middleware that does not carry security.%s', $setting));
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'security.' . $setting . ' is not enforced as declared: the global ' . $carryingAnotherValue::class . ' '
                . $expectedInstruction,
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('instance 1 of', $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('declaredValueProvider')]
    public function testAGlobalMiddlewareCarryingTheDeclaredValueBoots(
        array $configuration,
        string $setting,
        string $expectedInstruction,
        MiddlewareInterface $carryingAnotherValue,
        MiddlewareInterface $carryingTheDeclaredValue,
    ): void {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray($configuration)
            ->withRouter(new Router())
            ->withMiddleware($carryingTheDeclaredValue)
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('declaredValueProvider')]
    public function testAGlobalMiddlewareCarryingAnotherValueBootsWhenItsKeyIsAcknowledged(
        array $configuration,
        string $setting,
        string $expectedInstruction,
        MiddlewareInterface $carryingAnotherValue,
        MiddlewareInterface $carryingTheDeclaredValue,
    ): void {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray($configuration)
            ->withAcknowledgedSecurityKeys([$setting])
            ->withRouter(new Router())
            ->withMiddleware($carryingAnotherValue)
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    #[DataProvider('declaredValueProvider')]
    public function testAGlobalInstanceOnAnotherValueIsNotDescribedAsRouteOnly(
        array $configuration,
        string $setting,
        string $expectedInstruction,
        MiddlewareInterface $carryingAnotherValue,
        MiddlewareInterface $carryingTheDeclaredValue,
    ): void {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray($configuration)
                ->withRouter(new Router())
                ->withMiddleware($carryingAnotherValue)
                ->registerMiddleware('sec', $carryingTheDeclaredValue)
                ->build();

            self::fail(sprintf('build() accepted a global middleware that does not carry security.%s', $setting));
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString($expectedInstruction, $exception->getMessage());
            self::assertStringNotContainsString("registered only under route name 'sec'", $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, MiddlewareInterface, MiddlewareInterface}>
     */
    public static function declaredValueProvider(): iterable
    {
        $maxBodySize = ['security' => ['max_body_size' => 2_097_152]];
        $allowedHosts = ['security' => ['allowed_hosts' => ['app.test']]];
        $csrf = ['security' => ['csrf' => ['enabled' => true]]];
        $csrfExceptions = ['security' => ['csrf' => ['exceptions' => ['#^/webhooks/#']]]];
        $looserLimit = static fn (string $carried): string => 'carries a looser limit (' . $carried
            . ') than security.maxBodySize (2097152 bytes); '
            . 'give it a positive limit no larger than security.maxBodySize';
        $rebuildHosts = "build the middleware from the configuration's security->allowedHosts";
        $rebuildCsrf = 'build the middleware with CsrfConfig::fromSecurityConfig()';

        yield 'maxBodySize unlimited' => [
            $maxBodySize,
            'maxBodySize',
            $looserLimit('0, unlimited'),
            new MaxBodySizeMiddleware(0),
            new MaxBodySizeMiddleware(2_097_152),
        ];

        yield 'maxBodySize larger' => [
            $maxBodySize,
            'maxBodySize',
            $looserLimit('104857600 bytes'),
            new MaxBodySizeMiddleware(104_857_600),
            new MaxBodySizeMiddleware(2_097_152),
        ];

        yield 'maxBodySize one byte larger' => [
            $maxBodySize,
            'maxBodySize',
            $looserLimit('2097153 bytes'),
            new MaxBodySizeMiddleware(2_097_153),
            new MaxBodySizeMiddleware(2_097_152),
        ];

        yield 'allowedHosts empty' => [
            $allowedHosts,
            'allowedHosts',
            'carries an empty allowlist, which accepts every host; ' . $rebuildHosts,
            new AllowedHostsMiddleware([]),
            new AllowedHostsMiddleware(['app.test']),
        ];

        yield 'allowedHosts wider' => [
            $allowedHosts,
            'allowedHosts',
            'allows "evil.test", which security.allowedHosts does not list; '
            . 'declare it in security.allowedHosts, or ' . $rebuildHosts,
            new AllowedHostsMiddleware(['app.test', 'evil.test']),
            new AllowedHostsMiddleware(['app.test']),
        ];

        yield 'allowedHosts wildcard' => [
            $allowedHosts,
            'allowedHosts',
            'allows "*.app.test", which security.allowedHosts does not list, '
            . 'and omits "app.test", which security.allowedHosts lists; '
            . 'make security.allowedHosts match the middleware, or ' . $rebuildHosts,
            new AllowedHostsMiddleware(['*.app.test']),
            new AllowedHostsMiddleware(['app.test']),
        ];

        yield 'csrf disabled' => [
            $csrf,
            'csrf',
            'is disabled; ' . $rebuildCsrf,
            new CsrfMiddleware(new WiringTokenManager(), new CsrfConfig(enabled: false)),
            new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()),
        ];

        yield 'csrf extra exception' => [
            $csrf,
            'csrf',
            'excludes "#^/api/#", which security.csrf.exceptions does not list; '
            . 'declare it in security.csrf.exceptions, or ' . $rebuildCsrf,
            new CsrfMiddleware(new WiringTokenManager(), new CsrfConfig(excludedPathPatterns: ['#^/api/#'])),
            new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()),
        ];

        yield 'csrf missing exception' => [
            $csrfExceptions,
            'csrf',
            'does not exclude "#^/webhooks/#", which security.csrf.exceptions lists; '
            . 'remove it from security.csrf.exceptions, or ' . $rebuildCsrf,
            new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()),
            new CsrfMiddleware(new WiringTokenManager(), new CsrfConfig(excludedPathPatterns: ['#^/webhooks/#'])),
        ];

        yield 'headers' => [
            self::DECLARED_HEADERS,
            'headers',
            "carries another configuration; build the middleware from the configuration's security->headers",
            new SecureHeadersMiddleware(SecureHeadersConfig::defaults()),
            new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"])),
        ];
    }

    public function testDeclaredAllowedHostsMatchAMiddlewareSpellingThemInAnotherCaseAndOrder(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['allowed_hosts' => ['Example.COM', 'app.test', '2001:DB8::1']]])
            ->withRouter(new Router())
            ->withMiddleware(new AllowedHostsMiddleware(['[2001:db8::1]', 'app.test.', 'example.com']))
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    public function testDeclaredAllowedHostsMatchAMiddlewareListingDigitOnlyHostsInAnotherOrder(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['allowed_hosts' => ['10', '9', '1a']]])
            ->withRouter(new Router())
            ->withMiddleware(new AllowedHostsMiddleware(['1a', '10', '9']))
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    public function testDeclaredCsrfExceptionsMatchAMiddlewareListingThemInAnotherOrder(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(
                ['security' => ['csrf' => ['exceptions' => ['#^/webhooks/#', '#^/logout$#']]]],
            )
            ->withRouter(new Router())
            ->withMiddleware(new CsrfMiddleware(
                new WiringTokenManager(),
                new CsrfConfig(excludedPathPatterns: ['#^/logout$#', '#^/webhooks/#']),
            ))
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    public function testACsrfMiddlewareExcludingUndeclaredPathsNamesThemAndHowToKeepThem(): void
    {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray(['security' => ['csrf' => ['exceptions' => []]]])
                ->withRouter(new Router())
                ->withMiddleware(new CsrfMiddleware(
                    new WiringTokenManager(),
                    new CsrfConfig(excludedPathPatterns: ['#^/webhooks/#', '#^/passkey/#']),
                ))
                ->build();

            self::fail('build() accepted a CsrfMiddleware excluding paths security.csrf.exceptions does not list.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                "\n  - security.csrf is not enforced as declared: the global " . CsrfMiddleware::class
                . ' excludes "#^/webhooks/#", "#^/passkey/#", which security.csrf.exceptions does not list; '
                . 'declare them in security.csrf.exceptions, or build the middleware with '
                . "CsrfConfig::fromSecurityConfig()\n",
                $exception->getMessage(),
            );
        }
    }

    public function testDifferingValuesArePrintedAsJsonStrings(): void
    {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray(['security' => ['csrf' => ['exceptions' => []]]])
                ->withRouter(new Router())
                ->withMiddleware(new CsrfMiddleware(
                    new WiringTokenManager(),
                    new CsrfConfig(excludedPathPatterns: ["#^/caf\u{e9}\e/#"]),
                ))
                ->build();

            self::fail('build() accepted a CsrfMiddleware excluding a path security.csrf.exceptions does not list.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('excludes "#^/café\u001b/#", which', $exception->getMessage());
            self::assertStringNotContainsString("\e", $exception->getMessage());
        }
    }

    public function testASingleGlobalMaxBodySizeStricterThanTheDeclaredLimitBoots(): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['max_body_size' => 2_097_152]])
            ->withRouter(new Router())
            ->withMiddleware(new MaxBodySizeMiddleware(1))
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    #[DataProvider('noLooserSecondLimitProvider')]
    public function testASecondGlobalMaxBodySizeNoLooserThanTheDeclaredLimitBoots(int $secondLimit): void
    {
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['max_body_size' => 11_534_336]])
            ->withRouter(new Router())
            ->withMiddleware(new MaxBodySizeMiddleware(11_534_336))
            ->withMiddleware(new MaxBodySizeMiddleware($secondLimit))
            ->build();

        self::assertInstanceOf(Application::class, $application);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function noLooserSecondLimitProvider(): iterable
    {
        yield 'stricter' => [2_304_000];
        yield 'one byte' => [1];
        yield 'equal' => [11_534_336];
    }

    #[DataProvider('looserSecondLimitProvider')]
    public function testASecondGlobalMaxBodySizeLooserThanTheDeclaredLimitRefusesToBoot(
        int $secondLimit,
        string $describedLimit,
    ): void {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray(['security' => ['max_body_size' => 11_534_336]])
                ->withRouter(new Router())
                ->withMiddleware(new MaxBodySizeMiddleware(11_534_336))
                ->withMiddleware(new MaxBodySizeMiddleware($secondLimit))
                ->build();

            self::fail('build() accepted a second global MaxBodySizeMiddleware with a looser limit.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'security.maxBodySize is not enforced as declared: 1 of the 2 global ' . MaxBodySizeMiddleware::class
                . ' instances does not enforce it as declared, and every global instance must carry the declared value: fix or remove it'
                . "\n    - instance 2 of 2 carries a looser limit (" . $describedLimit . ') than security.maxBodySize '
                . '(11534336 bytes); give it a positive limit no larger than security.maxBodySize',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('instance 1 of 2', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function looserSecondLimitProvider(): iterable
    {
        yield 'unlimited' => [0, '0, unlimited'];
        yield 'one byte larger' => [11_534_337, '11534337 bytes'];
        yield 'larger' => [104_857_600, '104857600 bytes'];
    }

    public function testEveryGlobalMaxBodySizeLooserThanTheDeclaredLimitIsReportedInstanceByInstance(): void
    {
        try {
            ApplicationBuilder::create()
                ->withConfigurationArray(['security' => ['max_body_size' => 2_097_152]])
                ->withRouter(new Router())
                ->withMiddleware(new MaxBodySizeMiddleware(0))
                ->withMiddleware(new MaxBodySizeMiddleware(4_194_304))
                ->build();

            self::fail('build() accepted two global MaxBodySizeMiddleware with looser limits.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString(
                'security.maxBodySize is not enforced as declared: none of the 2 global ' . MaxBodySizeMiddleware::class
                . ' instances enforces it as declared, and every global instance must carry the declared value: fix each one'
                . "\n    - instance 1 of 2 carries a looser limit (0, unlimited) than security.maxBodySize "
                . '(2097152 bytes); give it a positive limit no larger than security.maxBodySize'
                . "\n    - instance 2 of 2 carries a looser limit (4194304 bytes) than security.maxBodySize "
                . '(2097152 bytes); give it a positive limit no larger than security.maxBodySize',
                $exception->getMessage(),
            );
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
                'security.headers is not enforced as declared: none of the 2 global ' . SecureHeadersMiddleware::class
                . ' instances enforces it as declared, and every global instance must carry the declared value: fix each one'
                . "\n    - instance 1 of 2 carries another configuration; "
                . "build the middleware from the configuration's security->headers"
                . "\n    - instance 2 of 2 carries another configuration; ",
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('fix or remove', $exception->getMessage());
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
                'security.headers is not enforced as declared: 1 of the 2 global ' . SecureHeadersMiddleware::class
                . ' instances does not enforce it as declared, and every global instance must carry the declared value: fix or remove it'
                . "\n    - instance 1 of 2 carries another configuration; "
                . "build the middleware from the configuration's security->headers\n",
                $exception->getMessage(),
            );
            self::assertStringNotContainsString('instance 2 of 2', $exception->getMessage());
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
                'security.csrf is not enforced as declared: mount new ' . CsrfMiddleware::class
                . "(...) globally with withMiddleware(), built from the configuration's security through "
                . 'CsrfConfig::fromSecurityConfig(), and list the exempt routes under security.csrf.exceptions; '
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

        yield 'headers' => [
            self::DECLARED_HEADERS,
            'security.headers',
            new SecureHeadersMiddleware(SecureHeadersConfig::fromArray(['csp' => "default-src 'self'"])),
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
    // Several global instances, escaping and disabled exclusions
    // =====================================================================

    public function testTwoGlobalCsrfInstancesOnlyGetTheRebuildAdvice(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['csrf' => ['enabled' => true]]],
            'security.csrf',
            new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()),
            new CsrfMiddleware(new WiringTokenManager(), new CsrfConfig(excludedPathPatterns: ['#^/api/#'])),
        );

        self::assertSame(
            '1 of the 2 global ' . CsrfMiddleware::class . ' instances does not enforce it as declared, '
            . 'and every global instance must carry the declared value: fix or remove it'
            . "\n    - instance 2 of 2 excludes \"#^/api/#\", which security.csrf.exceptions does not list; "
            . 'build the middleware with CsrfConfig::fromSecurityConfig()',
            $instruction,
        );
    }

    public function testTwoGlobalAllowedHostsInstancesOnlyGetTheRebuildAdvice(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['allowed_hosts' => ['app.test']]],
            'security.allowedHosts',
            new AllowedHostsMiddleware(['app.test']),
            new AllowedHostsMiddleware(['app.test', 'evil.test']),
        );

        self::assertSame(
            '1 of the 2 global ' . AllowedHostsMiddleware::class . ' instances does not enforce it as declared, '
            . 'and every global instance must carry the declared value: fix or remove it'
            . "\n    - instance 2 of 2 allows \"evil.test\", which security.allowedHosts does not list; "
            . "build the middleware from the configuration's security->allowedHosts",
            $instruction,
        );
    }

    public function testTwoGlobalInstancesThatBothDifferSayEveryOneMustCarryTheDeclaredValue(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['allowed_hosts' => ['app.test']]],
            'security.allowedHosts',
            new AllowedHostsMiddleware(['evil.test']),
            new AllowedHostsMiddleware(['app.test', 'evil.test']),
        );

        self::assertStringStartsWith(
            'none of the 2 global ' . AllowedHostsMiddleware::class . ' instances enforces it as declared, '
            . 'and every global instance must carry the declared value: fix each one',
            $instruction,
        );
        self::assertStringNotContainsString('declare it in', $instruction);
        self::assertStringNotContainsString('make security.allowedHosts match', $instruction);
    }

    public function testSeveralGlobalInstancesDifferingAreCountedInThePlural(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['max_body_size' => 2_097_152]],
            'security.maxBodySize',
            new MaxBodySizeMiddleware(2_097_152),
            new MaxBodySizeMiddleware(0),
            new MaxBodySizeMiddleware(4_194_304),
        );

        self::assertStringStartsWith(
            '2 of the 3 global ' . MaxBodySizeMiddleware::class . ' instances do not enforce it as declared, '
            . 'and every global instance must carry the declared value: fix or remove them' . "\n",
            $instruction,
        );
    }

    public function testADisabledCsrfMiddlewareExcludingUndeclaredPathsSaysToDeclareThemFirst(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['csrf' => ['enabled' => true]]],
            'security.csrf',
            new CsrfMiddleware(
                new WiringTokenManager(),
                new CsrfConfig(enabled: false, excludedPathPatterns: ['#^/webhooks/#', '#^/webhooks/#']),
            ),
        );

        self::assertSame(
            'the global ' . CsrfMiddleware::class . ' is disabled and excludes "#^/webhooks/#"; '
            . 'declare it in security.csrf.exceptions, then build the middleware with '
            . 'CsrfConfig::fromSecurityConfig()',
            $instruction,
        );
    }

    public function testADisabledCsrfMiddlewareExcludingSeveralUndeclaredPathsSaysToDeclareThem(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['csrf' => ['enabled' => true]]],
            'security.csrf',
            new CsrfMiddleware(
                new WiringTokenManager(),
                new CsrfConfig(enabled: false, excludedPathPatterns: ['#^/webhooks/#', '#^/z/#']),
            ),
        );

        self::assertSame(
            'the global ' . CsrfMiddleware::class . ' is disabled and excludes "#^/webhooks/#", "#^/z/#"; '
            . 'declare them in security.csrf.exceptions, then build the middleware with '
            . 'CsrfConfig::fromSecurityConfig()',
            $instruction,
        );
    }

    public function testADisabledCsrfInstanceAmongSeveralDoesNotAskToDeclareItsExclusions(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['csrf' => ['enabled' => true]]],
            'security.csrf',
            new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()),
            new CsrfMiddleware(
                new WiringTokenManager(),
                new CsrfConfig(enabled: false, excludedPathPatterns: ['#^/z/#']),
            ),
        );

        self::assertSame(
            '1 of the 2 global ' . CsrfMiddleware::class . ' instances does not enforce it as declared, '
            . 'and every global instance must carry the declared value: fix or remove it'
            . "\n    - instance 2 of 2 is disabled; build the middleware with CsrfConfig::fromSecurityConfig()",
            $instruction,
        );
    }

    public function testRebuildingTheDisabledInstanceAmongSeveralBootsAndKeepsTheExcludedPathChecked(): void
    {
        $protected = new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults());
        $application = ApplicationBuilder::create()
            ->withConfigurationArray(['security' => ['csrf' => ['enabled' => true]]])
            ->withRouter(new Router())
            ->withMiddleware($protected)
            ->withMiddleware(new CsrfMiddleware(new WiringTokenManager(), CsrfConfig::defaults()))
            ->build();

        self::assertInstanceOf(Application::class, $application);
        $response = $protected->process(
            Request::fromArray('POST', '/z/'),
            static fn (): Response => Response::text('HANDLED'),
        );
        self::assertSame(403, $response->status);
    }

    public function testADisabledCsrfMiddlewareWhoseExclusionsAreDeclaredOnlySaysToRebuildIt(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['csrf' => ['enabled' => true, 'exceptions' => ['#^/webhooks/#']]]],
            'security.csrf',
            new CsrfMiddleware(
                new WiringTokenManager(),
                new CsrfConfig(enabled: false, excludedPathPatterns: ['#^/webhooks/#']),
            ),
        );

        self::assertSame(
            'the global ' . CsrfMiddleware::class . ' is disabled; '
            . 'build the middleware with CsrfConfig::fromSecurityConfig()',
            $instruction,
        );
    }

    public function testRepeatedExtraValuesAreNamedOnce(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['csrf' => ['exceptions' => []]]],
            'security.csrf',
            new CsrfMiddleware(
                new WiringTokenManager(),
                new CsrfConfig(excludedPathPatterns: ['#^/api/#', '#^/api/#']),
            ),
        );

        self::assertStringContainsString(' excludes "#^/api/#", which', $instruction);
        self::assertStringNotContainsString('"#^/api/#", "#^/api/#"', $instruction);
    }

    public function testDeleteAndC1ControlCharactersAreEscapedInTheMessage(): void
    {
        $instruction = $this->instructionFor(
            ['security' => ['csrf' => ['exceptions' => []]]],
            'security.csrf',
            new CsrfMiddleware(
                new WiringTokenManager(),
                new CsrfConfig(excludedPathPatterns: ["#^/a\x7f\u{85}\u{9f}/#"]),
            ),
        );

        self::assertStringContainsString(' excludes "#^/a\u007f\u0085\u009f/#", which', $instruction);
        self::assertSame(1, preg_match('/^[^\x00-\x1f\x7f]*$/u', str_replace("\n", '', $instruction)));
        self::assertStringNotContainsString("\x7f", $instruction);
        self::assertStringNotContainsString("\u{85}", $instruction);
    }

    public function testAnEscapedPatternPastedBackIntoYamlLoadsAsTheSameString(): void
    {
        $pattern = "#^/a\x7f\u{85}/#";
        $instruction = $this->instructionFor(
            ['security' => ['csrf' => ['exceptions' => []]]],
            'security.csrf',
            new CsrfMiddleware(new WiringTokenManager(), new CsrfConfig(excludedPathPatterns: [$pattern])),
        );
        self::assertSame(1, preg_match('/excludes ("[^"]*"), which/', $instruction, $matches));

        $file = tempnam(sys_get_temp_dir(), 'zephyrus-yaml-');
        self::assertIsString($file);
        try {
            file_put_contents($file, "security:\n  csrf:\n    exceptions:\n      - " . $matches[1] . "\n");
            $configuration = Configuration::fromYamlFile($file);
        } finally {
            unlink($file);
        }

        self::assertSame([$pattern], $configuration->security->csrfExceptions);
    }

    /**
     * Boots with the given global middlewares and returns what build() says about one setting.
     *
     * @param array<string, mixed> $configuration
     */
    private function instructionFor(array $configuration, string $setting, MiddlewareInterface ...$middlewares): string
    {
        $builder = ApplicationBuilder::create()->withConfigurationArray($configuration)->withRouter(new Router());
        foreach ($middlewares as $middleware) {
            $builder = $builder->withMiddleware($middleware);
        }

        try {
            $builder->build();
        } catch (ConfigurationException $exception) {
            $prefix = '  - ' . $setting . ' is not enforced';
            foreach (explode("\n  - ", "\n" . $exception->getMessage()) as $block) {
                $block = '  - ' . $block;
                if (str_starts_with($block, $prefix)) {
                    $block = preg_replace('/\n\nApply the fix.*$/s', '', $block);
                    self::assertIsString($block);

                    return substr($block, strpos($block, ': ', strlen($prefix)) + 2);
                }
            }
            self::fail($setting . ' was not reported: ' . $exception->getMessage());
        }

        self::fail('build() accepted the configuration.');
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
