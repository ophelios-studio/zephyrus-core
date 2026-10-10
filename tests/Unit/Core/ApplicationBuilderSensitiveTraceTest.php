<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use Zephyrus\Core\ApplicationBuilder;
use Zephyrus\Core\Bootstrap\ApplicationBootstrap;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\ConfigurationException;

/**
 * Debug renderers print the arguments of every frame, so the configuration's secrets must not be readable there.
 */
final class ApplicationBuilderSensitiveTraceTest extends TestCase
{
    private const string PASSWORD = 's3cret-pw';

    private const string ENCRYPTION_KEY = 'k3y-material';

    private string|false $ignoreArgs;

    protected function setUp(): void
    {
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->ignoreArgs);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): mixed}>
     */
    public static function arrayEntryPoints(): iterable
    {
        yield 'ApplicationBuilder::fromConfigurationArray()' => [
            static fn (array $configuration): mixed => ApplicationBuilder::fromConfigurationArray($configuration),
        ];
        yield 'ApplicationBuilder::withConfigurationArray()' => [
            static fn (array $configuration): mixed => ApplicationBuilder::create()->withConfigurationArray($configuration),
        ];
        yield 'ApplicationBuilder::buildFromConfigurationArray()' => [
            static fn (array $configuration): mixed => ApplicationBuilder::buildFromConfigurationArray($configuration),
        ];
        yield 'ApplicationBootstrap::fromConfigurationArray()' => [
            static fn (array $configuration): mixed => ApplicationBootstrap::fromConfigurationArray($configuration),
        ];
    }

    /**
     * @param \Closure(array<string, mixed>): mixed $entryPoint
     */
    #[DataProvider('arrayEntryPoints')]
    public function testARefusedConfigurationArrayKeepsItsSecretsOutOfTheTrace(\Closure $entryPoint): void
    {
        $e = $this->thrownBy(static fn (): mixed => $entryPoint([
            'security' => ['encryption' => ['key' => self::ENCRYPTION_KEY]],
            'database' => ['host' => 'db.example.com', 'database' => 'app', 'pasword' => self::PASSWORD],
        ]));

        self::assertInstanceOf(ConfigurationException::class, $e);
        self::assertArrayHasKey('args', $e->getTrace()[0], 'Control: the trace must carry arguments.');
        self::assertSame([], self::framesPrinting($e, self::PASSWORD));
        self::assertSame([], self::framesPrinting($e, self::ENCRYPTION_KEY));
    }

    public function testAnUnwiredSecuritySettingKeepsTheConfigurationOutOfTheTrace(): void
    {
        $configuration = Configuration::fromArray([
            'security' => ['forceHttps' => true, 'encryption' => ['key' => self::ENCRYPTION_KEY]],
            'database' => ['host' => 'db.example.com', 'database' => 'app', 'username' => 'app', 'password' => self::PASSWORD],
        ]);

        $e = $this->thrownBy(static fn (): mixed => ApplicationBuilder::buildFromConfiguration($configuration));

        self::assertInstanceOf(ConfigurationException::class, $e);
        self::assertSame([], self::framesPrinting($e, self::PASSWORD));
        self::assertSame([], self::framesPrinting($e, self::ENCRYPTION_KEY));
    }

    private function thrownBy(callable $call): Throwable
    {
        try {
            $call();
        } catch (Throwable $e) {
            return $e;
        }

        self::fail('expected an exception');
    }

    /**
     * The framework frames of the trace whose arguments print the secret.
     *
     * @return list<string>
     */
    private static function framesPrinting(Throwable $e, string $secret): array
    {
        $printing = [];
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            if (str_starts_with($class, 'Zephyrus\\') && !str_starts_with($class, 'Zephyrus\\Tests\\')
                && str_contains(print_r($frame['args'] ?? [], true), $secret)) {
                $printing[] = $class . '::' . $frame['function'];
            }
        }

        return $printing;
    }
}
