<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\App;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Formatting\Formatter;
use Zephyrus\Http\Request;
use Zephyrus\Inertia\InertiaRenderer;
use Zephyrus\Localization\LocaleLoaderInterface;
use Zephyrus\Localization\Translator;
use Zephyrus\Rendering\Asset;
use Zephyrus\Session\SessionManager;

final class AppTest extends TestCase
{
    protected function tearDown(): void
    {
        App::reset();
    }

    // ─── Configuration ────────────────────────────────────────────────

    public function testConfigurationDefaultsToNull(): void
    {
        self::assertNull(App::getConfiguration());
    }

    public function testSetAndGetConfiguration(): void
    {
        $config = Configuration::fromArray([]);
        App::setConfiguration($config);
        self::assertSame($config, App::getConfiguration());
    }

    // ─── Session ──────────────────────────────────────────────────────

    public function testSessionDefaultsToNull(): void
    {
        self::assertNull(App::getSession());
    }

    public function testSetAndGetSession(): void
    {
        $session = new SessionManager([]);
        App::setSession($session);
        self::assertSame($session, App::getSession());
    }

    // ─── Formatter ────────────────────────────────────────────────────

    public function testFormatterDefaultsToNull(): void
    {
        self::assertNull(App::getFormatter());
    }

    public function testSetAndGetFormatter(): void
    {
        $formatter = new Formatter('en_US');
        App::setFormatter($formatter);
        self::assertSame($formatter, App::getFormatter());
    }

    // ─── Asset ────────────────────────────────────────────────────────

    public function testAssetDefaultsToNull(): void
    {
        self::assertNull(App::getAsset());
    }

    public function testSetAndGetAsset(): void
    {
        $asset = new Asset(sys_get_temp_dir());
        App::setAsset($asset);
        self::assertSame($asset, App::getAsset());
    }

    // ─── Translator ───────────────────────────────────────────────────

    public function testTranslatorDefaultsToNull(): void
    {
        self::assertNull(App::getTranslator());
    }

    public function testSetAndGetTranslator(): void
    {
        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return [];
            }
        };
        $translator = new Translator($loader, 'en');
        App::setTranslator($translator);
        self::assertSame($translator, App::getTranslator());
    }

    // ─── Current Request ──────────────────────────────────────────────

    public function testRequestDefaultsToNull(): void
    {
        self::assertNull(App::getRequest());
    }

    public function testSetAndGetRequest(): void
    {
        $request = Request::fromArray('GET', '/dashboard');

        App::setRequest($request);

        self::assertSame($request, App::getRequest());
    }

    // ─── Inertia ──────────────────────────────────────────────────────

    public function testInertiaDefaultsToNull(): void
    {
        self::assertNull(App::getInertia());
    }

    public function testSetAndGetInertia(): void
    {
        $inertia = new InertiaRenderer(__DIR__ . '/../Inertia/fixtures/app.php');

        App::setInertia($inertia);

        self::assertSame($inertia, App::getInertia());
    }

    // ─── Nonce ────────────────────────────────────────────────────────

    public function testNonceGeneratesBase64String(): void
    {
        $nonce = App::nonce();
        self::assertNotEmpty($nonce);
        // Base64-encoded 16 bytes = 24 characters.
        self::assertSame(24, strlen($nonce));
        self::assertNotFalse(base64_decode($nonce, true));
    }

    public function testNonceIsDeterministicWithinRequest(): void
    {
        $first = App::nonce();
        $second = App::nonce();
        self::assertSame($first, $second);
    }

    public function testResetNonceAllowsNewGeneration(): void
    {
        $first = App::nonce();
        App::resetNonce();
        $second = App::nonce();
        // While theoretically possible to collide, 16 random bytes makes this
        // astronomically unlikely.
        self::assertNotSame($first, $second);
    }

    // ─── Reset ────────────────────────────────────────────────────────

    public function testResetClearsAllServices(): void
    {
        App::setConfiguration(Configuration::fromArray([]));
        App::setSession(new SessionManager([]));
        App::setFormatter(new Formatter('en_US'));
        App::setAsset(new Asset(sys_get_temp_dir()));
        App::setRequest(Request::fromArray('GET', '/'));
        App::setInertia(new InertiaRenderer(__DIR__ . '/../Inertia/fixtures/app.php'));

        $loader = new class implements LocaleLoaderInterface {
            public function load(string $locale): array
            {
                return [];
            }
        };
        App::setTranslator(new Translator($loader, 'en'));
        App::nonce();

        App::reset();

        self::assertNull(App::getConfiguration());
        self::assertNull(App::getSession());
        self::assertNull(App::getFormatter());
        self::assertNull(App::getAsset());
        self::assertNull(App::getTranslator());
        self::assertNull(App::getRequest());
        self::assertNull(App::getInertia());

        // Nonce should be regenerated after reset.
        $newNonce = App::nonce();
        self::assertNotEmpty($newNonce);
    }
}
