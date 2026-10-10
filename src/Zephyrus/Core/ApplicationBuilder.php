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
use Zephyrus\Rendering\RenderEngine;
use Zephyrus\Routing\Router;
use Zephyrus\Routing\RouteUrlGenerator;
use Zephyrus\Security\AllowedHostsMiddleware;
use Zephyrus\Security\CsrfMiddleware;
use Zephyrus\Security\ForceHttpsMiddleware;
use Zephyrus\Security\MaxBodySizeMiddleware;
use Zephyrus\Security\SecureHeadersConfig;
use Zephyrus\Security\SecureHeadersMiddleware;

final class ApplicationBuilder
{
    /**
     * Error log line written when debug is forced off. It carries no configuration value: production logs must not hold secrets.
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
     * @throws ConfigurationException when a section value is invalid.
     */
    public static function fromConfigurationArray(array $configuration): self
    {
        return self::create()->withConfigurationArray($configuration);
    }

    /**
     * @throws ConfigurationException when the file is missing, unreadable, invalid or holds an invalid value.
     */
    public static function fromConfigurationFile(string $path): self
    {
        return self::create()->withConfigurationFile($path);
    }

    /**
     * @throws ConfigurationException when a declared security setting is not wired.
     */
    public static function buildFromConfiguration(Configuration $configuration): Application
    {
        return self::fromConfiguration($configuration)->build();
    }

    /**
     * @param array<string, mixed> $configuration
     * @throws ConfigurationException when a section value is invalid or a declared security setting is not wired.
     */
    public static function buildFromConfigurationArray(array $configuration): Application
    {
        return self::fromConfigurationArray($configuration)->build();
    }

    /**
     * @throws ConfigurationException when the file is missing, unreadable, invalid or holds an invalid value, or a
     *                                declared security setting is not wired.
     */
    public static function buildFromConfigurationFile(string $path): Application
    {
        return self::fromConfigurationFile($path)->build();
    }

    /**
     * @param string[] $paths
     * @throws ConfigurationException when a path is invalid or a file is missing, unreadable, invalid or holds an
     *                                invalid value.
     */
    public static function fromConfigurationFiles(array $paths): self
    {
        return self::fromConfiguration(Configuration::fromFiles($paths));
    }

    /**
     * @param string[] $paths
     * @throws ConfigurationException when a path is invalid or an existing file is unreadable, invalid or holds an
     *                                invalid value.
     */
    public static function fromOptionalConfigurationFiles(array $paths): self
    {
        return self::fromConfiguration(Configuration::fromOptionalFiles($paths));
    }

    /**
     * @param string[] $paths
     * @throws ConfigurationException when a path is invalid or a file is missing, unreadable, invalid or holds an
     *                                invalid value, or a declared security setting is not wired.
     */
    public static function buildFromConfigurationFiles(array $paths): Application
    {
        return self::fromConfigurationFiles($paths)->build();
    }

    /**
     * @param string[] $paths
     * @throws ConfigurationException when a path is invalid or an existing file is unreadable, invalid or holds an
     *                                invalid value, or a declared security setting is not wired.
     */
    public static function buildFromOptionalConfigurationFiles(array $paths): Application
    {
        return self::fromOptionalConfigurationFiles($paths)->build();
    }

    /**
     * Sets the router. Without one, every request is answered with 404.
     */
    public function withRouter(Router $router): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withRouter($router);

