<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Exceptions\ZephyrusRuntimeException;
use Zephyrus\Security\CryptographyException;

final class CryptographyExceptionTest extends TestCase
{
    public function testExtendsZephyrusRuntimeException(): void
    {
        $exception = new CryptographyException('test');
        self::assertInstanceOf(ZephyrusRuntimeException::class, $exception);
    }

    public function testEncryptionFailed(): void
    {
        $exception = CryptographyException::encryptionFailed();
        self::assertStringContainsString('Encryption failed', $exception->getMessage());
    }

    public function testEncryptionFailedWithPrevious(): void
    {
        $previous = new \RuntimeException('Low-level error');
        $exception = CryptographyException::encryptionFailed($previous);
        self::assertSame($previous, $exception->getPrevious());
    }

    public function testDecryptionFailed(): void
    {
        $exception = CryptographyException::decryptionFailed();
        self::assertStringContainsString('Decryption failed', $exception->getMessage());
    }

    public function testInvalidKey(): void
    {
        $exception = CryptographyException::invalidKey('too short');
        self::assertStringContainsString('too short', $exception->getMessage());
    }

    public function testInvalidPayload(): void
    {
        $exception = CryptographyException::invalidPayload('corrupted');
        self::assertStringContainsString('corrupted', $exception->getMessage());
    }

    public function testHashFailed(): void
    {
        $exception = CryptographyException::hashFailed('unsupported algorithm');
        self::assertStringContainsString('unsupported algorithm', $exception->getMessage());
    }

    public function testHashFailedWithPrevious(): void
    {
        $previous = new \RuntimeException('sodium error');
        $exception = CryptographyException::hashFailed('failed', $previous);
        self::assertSame($previous, $exception->getPrevious());
    }
}
