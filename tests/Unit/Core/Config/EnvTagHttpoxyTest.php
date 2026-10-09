<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\ConfigurationFile;

/**
 * The !env tag refuses HTTP_ and REDIRECT_ names rather than reading request data.
 */
final class EnvTagHttpoxyTest extends TestCase
{
    private string $path;

    /** @var array<string, mixed> */
    private array $serverBackup;

    /** @var array<string, mixed> */
    private array $envBackup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->envBackup = $_ENV;
        $this->path = sys_get_temp_dir() . '/zephyrus-env-tag-' . uniqid('', true) . '.yml';
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_ENV = $this->envBackup;
        @unlink($this->path);

        parent::tearDown();
    }

    /**
     * @return mixed
     */
    private function resolve(string $tag)
    {
        file_put_contents($this->path, "app:\n  value: !env " . $tag . "\n");

        /** @var array{app: array{value: mixed}} $parsed */
        $parsed = (new ConfigurationFile($this->path))->toArray();

        return $parsed['app']['value'];
    }

    public function testAnHttpPrefixedTagIsRefusedNamingTheKey(): void
    {
        // Exactly what a "Proxy: evil.test:8080" request header lands as.
        $_SERVER['HTTP_PROXY'] = 'http://evil.test:8080';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('HTTP_PROXY');

        $this->resolve('HTTP_PROXY, none');
    }

    public function testAnHttpPrefixedTagIsRefusedEvenWhenTheEnvSuperglobalHoldsIt(): void
    {
        $_ENV['HTTP_PROXY'] = 'http://corporate-proxy.internal:3128';

        $this->expectException(ConfigurationException::class);

        $this->resolve('HTTP_PROXY, none');
    }

    public function testARedirectPrefixedTagIsRefused(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic c2VjcmV0';

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('REDIRECT_HTTP_AUTHORIZATION');

        $this->resolve('REDIRECT_HTTP_AUTHORIZATION');
    }

    public function testANonHttpNameSetOnlyInServerFallsBackToTheDefault(): void
    {
        // $_SERVER also carries request data (PHP_AUTH_PW, QUERY_STRING, ...),
        // so it is never a configuration source.
        unset($_ENV['ZEPHYRUS_TEST_DB_HOST']);
        $_SERVER['ZEPHYRUS_TEST_DB_HOST'] = 'db.internal';

        self::assertSame('localhost', $this->resolve('ZEPHYRUS_TEST_DB_HOST, localhost'));
    }

    public function testARequestParameterCannotBecomeConfiguration(): void
    {
        $_SERVER['PHP_AUTH_PW'] = 'typed-by-client';
        unset($_ENV['PHP_AUTH_PW']);

        self::assertNull($this->resolve('PHP_AUTH_PW'));
    }

    public function testEnvTakesPrecedenceAndDefaultsStillApply(): void
    {
        $_ENV['ZEPHYRUS_TEST_ONLY_ENV'] = 'from-env';
        $_SERVER['ZEPHYRUS_TEST_ONLY_ENV'] = 'from-server';

        self::assertSame('from-env', $this->resolve('ZEPHYRUS_TEST_ONLY_ENV, fallback'));

        unset($_ENV['ZEPHYRUS_TEST_ONLY_ENV']);
        self::assertSame('fallback', $this->resolve('ZEPHYRUS_TEST_ONLY_ENV, fallback'));

        unset($_ENV['ZEPHYRUS_TEST_ABSENT'], $_SERVER['ZEPHYRUS_TEST_ABSENT']);
        self::assertSame('fallback', $this->resolve('ZEPHYRUS_TEST_ABSENT, fallback'));
    }
}
