<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Container\ContainerInterface;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\LocalizationConfig;
use Zephyrus\Core\Config\SecurityConfig;
use Zephyrus\Event\EventDispatcher;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Http\MiddlewareInterface;
use Zephyrus\Localization\FallbackLocaleLoader;
use Zephyrus\Localization\JsonLocaleLoader;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Localization\Translator;
use Zephyrus\Routing\Router;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\ForceHttpsMiddleware;
use Zephyrus\Security\MaxBodySizeMiddleware;

final class ApplicationBuilder
{
    /**
     * The line written to the error log when debug is forced off.
     *
     * It names the two settings, the consequence and the escape hatch, and it
     * carries no value of any kind, because it is written on a production tier
     * where the log is itself a place secrets must not reach.
     */
    public const string PRODUCTION_DEBUG_REFUSED =
        'Zephyrus: application.debug is true while application.environment is production-like. '
        . 'Debug output has been FORCED OFF for this boot: the debugger renders live configuration, '
        . 'stack-trace argument values and the process environment to whichever client triggers an error. '
        . 'If this is deliberate, call ApplicationBuilder::withProductionDebugAcknowledged(); '
        . 'even then, only clients named by withDebugClientAllowlist() (or loopback) can see it.';

    private KernelBuilder $kernelBuilder;

    private ?LocaleLoaderInterface $localeLoader = null;

    private string $defaultLocale = 'en';

    /** @var string[] */
    private array $supportedLocales = [];

    private ?Configuration $configuration = null;

    /** @var list<string> */
    private array $acknowledgedSecurityKeys = [];

    private bool $securityWiringCheckEnabled = true;

    private bool $productionDebugAcknowledged = false;

    /** @var string|string[]|null */
    private string|array|null $debugAllowedClients = null;

    public function __construct(?KernelBuilder $kernelBuilder = null)
    {
        $this->kernelBuilder = $kernelBuilder ?? KernelBuilder::create();
    }

    public static function create(): self
    {
        return new self();
    }

    public static function fromConfiguration(Configuration $configuration): self
    {
        return self::create()->withConfiguration($configuration);
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function fromConfigurationArray(array $configuration): self
    {
        return self::create()->withConfigurationArray($configuration);
    }

    public static function fromConfigurationFile(string $path): self
    {
        return self::create()->withConfigurationFile($path);
    }

    public static function buildFromConfiguration(Configuration $configuration): Application
    {
        return self::fromConfiguration($configuration)->build();
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public static function buildFromConfigurationArray(array $configuration): Application
    {
        return self::fromConfigurationArray($configuration)->build();
    }

    public static function buildFromConfigurationFile(string $path): Application
    {
        return self::fromConfigurationFile($path)->build();
    }

    /**
     * @param string[] $paths
     */
    public static function fromConfigurationFiles(array $paths): self
    {
        return self::fromConfiguration(Configuration::fromFiles($paths));
    }

    /**
     * @param string[] $paths
     */
    public static function fromOptionalConfigurationFiles(array $paths): self
    {
        return self::fromConfiguration(Configuration::fromOptionalFiles($paths));
    }

    /**
     * @param string[] $paths
     */
    public static function buildFromConfigurationFiles(array $paths): Application
    {
        return self::fromConfigurationFiles($paths)->build();
    }

    /**
     * @param string[] $paths
     */
    public static function buildFromOptionalConfigurationFiles(array $paths): Application
    {
        return self::fromOptionalConfigurationFiles($paths)->build();
    }

    public function withRouter(Router $router): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withRouter($router);

        return $clone;
    }

    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withMiddleware($middleware);

        return $clone;
    }

    public function registerMiddleware(string $name, MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->registerMiddleware($name, $middleware);

        return $clone;
    }

    public function withEventDispatcher(EventDispatcher $dispatcher): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withEventDispatcher($dispatcher);

