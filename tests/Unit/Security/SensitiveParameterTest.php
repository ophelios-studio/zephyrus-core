<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SensitiveParameter;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Routing\RouteSignature;
use Zephyrus\Security\Cryptography;
use Zephyrus\Security\CryptographyException;
use Zephyrus\Security\HeaderTokenGuard;
use Zephyrus\Session\SessionCsrfTokenManager;

/**
 * Every parameter that receives a secret must carry #[\SensitiveParameter].
 *
 * Without the attribute PHP records the argument verbatim in the stack trace of
 * any exception thrown below the call, and a debug-mode error renderer prints
 * that trace. Tracy is the concrete case: Debugger::enable() sets
 * zend.exception_ignore_args back to 0 so its bluescreen can render arguments,
 * which undoes the hardened php.ini setting an image may ship.
 *
 * The attribute is pure metadata. It changes what a trace shows and nothing
 * else, so it is safe on every signature listed here.
 */
final class SensitiveParameterTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function sensitiveParameterProvider(): array
    {
        $cases = [
            [Cryptography::class, 'encrypt', 'plaintext'],
            [Cryptography::class, 'encrypt', 'key'],
            [Cryptography::class, 'decrypt', 'encoded'],
            [Cryptography::class, 'decrypt', 'key'],
            [Cryptography::class, 'hashPassword', 'password'],
            [Cryptography::class, 'hashPassword', 'pepper'],
            [Cryptography::class, 'verifyPassword', 'password'],
            [Cryptography::class, 'verifyPassword', 'hash'],
            [Cryptography::class, 'verifyPassword', 'pepper'],
            [Cryptography::class, 'needsRehash', 'hash'],
            [Cryptography::class, 'hash', 'data'],
            [Cryptography::class, 'hash', 'key'],
            [Cryptography::class, 'hashFile', 'key'],
            [Cryptography::class, 'encodeKey', 'key'],
            [Cryptography::class, 'decodeKey', 'encoded'],
            [RouteSignature::class, '__construct', 'secret'],
            [HeaderTokenGuard::class, '__construct', 'expectedToken'],
            [SessionCsrfTokenManager::class, 'isTokenValid', 'submitted'],
            [DatabaseConfig::class, '__construct', 'password'],
        ];

        $named = [];
        foreach ($cases as $case) {
            $named[$case[0] . '::' . $case[1] . '($' . $case[2] . ')'] = $case;
        }

        return $named;
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('sensitiveParameterProvider')]
    public function testSecretParameterIsMarkedSensitive(string $class, string $method, string $parameter): void
    {
        $reflection = new ReflectionMethod($class, $method);

        foreach ($reflection->getParameters() as $candidate) {
            if ($candidate->getName() !== $parameter) {
                continue;
            }

            self::assertNotEmpty(
                $candidate->getAttributes(SensitiveParameter::class),
                sprintf('%s::%s() parameter $%s must carry #[\SensitiveParameter].', $class, $method, $parameter),
            );

            return;
        }

        self::fail(sprintf('%s::%s() has no parameter named $%s.', $class, $method, $parameter));
    }

    /**
     * The attribute only helps if it survives into the trace PHP actually
     * builds, so assert on a real thrown exception rather than on reflection.
     *
     * zend.exception_ignore_args is forced to 0 for the duration because that
     * is exactly the state a debug-mode request runs in: Tracy's
     * Debugger::enable() sets it to 0 so its bluescreen can render arguments.
     * Leaving the harness default would make this test pass for the wrong
     * reason on any machine whose php.ini already sets it to 1.
     */
    public function testEncryptionKeyIsRedactedFromAThrownStackTrace(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        $key = 'k3y-Th4t-1s-N0t-32-byt3s-l0ng';
        $plaintext = 'Jean Tremblay / 111 222 333';

        try {
            Cryptography::encrypt($plaintext, $key);
            self::fail('Expected the short key to be rejected.');
        } catch (CryptographyException $exception) {
            // getTrace(), not getTraceAsString(): the string form truncates every
            // argument to 15 characters, so it hides the leak instead of proving
            // it. A debug renderer walks the array form, which holds the full
            // value.
            $arguments = [];
            foreach ($exception->getTrace() as $frame) {
                foreach ($frame['args'] ?? [] as $argument) {
                    if (is_string($argument)) {
                        $arguments[] = $argument;
                    }
                }
            }

            self::assertNotContains($key, $arguments, 'The encryption key reached the stack trace.');
            self::assertNotContains($plaintext, $arguments, 'The plaintext reached the stack trace.');
        } finally {
            ini_set('zend.exception_ignore_args', $previous === false ? '1' : $previous);
        }
    }
}
