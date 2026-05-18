<?php

declare(strict_types=1);

namespace Zephyrus\Core;

use Zephyrus\Core\Config\Configuration;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Http\Request;
use Zephyrus\Inertia\InertiaRenderer;
use Zephyrus\Localization\Translator;
use Zephyrus\Rendering\Asset;
use Zephyrus\Session\SessionManager;

/**
 * Lightweight application registry for global helper function access.
 *
 * Holds references to framework services so that global helper functions
 * (env(), config(), session(), localize(), format(), asset(), nonce()) can
 * access them without dependency injection.
 *
 * Usage in bootstrap:
 *
 *   App::setConfiguration($config);
 *   App::setSession($session);
 *   App::setFormatter(new Formatter('en_US'));
 *   App::setAsset(new Asset('/public'));
 *
 * The set*() methods can be called at any time (e.g. during ApplicationBuilder
 * wiring) and are idempotent. The get*() methods return null when the service
 * has not been set, so helpers can degrade gracefully.
 */
final class App
{
    private static ?Configuration $configuration = null;
    private static ?SessionManager $session = null;
    private static ?Formatter $formatter = null;
    private static ?Asset $asset = null;
    private static ?Translator $translator = null;
    private static ?Request $request = null;
    private static ?InertiaRenderer $inertia = null;
    private static ?string $nonce = null;

    /**
     * Prevent instantiation.
     */
    private function __construct() {}

    // ─── Configuration ────────────────────────────────────────────────

    public static function setConfiguration(Configuration $configuration): void
    {
        self::$configuration = $configuration;
    }

    public static function getConfiguration(): ?Configuration
    {
        return self::$configuration;
    }

    // ─── Session ──────────────────────────────────────────────────────

    public static function setSession(SessionManager $session): void
    {
        self::$session = $session;
    }

    public static function getSession(): ?SessionManager
    {
        return self::$session;
    }

    // ─── Formatter ────────────────────────────────────────────────────

    public static function setFormatter(Formatter $formatter): void
    {
        self::$formatter = $formatter;
    }

    public static function getFormatter(): ?Formatter
    {
        return self::$formatter;
    }

    // ─── Asset ────────────────────────────────────────────────────────

    public static function setAsset(Asset $asset): void
    {
        self::$asset = $asset;
    }

    public static function getAsset(): ?Asset
    {
        return self::$asset;
    }

    // ─── Translator ───────────────────────────────────────────────────

    public static function setTranslator(Translator $translator): void
    {
        self::$translator = $translator;
    }

    public static function getTranslator(): ?Translator
    {
        return self::$translator;
    }

    // ─── Current Request ──────────────────────────────────────────────

    public static function setRequest(?Request $request): void
    {
        self::$request = $request;
    }

    public static function getRequest(): ?Request
    {
        return self::$request;
    }

    // ─── Inertia ──────────────────────────────────────────────────────

    public static function setInertia(InertiaRenderer $inertia): void
    {
        self::$inertia = $inertia;
    }

    public static function getInertia(): ?InertiaRenderer
    {
        return self::$inertia;
    }

    // ─── CSP Nonce ────────────────────────────────────────────────────

    /**
     * Get or generate the CSP nonce for the current request.
     *
     * The nonce is generated once per request and reused for consistency.
     */
    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = base64_encode(random_bytes(16));
        }
        return self::$nonce;
    }

    /**
     * Reset the nonce (useful between requests in tests).
     */
    public static function resetNonce(): void
    {
        self::$nonce = null;
    }

    // ─── Reset (testing) ──────────────────────────────────────────────

    /**
     * Clear all registered services. Useful in test tearDown().
     */
    public static function reset(): void
    {
        self::$configuration = null;
        self::$session = null;
        self::$formatter = null;
        self::$asset = null;
        self::$translator = null;
        self::$request = null;
        self::$inertia = null;
        self::$nonce = null;
    }
}
