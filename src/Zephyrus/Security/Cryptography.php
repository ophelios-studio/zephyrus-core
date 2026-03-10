<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Stateless cryptographic utilities built on libsodium (ext-sodium).
 *
 * Provides a clean, opinionated API for common cryptographic operations:
 *
 * - **Encryption/Decryption**: XChaCha20-Poly1305 AEAD symmetric encryption.
 * - **Password Hashing**: Argon2id via sodium_crypto_pwhash_str.
 * - **Hashing**: BLAKE2b keyed/unkeyed hashing.
 * - **Random Generation**: Cryptographically secure random strings/bytes/ints.
 * - **Key Management**: Encryption key generation, signing keypair generation.
 *
 * All methods are static. No global state is required.
 *
 * ## Encryption format
 *
 * The `encrypt()` method produces a base64url-encoded string containing:
 *   - 24-byte nonce (XChaCha20-Poly1305 IETF)
 *   - Ciphertext + 16-byte Poly1305 tag
 *
 * Format: base64url(nonce || ciphertext)
 *
 * ## Key requirements
 *
 * - Encryption key: exactly 32 bytes (use `generateEncryptionKey()` to generate).
 * - BLAKE2b key: 16-64 bytes (optional, omit for unkeyed hash).
 */
final class Cryptography
{
    /**
     * Expected key length for XChaCha20-Poly1305 IETF encryption.
     */
    public const int ENCRYPTION_KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES; // 32

    /**
     * Nonce length for XChaCha20-Poly1305 IETF.
     */
    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES; // 24

    // ─── Encryption / Decryption ──────────────────────────────────────

