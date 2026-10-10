<?php

declare(strict_types=1);

namespace Zephyrus\Security;

/**
 * Stateless cryptographic helpers built on libsodium (ext-sodium).
 *
 * Encryption is XChaCha20-Poly1305 IETF AEAD, password hashing is Argon2id and
 * hashing is BLAKE2b.
 *
 * ## Encryption format
 *
 * `encrypt()` returns base64url(nonce || ciphertext): a fresh random 24-byte
 * nonce per call, then the ciphertext with its 16-byte Poly1305 tag. The tag
 * covers the ciphertext and the context.
 *
 * ## Context binding
 *
 * `$context` is additional authenticated data: it is neither secret nor stored,
 * and `decrypt()` must receive it verbatim. A ciphertext moved to another tenant,
 * table, column or row fails to open. The empty default keeps existing ciphertext
 * opening; adopting a context on populated data means decrypting and
 * re-encrypting, there is no in-place upgrade.
 *
 * ## Key requirements
 *
 * - Encryption key: exactly 32 bytes, not all-zero (see `generateEncryptionKey()`).
 * - BLAKE2b key: `null` for an unkeyed hash, otherwise 16-64 bytes. An empty
 *   string is rejected.
 */
final class Cryptography
{
    /**
     * Expected key length for XChaCha20-Poly1305 IETF encryption.
     */
    public const int ENCRYPTION_KEY_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

    /**
     * Nonce length for XChaCha20-Poly1305 IETF.
     */
    private const int NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