        return $clone;
    }

    /**
     * @param callable(class-string): object $factory
     */
    public function withControllerFactory(callable $factory): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withControllerFactory($factory);

        return $clone;
    }

    public function withContainer(ContainerInterface $container): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withContainer($container);

        return $clone;
    }

    /**
     * Register a custom exception handler for a specific exception class.
     *
     * When the kernel catches an exception, registered handlers are checked
     * before the built-in mappings (404, 405, 422, 500). The most-specific
     * matching class wins via instanceof.
     *
     * @param class-string<\Throwable> $exceptionClass
     * @param callable(\Throwable, ?\Zephyrus\Http\Request): \Zephyrus\Http\Response $handler
     */
    public function withExceptionHandler(string $exceptionClass, callable $handler): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withExceptionHandler($exceptionClass, $handler);

        return $clone;
    }

    public function withLocaleLoader(LocaleLoaderInterface $loader, string $defaultLocale = 'en'): self
    {
        $clone = clone $this;
        $clone->localeLoader = $loader;
        $clone->defaultLocale = $defaultLocale;

        return $clone;
    }

    public function withJsonLocales(string $basePath, string $defaultLocale = 'en'): self
    {
        return $this->withLocaleLoader(new JsonLocaleLoader($basePath), $defaultLocale);
    }

    /**
     * Configure JSON translation catalogs from multiple directories merged in
     * last-wins order. Later paths override earlier paths for duplicate keys.
     *
     * @param string[] $basePaths
     */
    public function withJsonLocaleLayers(array $basePaths, string $defaultLocale = 'en'): self
    {
        $loaders = array_values(array_map(
            static fn (string $basePath): JsonLocaleLoader => new JsonLocaleLoader($basePath),
            $basePaths,
        ));

        if ($loaders === []) {
            return $this->withLocaleLoader(new class implements LocaleLoaderInterface {
                public function load(string $locale): array
                {
                    return [];
                }
            }, $defaultLocale);
        }

        return $this->withFallbackLoaders($loaders, $defaultLocale);
    }

    /**
     * Configure translation from multiple locale loaders merged in last-wins
     * order. Later loaders override earlier loaders for the same key.
     *
     * @param LocaleLoaderInterface[] $loaders
     */
    public function withFallbackLoaders(array $loaders, string $defaultLocale = 'en'): self
    {
        return $this->withLocaleLoader(new FallbackLocaleLoader($loaders), $defaultLocale);
    }

    /**
     * Restrict locale resolution (in transFromRequest) to this explicit list.
     * If not set (empty), every normalized Accept-Language candidate is accepted.
     *
     * @param string[] $locales
     */
    public function withSupportedLocales(array $locales): self
    {
        $clone = clone $this;
        $clone->supportedLocales = $locales;

        return $clone;
    }

    /**
     * Apply a LocalizationConfig to the builder.
     *
     * @param LocalizationConfig $config   The localization configuration section.
     * @param string|null        $basePath Optional project root used to resolve
     *                                     a relative locale_path. When provided,
     *                                     a non-absolute locale_path is prefixed
     *                                     with this directory.
     */
    public function withLocalizationConfig(LocalizationConfig $config, ?string $basePath = null): self
    {
        $builder = $this;

        if ($config->localePath !== null) {
            $localePath = $config->localePath;
            if ($basePath !== null && !str_starts_with($localePath, '/')) {
                $localePath = rtrim($basePath, '/\\') . '/' . $localePath;
            }
            $builder = $builder->withJsonLocales(
                basePath: $localePath,
                defaultLocale: $config->locale,
            );
        } else {
            $builder = $builder->withLocaleLoader(new class implements LocaleLoaderInterface {
                public function load(string $locale): array
                {
                    return [];
                }
            }, $config->locale);
        }

        return $builder->withSupportedLocales($config->supportedLocales);
    }

    /**
     * Apply a full typed Configuration tree to application bootstrap.
     *
     * Wiring scope:
     * - localization section (locale loader, supported locales)
     * - application.debug → Tracy Debugger initialization (in build())
     * - localization.timezone → date_default_timezone_set() (in build())
     *
     * NOTHING in the `security:` section is wired from here, on purpose; see
     * assertSecurityConfigurationIsWired(), which refuses to boot rather than
     * letting a declared protection sit inert.
     *
     * @param Configuration $configuration The full application configuration.
     * @param string|null   $basePath      Optional project root for resolving
     *                                     relative paths (e.g. locale_path).
     */
    public function withConfiguration(Configuration $configuration, ?string $basePath = null): self
    {
        $clone = $this->withLocalizationConfig($configuration->localization, $basePath);
        $clone->configuration = $configuration;

        return $clone;
    }

    /**
     * Parse and apply a root configuration array in one call.
     *
     * @param array<string, mixed> $configuration
     */
    public function withConfigurationArray(array $configuration): self
    {
        return $this->withConfiguration(Configuration::fromArray($configuration));
    }

    /**
     * Load and apply a root configuration file.
     */
    public function withConfigurationFile(string $path): self
    {
        return $this->withConfiguration(Configuration::fromFile($path));
    }

    /**
     * Load, merge, and apply multiple configuration files.
     *
     * @param string[] $paths
     */
    public function withConfigurationFiles(array $paths): self
    {
        return $this->withConfiguration(Configuration::fromFiles($paths));
    }

    /**
     * Load, merge, and apply multiple configuration files while ignoring missing files.
     *
     * @param string[] $paths
     */
    public function withOptionalConfigurationFiles(array $paths): self
    {
        return $this->withConfiguration(Configuration::fromOptionalFiles($paths));
    }

    /**
     * Declare that a security setting is enforced somewhere build() cannot see.
     *
     * The check in build() knows about the middlewares registered on this
     * builder and nothing else, so it is wrong in exactly one direction: an
     * application enforcing HTTPS at its load balancer, capping the body size
     * in nginx, or wrapping a framework middleware in a decorator instead of
     * extending it, is doing the right thing and would still be refused. This
     * is how such an application says so, once, in the bootstrap, where the
     * next reader can see the claim.
     *
     * Names may be given short ("maxBodySize") or qualified
     * ("security.maxBodySize"); both forms mean the same setting.
     *
     * @param string[] $keys
     */
    public function withAcknowledgedSecurityKeys(array $keys): self
    {
        $clone = clone $this;
        $clone->acknowledgedSecurityKeys = array_values(array_unique(array_merge(
            $this->acknowledgedSecurityKeys,
            array_map(
                static fn (string $key): string => str_starts_with($key, 'security.')
                    ? substr($key, strlen('security.'))
                    : $key,
                $keys,
            ),
        )));

        return $clone;
    }

    /**
     * Permit application.debug to stay ON in a production-like environment.
     *
     * Without this, build() FORCES debug off whenever
     * `application.environment` is production or staging and
     * `application.debug` is true, and writes one loud line to the error log
     * saying so. See build() for why forcing off beat refusing to boot.
     *
     * This is the escape hatch for the operator who genuinely means it, and it
     * is deliberately a line of BOOTSTRAP CODE rather than a config key: a
     * config key is exactly what gets flipped on a live tier at 3am and
     * forgotten, which is the situation this guard exists for.
     *
     * Acknowledging does NOT broadcast the debugger. DebugIntegration still
     * runs Tracy in Detect mode, so a remote client sees nothing unless it is
     * on the allowlist passed to withDebugClientAllowlist().
     */
    public function withProductionDebugAcknowledged(bool $acknowledged = true): self
    {
        $clone = clone $this;
        $clone->productionDebugAcknowledged = $acknowledged;

        return $clone;
    }

    /**
     * Name the clients allowed to receive the debugger's rendered output.
     *
     * Entries are addresses, or `secret@address` pairs matched against the
     * `tracy-debug` cookie. Loopback is always permitted by Tracy itself when
     * the request did not arrive through a proxy, so a local developer never
     * needs this. It exists for debugging a deployed tier from one known
     * address.
     *
     * @param string|string[]|null $clients
     */
    public function withDebugClientAllowlist(string|array|null $clients): self
    {
        $clone = clone $this;
        $clone->debugAllowedClients = $clients;

        return $clone;
    }

    /**
     * The debug flag build() will actually act on, with no side effect.
     *
     * Exposed so the production guard is assertable without booting Tracy in
     * the test process: enabling Tracy is global, irreversible for the rest of
     * the process, and installs error handlers, so a test that had to observe
     * it through Debugger::isEnabled() could only ever run in isolation.
     */
    public function isDebugEnabledForBoot(): bool
    {
        if ($this->configuration === null) {
            return false;
        }

        $application = $this->configuration->application;

        if (!$application->debug) {
            return false;
        }

        if (!$application->environment->isProductionLike()) {
            return true;
        }

        return $this->productionDebugAcknowledged;
    }

    /**
     * Turn the wiring check off wholesale.
     *
     * Prefer withAcknowledgedSecurityKeys(): it keeps the check live for every
     * OTHER setting, including ones added to the framework later. This exists
     * for a bootstrap that legitimately cannot enumerate them, and it is the
     * blunt instrument.
     */
    public function withoutSecurityWiringCheck(): self
    {
        $clone = clone $this;
        $clone->securityWiringCheckEnabled = false;

        return $clone;
    }

    /**
     * Refuse to boot when a declared security setting enforces nothing.
     *
     * ## Why this throws instead of wiring the middleware itself
     *
     * The whole `security:` block was inert. withConfiguration() wires
     * localization, application.debug and the timezone; KernelBuilder::build()
     * never reads Configuration at all. So forceHttps, csrfEnabled,
     * allowedHosts and maxBodySize were parsed, type-validated, range-checked,
     * unit-tested, echoed by Configuration::toArray() and connected to nothing:
     * a configuration declaring all four protections ON served a plain-HTTP,
     * forged-Host, tokenless 5 MiB POST.
     *
     * The obvious repair, wiring them here, is the dangerous one. Applications
     * already register their own CsrfMiddleware and their own security-header
     * middleware from their own bootstrap; a framework that started registering
     * them too would give those applications TWO CSRF middlewares on every
     * request. That is a worse outage than the silence, and it is the exact
     * class of breakage this whole review exists to stop. So the framework
     * still wires nothing, and instead refuses to start while naming the gap.
     *
     * ## What is checked, and what deliberately is not
     *
     * Only a setting the source file actually DECLARED (see
     * SecurityConfig::isDeclared()) and that asks for a protection: forceHttps
     * true, a non-empty allowedHosts, CSRF enabled, a finite maxBodySize.
     * Disabling something inert is harmless and is never reported.
     *
     * trustedProxies, trustedHeaders and encryptionKey are NOT checked. They
     * are consumed outside the builder entirely, by Request::fromGlobals() and
     * by whatever constructs Cryptography, so the builder cannot observe
     * whether an application passed them, and guessing would refuse correctly
     * wired applications at boot. That is a real, stated limit of this check
     * rather than an oversight.
     *
     * @throws ConfigurationException
     */
    private function assertSecurityConfigurationIsWired(SecurityConfig $security): void
    {
        if (!$this->securityWiringCheckEnabled) {
            return;
        }

        $unwired = [];

        if (
            $security->isDeclared('forceHttps')
            && $security->forceHttps
            && !$this->kernelBuilder->hasMiddleware(ForceHttpsMiddleware::class)
        ) {
            $unwired['forceHttps'] = ForceHttpsMiddleware::class;
        }

        if (
            $security->isDeclared('allowedHosts')
            && $security->allowedHosts !== []
            && !$this->kernelBuilder->hasMiddleware(AllowedHostsMiddleware::class)
        ) {
            $unwired['allowedHosts'] = AllowedHostsMiddleware::class;
        }

        if (
            ($security->isDeclared('csrfEnabled')
                || $security->isDeclared('csrfExceptions')
                || $security->isDeclared('csrfAutoHtml'))
            && $security->csrfEnabled
            && !$this->kernelBuilder->hasMiddleware(CsrfMiddleware::class)
        ) {
            $unwired['csrf'] = CsrfMiddleware::class;
        }

        if (
            $security->isDeclared('maxBodySize')
            && $security->maxBodySize > 0
            && !$this->kernelBuilder->hasMiddleware(MaxBodySizeMiddleware::class)
        ) {
            $unwired['maxBodySize'] = MaxBodySizeMiddleware::class;
        }

        foreach ($this->acknowledgedSecurityKeys as $acknowledged) {
            unset($unwired[$acknowledged]);
        }

        if ($unwired !== []) {
            $qualified = [];
            foreach ($unwired as $setting => $middleware) {
                $qualified['security.' . $setting] = $middleware;
            }

            throw ConfigurationException::unwiredSecurity($qualified);
        }
    }

    /**
     * Assemble the application.
     *
     * ## Why debug on production is FORCED OFF rather than refused
     *
     * `application.environment: production` plus `application.debug: true` used
     * to boot silently and hand the debugger to every client. Two repairs were
     * available and they are not equivalent.
     *
     * Refusing to boot is the stricter one, and it is the wrong one HERE. The
     * operator who sets this combination is, in practice, mid-incident: they
     * have a production tier misbehaving and they are trying to see why. A
     * refusal converts their diagnostic attempt into an outage of the very
     * service they were diagnosing, and it does it at the worst possible
     * moment. Worse, it teaches the escape hatch as a reflex: once
     * "acknowledge it or the site is down" is the rule, the acknowledgement
     * goes into the bootstrap permanently and the guard is gone for good.
     *
     * Forcing debug off keeps the tier serving, removes the leak, and costs
     * the operator only the thing that was unsafe. The failure mode it
     * introduces is silence, which is the failure mode this whole review
     * exists to eliminate, so it is bought back with a loud, unconditional
     * error_log line on every boot (PRODUCTION_DEBUG_REFUSED) naming both
     * settings and the escape hatch.
     *
     * The escape hatch is withProductionDebugAcknowledged(). It is a line of
     * code in the bootstrap and not a config key, on purpose: config is what
     * gets edited on a live tier and forgotten.
     *
     * And it is not the only line of defence. Even when acknowledged,
     * DebugIntegration runs Tracy in Detect mode, so the rendered debugger
     * still only reaches loopback, the `tracy-debug` cookie holder, or an
     * address named by withDebugClientAllowlist().
     */
    public function build(): Application
    {
        // Refuse a security configuration nothing enforces BEFORE any side
        // effect (debugger, timezone, App:: statics) has happened.
        if ($this->configuration !== null) {
            $this->assertSecurityConfigurationIsWired($this->configuration->security);
        }

        // Wire debug mode (Tracy) and timezone before anything else
        if ($this->configuration !== null) {
            $debug = $this->isDebugEnabledForBoot();

            if (!$debug && $this->configuration->application->debug) {
                error_log(self::PRODUCTION_DEBUG_REFUSED);
            }

            DebugIntegration::initialize(
                debug: $debug,
                allowedClients: $this->debugAllowedClients,
            );

            $timezone = $this->configuration->localization->timezone;
            if ($timezone !== '') {
                date_default_timezone_set($timezone);
            }
        }

        $loader = $this->localeLoader ?? new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return [];
            }
        };

        $translator = new Translator($loader, $this->defaultLocale);

        $localizationConfig = $this->configuration?->localization;
        $currency = $localizationConfig?->currency;
        $formatter = new Formatter(
            locale: $this->defaultLocale,
            defaultCurrency: ($currency !== null && $currency !== '') ? $currency : null,
            defaultDatePattern: $localizationConfig?->dateFormat ?? 'medium',
            defaultTimePattern: $localizationConfig?->timeFormat ?? 'short',
            defaultDatetimePattern: $localizationConfig?->datetimeFormat ?? 'medium',
        );

        if ($this->configuration !== null) {
            App::setConfiguration($this->configuration);
        }
        App::setTranslator($translator);
        App::setFormatter($formatter);

        return new Application(
            kernel:           $this->kernelBuilder->build(),
            translator:       $translator,
            defaultLocale:    $this->defaultLocale,
            supportedLocales: $this->supportedLocales,
        );
    }
}