    /**
     * Encrypt a plaintext string using XChaCha20-Poly1305 AEAD.
     *
     * @param string $plaintext The data to encrypt.
     * @param string $key       A 32-byte encryption key (raw binary).
     * @return string Base64url-encoded ciphertext (nonce prepended).
     * @throws CryptographyException If the key is invalid or encryption fails.
     */
    public static function encrypt(string $plaintext, string $key): string
    {
        self::validateEncryptionKey($key);

        $nonce = random_bytes(self::NONCE_BYTES);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                '', // Additional authenticated data (AAD) — empty.
                $nonce,
                $key,
            );
        } catch (\SodiumException $e) {
            throw CryptographyException::encryptionFailed($e);
        }

        return self::base64urlEncode($nonce . $ciphertext);
    }

    /**
     * Decrypt a ciphertext string produced by `encrypt()`.
     *
     * @param string $encoded The base64url-encoded ciphertext.
     * @param string $key     The same 32-byte key used for encryption.
     * @return string The decrypted plaintext.
     * @throws CryptographyException If decryption fails (wrong key, corrupted data).
     */
    public static function decrypt(string $encoded, string $key): string
    {
        self::validateEncryptionKey($key);

        $decoded = self::base64urlDecode($encoded);
        if ($decoded === false || strlen($decoded) < self::NONCE_BYTES + 1) {
            throw CryptographyException::invalidPayload('Ciphertext is too short or malformed.');
        }

        $nonce = substr($decoded, 0, self::NONCE_BYTES);
        $ciphertext = substr($decoded, self::NONCE_BYTES);

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                '', // AAD
                $nonce,
                $key,
            );
        } catch (\SodiumException $e) {
            throw CryptographyException::decryptionFailed($e);
        }

        if ($plaintext === false) {
            throw CryptographyException::decryptionFailed();
        }

        return $plaintext;
    }

    // ─── Password Hashing (Argon2id) ──────────────────────────────────

    /**
     * Hash a password using Argon2id.
     *
     * Optionally prepends a pepper to the password before hashing for
     * defense-in-depth (the pepper should be a secret not stored in the DB).
     *
     * @param string      $password The password to hash.
     * @param string|null $pepper   Optional secret pepper to prepend.
     * @return string The hashed password string (suitable for storage).
     * @throws CryptographyException If hashing fails.
     */
    public static function hashPassword(string $password, ?string $pepper = null): string
    {
        $input = $pepper !== null ? $pepper . $password : $password;

        try {
            $hash = sodium_crypto_pwhash_str(
                $input,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            );
        } catch (\SodiumException $e) {
            throw CryptographyException::hashFailed('Password hashing failed.', $e);
        }

        return $hash;
    }

    /**
     * Verify a password against a hash produced by `hashPassword()`.
     *
     * @param string      $password The password to check.
     * @param string      $hash     The stored hash from `hashPassword()`.
     * @param string|null $pepper   The same pepper used during hashing (if any).
     */
    public static function verifyPassword(string $password, string $hash, ?string $pepper = null): bool
    {
        $input = $pepper !== null ? $pepper . $password : $password;

        try {
            return sodium_crypto_pwhash_str_verify($hash, $input);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Check if a password hash needs to be rehashed (e.g. after security
     * parameter changes).
     */
    public static function needsRehash(string $hash): bool
    {
        try {
            return sodium_crypto_pwhash_str_needs_rehash(
                $hash,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            );
        } catch (\SodiumException) {
            return true;
        }
    }

    // ─── Hashing (BLAKE2b) ────────────────────────────────────────────

    /**
     * Compute a BLAKE2b hash of the given data.
     *
     * @param string      $data   The data to hash.
     * @param string|null $key    Optional 16-64 byte key for keyed hashing.
     * @param int         $length Output length in bytes (16-64, default 32).
     * @return string Hex-encoded hash.
     */
    public static function hash(string $data, ?string $key = null, int $length = SODIUM_CRYPTO_GENERICHASH_BYTES): string
    {
        try {
            $hash = sodium_crypto_generichash($data, $key ?? '', $length);
        } catch (\SodiumException $e) {
            throw CryptographyException::hashFailed('BLAKE2b hashing failed.', $e);
        }

        return sodium_bin2hex($hash);
    }

    /**
     * Compute a BLAKE2b hash of a file's contents.
     *
     * Reads the file in chunks for memory efficiency on large files.
     *
     * @param string      $path   Absolute path to the file.
     * @param string|null $key    Optional key for keyed hashing.
     * @param int         $length Output length in bytes (default 32).
     * @return string Hex-encoded hash.
     */
    public static function hashFile(string $path, ?string $key = null, int $length = SODIUM_CRYPTO_GENERICHASH_BYTES): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw CryptographyException::hashFailed(sprintf('File not found or not readable: %s', $path));
        }

        try {
            $state = sodium_crypto_generichash_init($key ?? '', $length);

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw CryptographyException::hashFailed(sprintf('Unable to open file: %s', $path));
            }

            try {
                while (!feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if ($chunk === false) {
                        break;
                    }
                    sodium_crypto_generichash_update($state, $chunk);
                }
            } finally {
                fclose($handle);
            }

            $hash = sodium_crypto_generichash_final($state, $length);
        } catch (CryptographyException $e) {
            throw $e;
        } catch (\SodiumException $e) {
            throw CryptographyException::hashFailed('BLAKE2b file hashing failed.', $e);
        }

        return sodium_bin2hex($hash);
    }

    // ─── Random Generation ────────────────────────────────────────────

    /**
     * Generate a cryptographically secure random string.
     *
     * Uses a URL-safe base64 alphabet (A-Z, a-z, 0-9, -, _).
     *
     * @param int $length Desired string length in characters.
     * @return string Random URL-safe string of the requested length.
     */
    public static function randomString(int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Length must be at least 1.');
        }

        // Generate enough random bytes, encode, and truncate to requested length.
        $bytesNeeded = (int) ceil($length * 3 / 4) + 1;
        $raw = random_bytes($bytesNeeded);

        return substr(self::base64urlEncode($raw), 0, $length);
    }

    /**
     * Generate cryptographically secure random bytes.
     *
     * @param int $length Number of bytes.
     * @return string Raw random bytes.
     */
    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Length must be at least 1.');
        }

        return random_bytes($length);
    }

    /**
     * Generate a cryptographically secure random hex string.
     *
     * @param int $length Number of hex characters (must be even for full bytes).
     * @return string Hex string.
     */
    public static function randomHex(int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Length must be at least 1.');
        }

        $bytesNeeded = (int) ceil($length / 2);
        return substr(bin2hex(random_bytes($bytesNeeded)), 0, $length);
    }

    /**
     * Generate a cryptographically secure random integer in the given range.
     */
    public static function randomInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }

    // ─── Key Management ───────────────────────────────────────────────

    /**
     * Generate a new random encryption key for XChaCha20-Poly1305.
     *
     * @return string 32-byte raw binary key.
     */
    public static function generateEncryptionKey(): string
    {
        return sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
    }

    /**
     * Generate a new Ed25519 signing keypair.
     *
     * @return array{publicKey: string, secretKey: string} Hex-encoded keys.
     */
    public static function generateSigningKeyPair(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            'publicKey' => sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
            'secretKey' => sodium_bin2hex(sodium_crypto_sign_secretkey($keypair)),
        ];
    }

    /**
     * Encode an encryption key as base64url for safe storage in config/env.
     */
    public static function encodeKey(string $key): string
    {
        return self::base64urlEncode($key);
    }

    /**
     * Decode a base64url-encoded encryption key back to raw bytes.
     */
    public static function decodeKey(string $encoded): string
    {
        $key = self::base64urlDecode($encoded);
        if ($key === false) {
            throw CryptographyException::invalidKey('Unable to decode key (invalid base64url).');
        }
        return $key;
    }

    // ─── Internal Helpers ─────────────────────────────────────────────

    private static function validateEncryptionKey(string $key): void
    {
        if (strlen($key) !== self::ENCRYPTION_KEY_BYTES) {
            throw CryptographyException::invalidKey(sprintf(
                'Key must be exactly %d bytes, got %d.',
                self::ENCRYPTION_KEY_BYTES,
                strlen($key),
            ));
        }
    }

    private static function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string|false
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');
        return base64_decode($padded, true);
    }
}