    /**
     * Encrypt a plaintext string with XChaCha20-Poly1305 AEAD.
     *
     * @param string $plaintext The data to encrypt.
     * @param string $key       A 32-byte encryption key (raw binary).
     * @param string $context   Additional authenticated data, e.g. "tenant:42|table:client|column:ssn".
     *                          Must be repeated verbatim by `decrypt()`.
     * @return string Base64url-encoded nonce and ciphertext.
     * @throws CryptographyException If the key is invalid or encryption fails.
     */
    public static function encrypt(
        #[\SensitiveParameter] string $plaintext,
        #[\SensitiveParameter] string $key,
        string $context = '',
    ): string {
        self::validateEncryptionKey($key);

        $nonce = random_bytes(self::NONCE_BYTES);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $context,
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
     * @param string $context The same context passed to `encrypt()`; a mismatch fails like tampering.
     * @return string The decrypted plaintext.
     * @throws CryptographyException If the key is invalid, the payload is malformed,
     *                               or authentication fails (wrong key or context, tampering).
     */
    public static function decrypt(
        #[\SensitiveParameter] string $encoded,
        #[\SensitiveParameter] string $key,
        string $context = '',
    ): string {
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
                $context,
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

    /**
     * Hash a password with Argon2id at the interactive limits.
     *
     * The pepper, a secret kept outside the database, is prepended with no delimiter,
     * so distinct pairs can collide: pepper 'A' with password 'B' matches pepper null
     * with password 'AB'. Use a fixed-length pepper (e.g. 32 bytes) to rule that out.
     * The construction is kept as is, since changing it invalidates every stored peppered hash.
     *
     * @param string      $password The password to hash.
     * @param string|null $pepper   Optional secret pepper to prepend.
     * @return string The hashed password string (suitable for storage).
     * @throws CryptographyException If hashing fails.
     */
    public static function hashPassword(
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] ?string $pepper = null,
    ): string {
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
    public static function verifyPassword(
        #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $hash,
        #[\SensitiveParameter] ?string $pepper = null,
    ): bool {
        $input = $pepper !== null ? $pepper . $password : $password;

        try {
            return sodium_crypto_pwhash_str_verify($hash, $input);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Check if a password hash needs to be rehashed (e.g. after security
     * parameter changes). Returns true when the hash cannot be parsed.
     */
    public static function needsRehash(#[\SensitiveParameter] string $hash): bool
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

    /**
     * Compute a BLAKE2b hash of the given data.
     *
     * Pass `null` (or omit the key) for an unkeyed digest. An empty key is rejected.
     *
     * @param string      $data   The data to hash.
     * @param string|null $key    Optional 16-64 byte key for keyed hashing.
     * @param int         $length Output length in bytes (16-64, default 32).
     * @return string Hex-encoded hash.
     * @throws CryptographyException If the key is present but not 16-64 bytes,
     *                               or $length is outside 16-64.
     */
    public static function hash(
        #[\SensitiveParameter] string $data,
        #[\SensitiveParameter] ?string $key = null,
        int $length = SODIUM_CRYPTO_GENERICHASH_BYTES,
    ): string {
        self::validateHashKey($key);

        try {
            $hash = sodium_crypto_generichash($data, $key ?? '', $length);
        } catch (\SodiumException $e) {
            throw CryptographyException::hashFailed('BLAKE2b hashing failed.', $e);
        }

        return sodium_bin2hex($hash);
    }

    /**
     * Compute a BLAKE2b hash of a file's contents, read in 8 KB chunks.
     *
     * Pass `null` (or omit the key) for an unkeyed digest. An empty key is rejected.
     *
     * @param string      $path   Absolute path to the file.
     * @param string|null $key    Optional 16-64 byte key for keyed hashing.
     * @param int         $length Output length in bytes (16-64, default 32).
     * @return string Hex-encoded hash.
     * @throws CryptographyException If the key is invalid, the file is missing or
     *                               unreadable, or hashing fails.
     */
    public static function hashFile(
        string $path,
        #[\SensitiveParameter] ?string $key = null,
        int $length = SODIUM_CRYPTO_GENERICHASH_BYTES,
    ): string {
        self::validateHashKey($key);

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

    /**
     * Generate a cryptographically secure random string.
     *
     * Uses a URL-safe base64 alphabet (A-Z, a-z, 0-9, -, _).
     *
     * @param int $length Desired string length in characters.
     * @return string Random URL-safe string of the requested length.
     * @throws CryptographyException If $length is below 1.
     */
    public static function randomString(int $length): string
    {
        if ($length < 1) {
            throw CryptographyException::invalidArgument('Length must be at least 1.');
        }

        $bytesNeeded = (int) ceil($length * 3 / 4) + 1;
        $raw = random_bytes($bytesNeeded);

        return substr(self::base64urlEncode($raw), 0, $length);
    }

    /**
     * Generate cryptographically secure random bytes.
     *
     * @param int $length Number of bytes.
     * @return string Raw random bytes.
     * @throws CryptographyException If $length is below 1.
     */
    public static function randomBytes(int $length): string
    {
        if ($length < 1) {
            throw CryptographyException::invalidArgument('Length must be at least 1.');
        }

        return random_bytes($length);
    }

    /**
     * Generate a cryptographically secure random hex string.
     *
     * @param int $length Number of hex characters (must be even for full bytes).
     * @return string Hex string.
     * @throws CryptographyException If $length is below 1.
     */
    public static function randomHex(int $length): string
    {
        if ($length < 1) {
            throw CryptographyException::invalidArgument('Length must be at least 1.');
        }

        $bytesNeeded = (int) ceil($length / 2);
        return substr(bin2hex(random_bytes($bytesNeeded)), 0, $length);
    }

    /**
     * Generate a cryptographically secure random integer in the given range.
     * A $min greater than $max raises a ValueError.
     */
    public static function randomInt(int $min, int $max): int
    {
        return random_int($min, $max);
    }

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
    public static function encodeKey(#[\SensitiveParameter] string $key): string
    {
        return self::base64urlEncode($key);
    }

    /**
     * Decode a base64url-encoded encryption key to raw bytes.
     *
     * The decoded bytes are validated as an encryption key, so an empty value is refused.
     *
     * @throws CryptographyException If the value is not base64url, or does not
     *                               decode to a valid 32-byte encryption key.
     */
    public static function decodeKey(#[\SensitiveParameter] string $encoded): string
    {
        $key = self::base64urlDecode($encoded);
        if ($key === false) {
            throw CryptographyException::invalidKey('Unable to decode key (invalid base64url).');
        }

        self::validateEncryptionKey($key);

        return $key;
    }

    /**
     * Rejects wrong lengths and the all-zero key, which a configuration mistake produces.
     *
     * The zero-key check uses hash_equals, not ===, so it is constant-time.
     */
    private static function validateEncryptionKey(#[\SensitiveParameter] string $key): void
    {
        if (strlen($key) !== self::ENCRYPTION_KEY_BYTES) {
            throw CryptographyException::invalidKey(sprintf(
                'Key must be exactly %d bytes, got %d.',
                self::ENCRYPTION_KEY_BYTES,
                strlen($key),
            ));
        }

        if (hash_equals(str_repeat("\0", self::ENCRYPTION_KEY_BYTES), $key)) {
            throw CryptographyException::invalidKey(
                'The all-zero key is refused; it is what an unset or mis-decoded configuration value produces.',
            );
        }
    }

    /**
     * An empty key is refused: libsodium reads it as no key, which would return an
     * unkeyed digest that looks like a MAC. `null` remains the explicit unkeyed choice.
     */
    private static function validateHashKey(#[\SensitiveParameter] ?string $key): void
    {
        if ($key === null) {
            return;
        }

        $length = strlen($key);
        if ($length < SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN || $length > SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX) {
            throw CryptographyException::invalidKey(sprintf(
                'Hash key must be between %d and %d bytes, got %d. Pass null for an unkeyed hash.',
                SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MIN,
                SODIUM_CRYPTO_GENERICHASH_KEYBYTES_MAX,
                $length,
            ));
        }
    }

    private static function base64urlEncode(#[\SensitiveParameter] string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(#[\SensitiveParameter] string $data): string|false
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int) (ceil(strlen($data) / 4) * 4), '=');
        return base64_decode($padded, true);
    }
}
