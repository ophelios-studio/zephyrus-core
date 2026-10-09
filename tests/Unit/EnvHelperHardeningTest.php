<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * env() reads $_ENV and the process environment, never request data, and
 * refuses HTTP_ and REDIRECT_ names.
 */
final class EnvHelperHardeningTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envBackup = [];

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    /** @var list<string> */
    private array $names = [
        'HTTP_PROXY',
        'HTTP_ZEPHYRUS_ANYTHING',
        'REDIRECT_HTTP_AUTHORIZATION',
        'ZEPHYRUS_FPM_SECRET',
        'ZEPHYRUS_FPM_FLAG',
        'ZEPHYRUS_FASTCGI_PARAM',
        'ZEPHYRUS_PRECEDENCE',
    ];

    protected function setUp(): void
    {
        foreach ($this->names as $name) {
            $this->envBackup[$name] = $_ENV[$name] ?? null;
            $this->serverBackup[$name] = $_SERVER[$name] ?? null;
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->names as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            if ($this->envBackup[$name] !== null) {
                $_ENV[$name] = $this->envBackup[$name];
            }
            if ($this->serverBackup[$name] !== null) {
                $_SERVER[$name] = $this->serverBackup[$name];
            }
        }
    }

    public function testAnHttpPrefixedNameIsRefusedWhateverItsSource(): void
    {
        // Exactly what PHP does with an inbound `Proxy:` header.
        $_SERVER['HTTP_PROXY'] = 'http://attacker.test:3128';
        putenv('HTTP_PROXY=http://corporate-proxy.internal:3128');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP_PROXY');

        env('HTTP_PROXY');
    }

    public function testARedirectPrefixedNameIsRefused(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Basic c2VjcmV0';

        $this->expectException(InvalidArgumentException::class);

        env('REDIRECT_HTTP_AUTHORIZATION', 'none');
    }

    public function testAnyHttpNamedServerKeyIsRefusedRatherThanReturningItsValue(): void
    {
        $_SERVER['HTTP_ZEPHYRUS_ANYTHING'] = 'attacker-chosen';

        $this->expectException(InvalidArgumentException::class);

        env('HTTP_ZEPHYRUS_ANYTHING');
    }

    public function testAProcessEnvironmentValueIsReturned(): void
    {
        putenv('ZEPHYRUS_FPM_SECRET=whsec_LIVE_abc123');

        self::assertSame('whsec_LIVE_abc123', env('ZEPHYRUS_FPM_SECRET'));
    }

    public function testAProcessEnvironmentFlagIsReadAsTrue(): void
    {
        putenv('ZEPHYRUS_FPM_FLAG=true');

        self::assertTrue(env('ZEPHYRUS_FPM_FLAG', false));
    }

    public function testAFastcgiParamIsNotReadAsConfiguration(): void
    {
        $_SERVER['ZEPHYRUS_FASTCGI_PARAM'] = 'from-fastcgi';

        self::assertNull(env('ZEPHYRUS_FASTCGI_PARAM'));
    }

    public function testTheResolutionOrderIsEnvThenProcessEnvironment(): void
    {
        $_SERVER['ZEPHYRUS_PRECEDENCE'] = 'from-server';
        putenv('ZEPHYRUS_PRECEDENCE=from-getenv');
        $_ENV['ZEPHYRUS_PRECEDENCE'] = 'from-env';

        self::assertSame('from-env', env('ZEPHYRUS_PRECEDENCE'));

        unset($_ENV['ZEPHYRUS_PRECEDENCE']);
        self::assertSame('from-getenv', env('ZEPHYRUS_PRECEDENCE'));

        putenv('ZEPHYRUS_PRECEDENCE');
        self::assertNull(env('ZEPHYRUS_PRECEDENCE'));
    }
}
