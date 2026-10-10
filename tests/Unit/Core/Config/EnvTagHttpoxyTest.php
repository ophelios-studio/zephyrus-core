<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\ConfigurationFile;

/**
 * The !env tag refuses names that web servers fill from the request rather than reading them.
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
        $this->path = sys_get_temp_dir() . '/zephyrus-env-tag-' . bin2hex(random_bytes(8)) . '.yml';
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
        return $this->resolveYaml("app:\n  value: !env " . $tag . "\n")['app']['value'];
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveYaml(string $yaml): array
    {
        file_put_contents($this->path, $yaml);

        return (new ConfigurationFile($this->path))->toArray();
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

    public function testARequestDataNameRaisesAConfigurationExceptionInAnEnvTag(): void
    {
        $this->expectException(\Zephyrus\Core\Config\ConfigurationException::class);

        $this->resolve('PHP_AUTH_PW');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyNames(): iterable
    {
        yield 'empty quotes' => ['""'];
        yield 'NUL only' => ['"\\0"'];
        yield 'empty name with a default' => [', fallback'];
    }

    #[DataProvider('emptyNames')]
    public function testAnEmptyNameInAnEnvTagIsRefusedNamingTheKey(string $tag): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"app.value"');

        $this->resolve($tag);
    }

    public function testARefusedEnvTagInAListNamesTheIndexedPath(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('!env tag at "app.allowed_hosts.0": it has an empty variable name');

        $this->resolveYaml("app:\n  allowed_hosts:\n    - !env \"\"\n");
    }

    public function testARefusedEnvTagNamesItsFullPathWhenTheLastSegmentIsAmbiguous(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('!env tag at "mailer.host": HTTP_PROXY: ');

        $this->resolveYaml("database:\n  host: localhost\nmailer:\n  host: !env HTTP_PROXY\n");
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonScalarNames(): iterable
    {
        yield 'sequence' => ['[QUERY_STRING]'];
        yield 'mapping' => ['{name: QUERY_STRING}'];
    }

    #[DataProvider('nonScalarNames')]
    public function testANonScalarEnvTagValueIsRefusedNamingTheKey(string $tag): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('!env tag at "app.value": it must name one variable, not a list or mapping');

        $this->resolve($tag);
    }
}