        return $clone;
    }

    /**
     * Appends a global middleware, run in registration order. The security middlewares must be mounted here
     * for build() to count them as enforced.
     */
    public function withMiddleware(MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withMiddleware($middleware);

        return $clone;
    }

    /**
     * Binds a middleware name that routes can reference. Registering the same name again replaces the binding.
     */
    public function registerMiddleware(string $name, MiddlewareInterface $middleware): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->registerMiddleware($name, $middleware);

        return $clone;
    }

    /**
     * Sets the dispatcher that receives the kernel's RequestEvent, ExceptionEvent and ResponseEvent.
     * Without one, no events fire.
     */
    public function withEventDispatcher(EventDispatcher $dispatcher): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withEventDispatcher($dispatcher);

        return $clone;
    }

    /**
     * Sets how controller classes are instantiated.
     *
     * @param callable(class-string): object $factory
     */
    public function withControllerFactory(callable $factory): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withControllerFactory($factory);

        return $clone;
    }

    /**
     * Shares an application-built engine with every controller using RenderResponses, in any order with withControllerFactory().
     * It replaces an engine the factory set.
     */
    public function withRenderEngine(RenderEngine $engine): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withRenderEngine($engine);

        return $clone;
    }

    /**
     * Resolves controllers through the container, so auto-wiring and explicit bindings both work.
     * Shorthand for withControllerFactory(): whichever of the two is called last wins.
     */
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
     * matching class wins via instanceof. The handler receives the request
     * without the attributes set by route middlewares, see HttpKernel.
     *
     * @param class-string<\Throwable> $exceptionClass
     * @param callable(\Throwable, \Zephyrus\Http\Request): \Zephyrus\Http\Response $handler
     */
    public function withExceptionHandler(string $exceptionClass, callable $handler): self
    {
        $clone = clone $this;
        $clone->kernelBuilder = $this->kernelBuilder->withExceptionHandler($exceptionClass, $handler);

        return $clone;
    }

    /**
     * Sets the translation loader and default locale. The last loader-setting call wins, including withConfiguration().
     */
    public function withLocaleLoader(LocaleLoaderInterface $loader, string $defaultLocale = 'en'): self
    {
        $clone = clone $this;
        $clone->localeLoader = $loader;
        $clone->defaultLocale = $defaultLocale;

        return $clone;
    }

    /**
     * Loads JSON catalogs from one directory. Files are read lazily: an unreadable or invalid file
     * throws LocalizationException when its locale is loaded, not here. A missing directory or file
     * gives an empty catalog (keys are returned as is).
     */
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
     * Restricts locale resolution (transFromRequest) to this list. An empty list accepts every normalized Accept-Language candidate.
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
     * Applies a LocalizationConfig: loader, default locale and supported locales.
     *
     * @param LocalizationConfig $config
     * @param string|null        $basePath Prefixed to a relative locale_path when given.
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
     * Applies a full Configuration tree.
     *
     * Called after withJsonLocales(), it replaces the loader and the translations.
     *
     * Wired here: the localization section (loader, supported locales). Wired in build(): application.debug
     * (Tracy) and localization.timezone. Nothing in `security:` is wired, on purpose: build() refuses to boot
     * when a declared protection is not mounted, rather than letting it sit inert.
     *
     * @param Configuration $configuration The full application configuration.
     * @param string|null   $basePath      Prefixed to a relative locale_path when given.
     */
    public function withConfiguration(Configuration $configuration, ?string $basePath = null): self
    {
        $clone = $this->withLocalizationConfig($configuration->localization, $basePath);
        $clone->configuration = $configuration;

        return $clone;
    }

    /**
     * Parses and applies a root configuration array.
     *
     * @param array<string, mixed> $configuration
     * @throws ConfigurationException when a section value is invalid.
     */
    public function withConfigurationArray(array $configuration): self
    {
        return $this->withConfiguration(Configuration::fromArray($configuration));
    }

    /**
     * Loads and applies a root configuration file.
     *
     * @throws ConfigurationException when the file is missing, unreadable, invalid or holds an invalid value.
     */
    public function withConfigurationFile(string $path): self
    {
        return $this->withConfiguration(Configuration::fromFile($path));
    }

    /**
     * Loads, merges and applies configuration files.
     *
     * @param string[] $paths
     * @throws ConfigurationException when a path is invalid or a file is missing, unreadable, invalid or holds an invalid value.
     */
    public function withConfigurationFiles(array $paths): self
    {
        return $this->withConfiguration(Configuration::fromFiles($paths));
    }

    /**
     * Like withConfigurationFiles(), but missing files are skipped.
     *
     * @param string[] $paths
     * @throws ConfigurationException when a path is invalid or an existing file is unreadable, invalid or holds an invalid value.
     */
    public function withOptionalConfigurationFiles(array $paths): self
    {
        return $this->withConfiguration(Configuration::fromOptionalFiles($paths));
    }

    /**
     * Declares security settings enforced where build() cannot see them, so build() does not refuse them.
     *
     * build() only knows the middlewares mounted on this builder. A setting enforced by a load balancer, by the web
     * server or by a decorator around a framework middleware must be acknowledged here.
     *
     * Accepted names: forceHttps, allowedHosts, csrf, maxBodySize and headers, short or qualified
     * ("security.maxBodySize").
     * Any other name is ignored.
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
     * Lets application.debug stay on in a production-like environment (production or staging).
     *
     * Without it, build() forces debug off and logs PRODUCTION_DEBUG_REFUSED. This is bootstrap code on purpose,
     * not a config key: config is what gets flipped on a live tier and forgotten. Acknowledging does not broadcast
     * the debugger: remote clients still see nothing unless they are on withDebugClientAllowlist().
     */
    public function withProductionDebugAcknowledged(bool $acknowledged = true): self
    {
        $clone = clone $this;
        $clone->productionDebugAcknowledged = $acknowledged;

        return $clone;
    }

    /**
     * Name the clients allowed to receive the rendered debugger output.
     *
     * Each entry is an exact address, or `secret@address` matched against the `tracy-debug` cookie.
     * Tracy accepts no ranges. A bare gateway address admits anyone who can reach the published port.
     *
     * Behind Docker, loopback is not granted: use `secret@<gateway-ip>` and set the cookie `tracy-debug=<secret>`.
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
     * Returns the debug flag build() acts on, without enabling Tracy, which is global and irreversible per process.
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
     * Turns the security wiring check off entirely. Prefer withAcknowledgedSecurityKeys(), which keeps the check
     * live for every other setting, including ones added to the framework later.
     */
    public function withoutSecurityWiringCheck(): self
    {
        $clone = clone $this;
        $clone->securityWiringCheckEnabled = false;

        return $clone;
    }

    /**
     * Refuses to boot when a declared security setting asks for a protection that no mounted middleware enforces.
     *
     * The security section is never wired from configuration: registering the middlewares here would give
     * applications that mount their own a second CSRF or header middleware. Checked: forceHttps, a non-empty
     * allowedHosts, a finite maxBodySize and the headers section, each only when declared, and csrfEnabled when
     * any csrf key is declared; a protection counts as enforced only when its middleware is mounted with
     * withMiddleware(), and the headers only when a mounted SecureHeadersMiddleware carries security.headers.
     * Not checked: trustedProxies, trustedHeaders and encryptionKey, which are consumed outside the builder.
     *
     * @throws ConfigurationException when a declared protection is not mounted and not acknowledged.
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
            && !$this->kernelBuilder->hasGlobalMiddleware(ForceHttpsMiddleware::class)
        ) {
            $unwired['forceHttps'] = ForceHttpsMiddleware::class;
        }

        if (
            $security->isDeclared('allowedHosts')
            && $security->allowedHosts !== []
            && !$this->kernelBuilder->hasGlobalMiddleware(AllowedHostsMiddleware::class)
        ) {
            $unwired['allowedHosts'] = AllowedHostsMiddleware::class;
        }

        if (
            ($security->isDeclared('csrfEnabled')
                || $security->isDeclared('csrfExceptions')
                || $security->isDeclared('csrfAutoHtml'))
            && $security->csrfEnabled
            && !$this->kernelBuilder->hasGlobalMiddleware(CsrfMiddleware::class)
        ) {
            $unwired['csrf'] = CsrfMiddleware::class;
        }

        if (
            $security->isDeclared('maxBodySize')
            && $security->maxBodySize > 0
            && !$this->kernelBuilder->hasGlobalMiddleware(MaxBodySizeMiddleware::class)
        ) {
            $unwired['maxBodySize'] = MaxBodySizeMiddleware::class;
        }

        if (
            $security->isDeclared('headers')
            && !$this->secureHeadersMountedWith($security->headers)
        ) {
            $unwired['headers'] = SecureHeadersMiddleware::class;
        }

        foreach ($this->acknowledgedSecurityKeys as $acknowledged) {
            unset($unwired[$acknowledged]);
        }

        if ($unwired !== []) {
            $qualified = [];
            foreach ($unwired as $setting => $middleware) {
                $qualified['security.' . $setting] = $this->describeUnwiredMiddleware($middleware);
            }

            throw ConfigurationException::unwiredSecurity($qualified);
        }
    }

    private function secureHeadersMountedWith(SecureHeadersConfig $headers): bool
    {
        foreach ($this->kernelBuilder->globalMiddlewaresOf(SecureHeadersMiddleware::class) as $middleware) {
            if ($middleware->config() == $headers) {
                return true;
            }
        }

        return false;
    }

    /**
     * Names the middleware a boot error should ask for, noting when the class
     * is registered under a route name only.
     *
     * @param class-string $middleware
     */
    private function describeUnwiredMiddleware(string $middleware): string
    {
        if (
            $middleware === SecureHeadersMiddleware::class
            && $this->kernelBuilder->hasGlobalMiddleware($middleware)
        ) {
            return $middleware . ' (mounted with a configuration other than security.headers, so the declared headers'
                . ' are never sent: mount new SecureHeadersMiddleware($configuration->security->headers))';
        }

        if (!$this->kernelBuilder->hasMiddleware($middleware)) {
            return $middleware;
        }

        $hint = $middleware . ' (currently registered under a route name only, so it guards just the routes that name it: mount it with withMiddleware()';

        return $middleware === CsrfMiddleware::class
            ? $hint . ' and exempt routes through security.csrf.exceptions)'
            : $hint . ')';
    }

    /**
     * Assembles the application.
     *
     * In a production-like environment, application.debug is forced off (with a PRODUCTION_DEBUG_REFUSED log line)
     * unless withProductionDebugAcknowledged() is called. Refusing to boot instead would turn a diagnostic
     * attempt into an outage. Even when acknowledged, DebugIntegration shows the debugger only to loopback,
     * the `tracy-debug` cookie holder, or withDebugClientAllowlist() addresses.
     *
     * @throws ConfigurationException when a declared security setting is not wired, or an enforced
     *                                Content-Security-Policy middleware is shadowed by a SecureHeadersMiddleware csp.
     */
    public function build(): Application
    {
        // Runs before any side effect (debugger, timezone, App statics).
        if ($this->configuration !== null) {
            $this->assertSecurityConfigurationIsWired($this->configuration->security);
        }

        if ($this->configuration !== null) {
            $debug = $this->isDebugEnabledForBoot();

            if (!$debug && $this->configuration->application->debug) {
                error_log(self::PRODUCTION_DEBUG_REFUSED);
            }

            DebugIntegration::initialize(
                debug: $debug,
                allowedClients: $this->debugAllowedClients,
                sessionName: $this->configuration->session->name,
            );

            $timezone = $this->configuration->localization->timezone;
            if ($timezone !== '') {
                date_default_timezone_set($timezone);
            }
        } else {
            DebugIntegration::initializeWithoutConfiguration();
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
            groupingSeparator: $localizationConfig?->groupingSeparator,
        );

        if ($this->configuration !== null) {
            App::setConfiguration($this->configuration);
        }
        App::setTranslator($translator);
        App::setFormatter($formatter);

        $router = $this->kernelBuilder->router();
        App::setUrlGenerator($router === null ? null : new RouteUrlGenerator($router->routes()));

        return new Application(
            kernel:           $this->kernelBuilder->build(),
            translator:       $translator,
            defaultLocale:    $this->defaultLocale,
            supportedLocales: $this->supportedLocales,
        );
    }
}
