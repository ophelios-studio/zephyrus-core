<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Security\Cryptography;
use Zephyrus\Security\CryptographyException;

final class CryptographyTest extends TestCase
{
    // ─── Encryption / Decryption ──────────────────────────────────────

    public function testEncryptDecryptRoundTrip(): void
    {
        $key = Cryptography::generateEncryptionKey();
        $plaintext = 'Hello, World!';

        $encrypted = Cryptography::encrypt($plaintext, $key);
        $decrypted = Cryptography::decrypt($encrypted, $key);

        self::assertSame($plaintext, $decrypted);
    }

    public function testEncryptProducesDifferentOutputEachTime(): void
    {
        $key = Cryptography::generateEncryptionKey();
        $plaintext = 'Same input, different nonces.';

        $first = Cryptography::encrypt($plaintext, $key);
        $second = Cryptography::encrypt($plaintext, $key);

        // Same plaintext produces different ciphertext due to random nonce.
        self::assertNotSame($first, $second);
    }

    public function testDecryptWithWrongKeyThrows(): void
    {
        $key1 = Cryptography::generateEncryptionKey();
        $key2 = Cryptography::generateEncryptionKey();
        $encrypted = Cryptography::encrypt('secret', $key1);

        $this->expectException(CryptographyException::class);
        Cryptography::decrypt($encrypted, $key2);
    }

    public function testDecryptWithCorruptedPayloadThrows(): void
    {
        $key = Cryptography::generateEncryptionKey();

        $this->expectException(CryptographyException::class);
        Cryptography::decrypt('not-valid-ciphertext!!', $key);
    }

    public function testDecryptWithTruncatedPayloadThrows(): void
    {
        $key = Cryptography::generateEncryptionKey();

        $this->expectException(CryptographyException::class);
        $this->expectExceptionMessage('too short or malformed');
        Cryptography::decrypt('dG9vc2hvcnQ', $key); // "tooshort" in base64url
    }

    public function testEncryptWithInvalidKeyLengthThrows(): void
    {
        $this->expectException(CryptographyException::class);
        $this->expectExceptionMessage('Key must be exactly');
        Cryptography::encrypt('test', 'short-key');
    }

    public function testDecryptWithInvalidKeyLengthThrows(): void
    {
        $this->expectException(CryptographyException::class);
        $this->expectExceptionMessage('Key must be exactly');
        Cryptography::decrypt('test', 'short-key');
    }

    public function testEncryptDecryptEmptyString(): void
    {
        $key = Cryptography::generateEncryptionKey();
        $encrypted = Cryptography::encrypt('', $key);
        $decrypted = Cryptography::decrypt($encrypted, $key);

        self::assertSame('', $decrypted);
    }

    public function testEncryptDecryptLargePayload(): void
    {
        $key = Cryptography::generateEncryptionKey();
        $plaintext = str_repeat('A', 100_000);

        $encrypted = Cryptography::encrypt($plaintext, $key);
        $decrypted = Cryptography::decrypt($encrypted, $key);

        self::assertSame($plaintext, $decrypted);
    }

    public function testEncryptDecryptUnicode(): void
    {
        $key = Cryptography::generateEncryptionKey();
        $plaintext = 'Bonjour le monde! こんにちは世界 🌍';

        $encrypted = Cryptography::encrypt($plaintext, $key);
        $decrypted = Cryptography::decrypt($encrypted, $key);

        self::assertSame($plaintext, $decrypted);
    }

    // ─── Password Hashing ─────────────────────────────────────────────

    public function testHashPasswordAndVerify(): void
    {
        $hash = Cryptography::hashPassword('my-secret-password');

        self::assertTrue(Cryptography::verifyPassword('my-secret-password', $hash));
        self::assertFalse(Cryptography::verifyPassword('wrong-password', $hash));
    }

    public function testHashPasswordWithPepper(): void
    {
        $pepper = 'app-secret-pepper';
        $hash = Cryptography::hashPassword('my-password', $pepper);

        self::assertTrue(Cryptography::verifyPassword('my-password', $hash, $pepper));
        self::assertFalse(Cryptography::verifyPassword('my-password', $hash)); // No pepper.
        self::assertFalse(Cryptography::verifyPassword('my-password', $hash, 'wrong-pepper'));
    }

    public function testHashPasswordProducesDifferentHashEachTime(): void
    {
        $first = Cryptography::hashPassword('same-password');
        $second = Cryptography::hashPassword('same-password');

        self::assertNotSame($first, $second);
        // But both should verify.
        self::assertTrue(Cryptography::verifyPassword('same-password', $first));
        self::assertTrue(Cryptography::verifyPassword('same-password', $second));
    }

    public function testNeedsRehashReturnsFalseForFreshHash(): void
    {
        $hash = Cryptography::hashPassword('test');

        self::assertFalse(Cryptography::needsRehash($hash));
    }

    public function testNeedsRehashReturnsTrueForInvalidHash(): void
    {
        self::assertTrue(Cryptography::needsRehash('not-a-valid-hash'));
    }

    // ─── Hashing (BLAKE2b) ────────────────────────────────────────────

    public function testHashProducesConsistentOutput(): void
    {
        $hash1 = Cryptography::hash('hello');
        $hash2 = Cryptography::hash('hello');

        self::assertSame($hash1, $hash2);
    }

