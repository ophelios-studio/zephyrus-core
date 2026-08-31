<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationFile;

/**
 * The !env tag used to fall back to $_SERVER, which under CGI and FastCGI
 * carries every request header as HTTP_<NAME>. "!env HTTP_PROXY" therefore
 * resolved to whatever a caller put in a "Proxy:" header. That is httpoxy,
 * CVE-2016-5385.
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

    public function testAnHttpPrefixedNameIsNeverReadFromServer(): void
    {
        // Exactly what a "Proxy: evil.test:8080" request header lands as.
        $_SERVER['HTTP_PROXY'] = 'http://evil.test:8080';
        unset($_ENV['HTTP_PROXY']);

        // Pre-fix this returned "http://evil.test:8080".
        self::assertSame('none', $this->resolve('HTTP_PROXY, none'));
    }

    public function testAnHttpPrefixedNameWithNoDefaultResolvesToNull(): void
    {
        $_SERVER['HTTP_X_ANYTHING'] = 'attacker-chosen';
        unset($_ENV['HTTP_X_ANYTHING']);

        self::assertNull($this->resolve('HTTP_X_ANYTHING'));
    }

    public function testARealProcessEnvironmentVariableStillWinsEvenWhenHttpPrefixed(): void
    {
        // $_ENV is not client-writable, so an operator who genuinely exports
        // HTTP_PROXY still gets it.
        $_ENV['HTTP_PROXY'] = 'http://corporate-proxy.internal:3128';
        $_SERVER['HTTP_PROXY'] = 'http://evil.test:8080';

        self::assertSame('http://corporate-proxy.internal:3128', $this->resolve('HTTP_PROXY, none'));
    }

    public function testANonHttpNameIsStillReadFromServer(): void
    {
        // Non-breakage: setting configuration through fastcgi_param / SetEnv is
        // a documented deployment pattern and it lands in $_SERVER only.
        unset($_ENV['ZEPHYRUS_TEST_DB_HOST']);
        $_SERVER['ZEPHYRUS_TEST_DB_HOST'] = 'db.internal';

        self::assertSame('db.internal', $this->resolve('ZEPHYRUS_TEST_DB_HOST, localhost'));
    }

    public function testEnvTakesPrecedenceAndDefaultsStillApply(): void
    {
        $_ENV['ZEPHYRUS_TEST_ONLY_ENV'] = 'from-env';
        $_SERVER['ZEPHYRUS_TEST_ONLY_ENV'] = 'from-server';

        self::assertSame('from-env', $this->resolve('ZEPHYRUS_TEST_ONLY_ENV, fallback'));

        unset($_ENV['ZEPHYRUS_TEST_ABSENT'], $_SERVER['ZEPHYRUS_TEST_ABSENT']);
        self::assertSame('fallback', $this->resolve('ZEPHYRUS_TEST_ABSENT, fallback'));
    }
}
