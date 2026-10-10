<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Core\Config\Configuration;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Localization\Translator;
use Zephyrus\Rendering\Asset;
use Zephyrus\Routing\RouteUrlGenerator;
use Zephyrus\Session\SessionManager;

/**
 * Process-wide registry of framework services, for the global helper functions
 * (env(), config(), session(), localize(), format(), asset(), route(), nonce()).
 *
 * Bootstrap:
 *
 *   App::setConfiguration($config);
 *   App::setSession($session);
 *   App::setFormatter(new Formatter('en_US'));
 *   App::setAsset(new Asset('/public'));
 *
 * The get*() methods return null when the service is not set. The state assumes
 * one request per process (php-fpm, mod_php): persistent workers (RoadRunner,
 * Swoole, FrankenPHP worker mode) are not supported.
 */
final class App
{
    private static ?Configuration $configuration = null;
    private static ?SessionManager $session = null;
    private static ?Formatter $formatter = null;
    private static ?Asset $asset = null;
    private static ?RouteUrlGenerator $urlGenerator = null;
    private static ?Translator $translator = null;
    private static ?string $nonce = null;

    /**
     * Prevent instantiation.
     */
    private function __construct()
    {
    }

    public static function setConfiguration(?Configuration $configuration): void
    {
        self::$configuration = $configuration;
    }

    public static function getConfiguration(): ?Configuration
    {
        return self::$configuration;
    }

    public static function setSession(SessionManager $session): void
    {
        self::$session = $session;
    }

    public static function getSession(): ?SessionManager
    {
        return self::$session;
    }

    public static function setFormatter(Formatter $formatter): void
    {
        self::$formatter = $formatter;
    }

    public static function getFormatter(): ?Formatter
    {
        return self::$formatter;
    }

    public static function setAsset(Asset $asset): void
    {
        self::$asset = $asset;
    }

    public static function getAsset(): ?Asset
    {
        return self::$asset;
    }

    /**
     * Installs the generator used by route(); null clears it.
     */
    public static function setUrlGenerator(?RouteUrlGenerator $urlGenerator): void
    {
        self::$urlGenerator = $urlGenerator;
    }

    public static function getUrlGenerator(): ?RouteUrlGenerator
    {
        return self::$urlGenerator;
    }

    public static function setTranslator(Translator $translator): void
    {
        self::$translator = $translator;
    }

    public static function getTranslator(): ?Translator
    {
        return self::$translator;
    }

    /**
     * Returns the CSP nonce, generated on first call and reused until resetNonce().
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    /**
     * Forgets the CSP nonce, so the next nonce() call generates a new one.
     */
    public static function resetNonce(): void
    {
        self::$nonce = null;
    }

    /**
     * Clears all registered services. Intended for test tearDown().
     */
    public static function reset(): void
    {
        self::$configuration = null;
        self::$session = null;
        self::$formatter = null;
        self::$asset = null;
        self::$urlGenerator = null;
        self::$translator = null;
        self::$nonce = null;
    }
}
