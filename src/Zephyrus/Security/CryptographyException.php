<?php

declare(strict_types=1);

namespace Zephyrus\Security;

use Zephyrus\Exceptions\ZephyrusRuntimeException;

/**
 * Thrown when a cryptographic operation fails.
 */
final class CryptographyException extends ZephyrusRuntimeException
{
    public static function encryptionFailed(?\Throwable $previous = null): self
    {
        return new self('Encryption failed.', previous: $previous);
    }

    public static function decryptionFailed(?\Throwable $previous = null): self
    {
        return new self(
            'Decryption failed. The ciphertext may be corrupted or the key is incorrect.',
            previous: $previous,
        );
    }

    public static function invalidKey(string $reason): self
    {
        return new self(sprintf('Invalid cryptographic key: %s', $reason));
    }

    public static function invalidPayload(string $reason): self
    {
        return new self(sprintf('Invalid cryptographic payload: %s', $reason));
    }

    public static function hashFailed(string $reason, ?\Throwable $previous = null): self
    {
        return new self(sprintf('Hashing failed: %s', $reason), previous: $previous);
    }

    public static function invalidArgument(string $reason): self
    {
        return new self($reason);
    }
}
