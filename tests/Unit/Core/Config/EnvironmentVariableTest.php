<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
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
        'http_zephyrus_test',
        'REDIRECT_ZEPHYRUS_TEST',
        'APP_HTTP_TIMEOUT',
        'SERVER_NAME_ALIAS',
        'QUERY_STRING',
        'CONTENT_TYPE',
        'REQUEST_URI',
        'SERVER_NAME',
        'HTTPS',
        'PHP_AUTH_USER',
        'DOCUMENT_ROOT',
        'query_string',
        'REMOTE_PORT',
        'SERVER_ADDR',
        'SCRIPT_URI',
        'SCRIPT_URL',
        'CONTEXT_PREFIX',
        'CONTEXT_DOCUMENT_ROOT',
        'ORIG_SCRIPT_FILENAME',
        'orig_path_info',
        'SSL_CLIENT_S_DN',
        'ssl_server_name',
        'SERVER_SIGNATURE',
        'HTTP2',
        'H2PUSH',
        'H2_PUSH_POLICY',
        'h2_stream_tag',
    ];

    private const NUL_NAMES = ["QUERY_STRING\0x", "ZEPHYRUS_TEST_X\0", "HTTP_ZEPHYRUS_TEST\0x"];

    protected function tearDown(): void
    {
        foreach (self::NAMES as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        foreach (self::NUL_NAMES as $name) {
            unset($_ENV[$name]);
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

    public function testAPasswordNameIsRefusedBeforeAnySourceIsRead(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EnvironmentVariable::read('PHP_AUTH_PW');
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNames(): iterable
    {
        foreach ([
            'QUERY_STRING', 'CONTENT_TYPE', 'REQUEST_URI', 'SERVER_NAME', 'HTTPS', 'PHP_AUTH_USER',
            'DOCUMENT_ROOT', 'REMOTE_PORT', 'SERVER_ADDR', 'SCRIPT_URI', 'SCRIPT_URL',
            'CONTEXT_PREFIX', 'CONTEXT_DOCUMENT_ROOT', 'SERVER_SIGNATURE', 'HTTP2', 'H2PUSH',
        ] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedPrefixedNames(): iterable
    {
        foreach (['ORIG_SCRIPT_FILENAME', 'orig_path_info', 'SSL_CLIENT_S_DN', 'ssl_server_name', 'H2_PUSH_POLICY', 'h2_stream_tag'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('refusedPrefixedNames')]
    public function testANameWithARefusedPrefixIsRefusedWhenTheEnvSuperglobalHoldsIt(string $name): void
    {
        $_ENV[$name] = 'client';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($name);

        EnvironmentVariable::read($name);
    }

    #[DataProvider('refusedPrefixedNames')]
    public function testANameWithARefusedPrefixIsRefusedWhenTheProcessEnvironmentHoldsIt(string $name): void
    {
        putenv($name . '=client');

        $this->expectException(\InvalidArgumentException::class);

        EnvironmentVariable::read($name);
    }

    #[DataProvider('refusedNames')]
    public function testARefusedNameIsRefusedWhenTheEnvSuperglobalHoldsIt(string $name): void
    {
        $_ENV[$name] = 'client';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($name);

        EnvironmentVariable::read($name);
    }

    #[DataProvider('refusedNames')]
    public function testARefusedNameIsRefusedWhenTheProcessEnvironmentHoldsIt(string $name): void
    {
        putenv($name . '=client');

        $this->expectException(\InvalidArgumentException::class);

        EnvironmentVariable::read($name);
    }

    public function testARefusedNameIsRefusedInAnyCase(): void
    {
        putenv('query_string=client');

        $this->expectException(\InvalidArgumentException::class);

        EnvironmentVariable::read('query_string');
    }

    public function testAnHttpPrefixedNameIsRefusedInLowercase(): void
    {
        $_ENV['http_zephyrus_test'] = '1';

        $this->expectException(\InvalidArgumentException::class);

        EnvironmentVariable::read('http_zephyrus_test');
    }

    public function testANameMerelyStartingLikeARefusedNameIsStillRead(): void
    {
        putenv('SERVER_NAME_ALIAS=edge');

        self::assertSame('edge', EnvironmentVariable::read('SERVER_NAME_ALIAS'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesWithANulByte(): iterable
    {
        foreach (self::NUL_NAMES as $name) {
            yield addcslashes($name, "\0") => [$name];
        }
    }

    #[DataProvider('namesWithANulByte')]
    public function testANameContainingANulByteIsRefusedWhenTheEnvSuperglobalHoldsIt(string $name): void
    {
        $_ENV[$name] = 'client';

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NUL byte');

        EnvironmentVariable::read($name);
    }

    #[DataProvider('namesWithANulByte')]
    public function testANameContainingANulByteIsRefusedWhenTheProcessEnvironmentHoldsTheTruncatedName(string $name): void
    {
        putenv('QUERY_STRING=client');
        putenv('ZEPHYRUS_TEST_X=1');
        putenv('HTTP_ZEPHYRUS_TEST=1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('NUL byte');

        EnvironmentVariable::read($name);
    }

    public function testAMissingVariableReadsAsNull(): void
    {
        self::assertNull(EnvironmentVariable::read('ZEPHYRUS_TEST_ABSENT_NAME'));
    }
}
