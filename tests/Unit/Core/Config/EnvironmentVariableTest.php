<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\EnvironmentVariable;

final class EnvironmentVariableTest extends TestCase
{
    private const NAMES = [
        'ZEPHYRUS_TEST_X',
        'ZEPHYRUS_TEST_ONLY_SERVER',
        'ZEPHYRUS_TEST_ZERO',
        'ZEPHYRUS_TEST_EMPTY',
        'ZEPHYRUS_TEST_NON_SCALAR',
        'PHP_AUTH_PW',
        'HTTP_ZEPHYRUS_TEST',
        'REDIRECT_ZEPHYRUS_TEST',
        'APP_HTTP_TIMEOUT',
    ];

    protected function tearDown(): void
    {
        foreach (self::NAMES as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        parent::tearDown();
    }

    public function testEnvSuperglobalWinsOverTheProcessEnvironment(): void
    {
        putenv('ZEPHYRUS_TEST_X=from-process');
        $_ENV['ZEPHYRUS_TEST_X'] = 'from-env';

        self::assertSame('from-env', EnvironmentVariable::read('ZEPHYRUS_TEST_X'));
    }

    public function testTheProcessEnvironmentIsReadWhenEnvSuperglobalIsEmpty(): void
    {
        putenv('ZEPHYRUS_TEST_X=1');

        self::assertSame('1', EnvironmentVariable::read('ZEPHYRUS_TEST_X'));
    }

    public function testAKeyOnlyInServerIsNeverRead(): void
    {
        $_SERVER['ZEPHYRUS_TEST_ONLY_SERVER'] = 'v';

        self::assertNull(EnvironmentVariable::read('ZEPHYRUS_TEST_ONLY_SERVER'));
    }

    public function testRequestDataInServerIsNeverRead(): void
    {
        // Set by mod_php under Apache: the client's typed password.
        $_SERVER['PHP_AUTH_PW'] = 'typed';

        self::assertNull(EnvironmentVariable::read('PHP_AUTH_PW'));
    }

    public function testZeroIsReturnedAsTheStringZero(): void
    {
        $_ENV['ZEPHYRUS_TEST_ZERO'] = '0';

        self::assertSame('0', EnvironmentVariable::read('ZEPHYRUS_TEST_ZERO'));
    }

    public function testAnEmptyValueIsReturnedAsAnEmptyString(): void
    {
        putenv('ZEPHYRUS_TEST_EMPTY=');

        self::assertSame('', EnvironmentVariable::read('ZEPHYRUS_TEST_EMPTY'));
    }

    public function testAScalarEnvValueIsCastToString(): void
    {
        $_ENV['ZEPHYRUS_TEST_X'] = 42;

        self::assertSame('42', EnvironmentVariable::read('ZEPHYRUS_TEST_X'));
    }

    public function testANonScalarEnvValueReadsAsNull(): void
    {
        $_ENV['ZEPHYRUS_TEST_NON_SCALAR'] = ['nested'];

        self::assertNull(EnvironmentVariable::read('ZEPHYRUS_TEST_NON_SCALAR'));
    }

    public function testAnHttpPrefixedNameIsRefusedEvenWhenTheProcessEnvironmentHoldsIt(): void
    {
        // Under plain CGI the process environment is built from request headers.
        putenv('HTTP_ZEPHYRUS_TEST=1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP_ZEPHYRUS_TEST');

        EnvironmentVariable::read('HTTP_ZEPHYRUS_TEST');
    }

    public function testAnHttpPrefixedNameIsRefusedEvenWhenTheEnvSuperglobalHoldsIt(): void
    {
        $_ENV['HTTP_ZEPHYRUS_TEST'] = '1';

        $this->expectException(\InvalidArgumentException::class);

        EnvironmentVariable::read('HTTP_ZEPHYRUS_TEST');
    }

    public function testARedirectPrefixedNameIsRefused(): void
    {
        // Apache's Action handler exposes client headers as REDIRECT_HTTP_*.
        $_ENV['REDIRECT_ZEPHYRUS_TEST'] = '1';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIRECT_ZEPHYRUS_TEST');

        EnvironmentVariable::read('REDIRECT_ZEPHYRUS_TEST');
    }

    public function testANameContainingButNotStartingWithHttpIsStillRead(): void
    {
        putenv('APP_HTTP_TIMEOUT=5');

        self::assertSame('5', EnvironmentVariable::read('APP_HTTP_TIMEOUT'));
    }

    public function testAMissingVariableReadsAsNull(): void
    {
        self::assertNull(EnvironmentVariable::read('ZEPHYRUS_TEST_ABSENT_NAME'));
    }
}