    public function testHashProducesHexString(): void
    {
        $hash = Cryptography::hash('test');

        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hash);
        self::assertSame(64, strlen($hash)); // 32 bytes = 64 hex chars.
    }

    public function testHashWithDifferentInputsProducesDifferentOutput(): void
    {
        $hash1 = Cryptography::hash('input1');
        $hash2 = Cryptography::hash('input2');

        self::assertNotSame($hash1, $hash2);
    }

    public function testKeyedHashDiffersFromUnkeyed(): void
    {
        $data = 'test data';
        $key = random_bytes(32);

        $unkeyed = Cryptography::hash($data);
        $keyed = Cryptography::hash($data, $key);

        self::assertNotSame($unkeyed, $keyed);
    }

    public function testHashWithCustomLength(): void
    {
        $hash = Cryptography::hash('test', length: 16);

        self::assertSame(32, strlen($hash)); // 16 bytes = 32 hex chars.
    }

    public function testHashFileProducesConsistentOutput(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'zephyrus-test-');
        file_put_contents($path, 'file content');

        try {
            $hash1 = Cryptography::hashFile($path);
            $hash2 = Cryptography::hashFile($path);

            self::assertSame($hash1, $hash2);
            self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hash1);
        } finally {
            @unlink($path);
        }
    }

    public function testHashFileMatchesHashOfContent(): void
    {
        $content = 'identical content';
        $path = tempnam(sys_get_temp_dir(), 'zephyrus-test-');
        file_put_contents($path, $content);

        try {
            $fileHash = Cryptography::hashFile($path);
            $contentHash = Cryptography::hash($content);

            self::assertSame($contentHash, $fileHash);
        } finally {
            @unlink($path);
        }
    }

    public function testHashFileThrowsForMissingFile(): void
    {
        $this->expectException(CryptographyException::class);
        $this->expectExceptionMessage('File not found');
        Cryptography::hashFile('/nonexistent/file.txt');
    }

    // ─── Random Generation ────────────────────────────────────────────

    public function testRandomStringHasCorrectLength(): void
    {
        self::assertSame(16, strlen(Cryptography::randomString(16)));
        self::assertSame(32, strlen(Cryptography::randomString(32)));
        self::assertSame(1, strlen(Cryptography::randomString(1)));
        self::assertSame(100, strlen(Cryptography::randomString(100)));
    }

    public function testRandomStringIsUrlSafe(): void
    {
        $str = Cryptography::randomString(1000);

        // URL-safe base64 alphabet.
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $str);
    }

    public function testRandomStringProducesDifferentValues(): void
    {
        $first = Cryptography::randomString(32);
        $second = Cryptography::randomString(32);

        self::assertNotSame($first, $second);
    }

    public function testRandomStringThrowsForZeroLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cryptography::randomString(0);
    }

    public function testRandomBytesHasCorrectLength(): void
    {
        self::assertSame(16, strlen(Cryptography::randomBytes(16)));
        self::assertSame(32, strlen(Cryptography::randomBytes(32)));
    }

    public function testRandomBytesThrowsForZeroLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cryptography::randomBytes(0);
    }

    public function testRandomHexHasCorrectLength(): void
    {
        self::assertSame(16, strlen(Cryptography::randomHex(16)));
        self::assertSame(32, strlen(Cryptography::randomHex(32)));
        self::assertSame(1, strlen(Cryptography::randomHex(1)));
    }

    public function testRandomHexIsValidHex(): void
    {
        $hex = Cryptography::randomHex(64);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $hex);
    }

    public function testRandomHexThrowsForZeroLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Cryptography::randomHex(0);
    }

    public function testRandomIntIsWithinRange(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $value = Cryptography::randomInt(10, 20);
            self::assertGreaterThanOrEqual(10, $value);
            self::assertLessThanOrEqual(20, $value);
        }
    }

    public function testRandomIntCanReturnBoundaryValues(): void
    {
        // With a tight range, we should hit both boundaries.
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $seen[Cryptography::randomInt(0, 1)] = true;
        }

        self::assertArrayHasKey(0, $seen);
        self::assertArrayHasKey(1, $seen);
    }

    // ─── Key Management ───────────────────────────────────────────────

    public function testGenerateEncryptionKeyHasCorrectLength(): void
    {
        $key = Cryptography::generateEncryptionKey();

        self::assertSame(Cryptography::ENCRYPTION_KEY_BYTES, strlen($key));
    }

    public function testGenerateEncryptionKeyProducesDifferentKeys(): void
    {
        $key1 = Cryptography::generateEncryptionKey();
        $key2 = Cryptography::generateEncryptionKey();

        self::assertNotSame($key1, $key2);
    }

    public function testGenerateSigningKeyPairReturnsPublicAndSecretKeys(): void
    {
        $pair = Cryptography::generateSigningKeyPair();

        self::assertArrayHasKey('publicKey', $pair);
        self::assertArrayHasKey('secretKey', $pair);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $pair['publicKey']);
        self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $pair['secretKey']);
        // Ed25519 public key = 32 bytes = 64 hex chars.
        self::assertSame(64, strlen($pair['publicKey']));
        // Ed25519 secret key = 64 bytes = 128 hex chars.
        self::assertSame(128, strlen($pair['secretKey']));
    }

    public function testEncodeDecodeKeyRoundTrip(): void
    {
        $key = Cryptography::generateEncryptionKey();

        $encoded = Cryptography::encodeKey($key);
        $decoded = Cryptography::decodeKey($encoded);

        self::assertSame($key, $decoded);
    }

    public function testDecodeKeyThrowsForInvalidBase64(): void
    {
        $this->expectException(CryptographyException::class);
        $this->expectExceptionMessage('Unable to decode key');
        // Invalid base64url (contains characters that break padding/decoding).
        Cryptography::decodeKey('!!!invalid!!!');
    }

    public function testEncryptionKeyConstant(): void
    {
        self::assertSame(32, Cryptography::ENCRYPTION_KEY_BYTES);
    }
}
