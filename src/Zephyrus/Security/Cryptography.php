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
 * ## Context binding
 *
 * `encrypt()`/`decrypt()` take an optional `$context` passed to the AEAD as
 * additional authenticated data. It is not secret and is not stored in the
 * ciphertext; it only has to be reproduced verbatim at decryption time. Binding
 * a ciphertext to where it lives (tenant, table, column, row) makes a
 * transplanted ciphertext fail to open instead of decrypting cleanly somewhere
 * it was never written.
 *
 * The default is the empty string, which is byte-for-byte what the AEAD received
 * before the parameter existed, so ciphertext already in a database keeps
 * opening. Adopting a context on a column that already holds data means
 * decrypting with the old context and re-encrypting with the new one. There is
 * no in-place upgrade.
 *
 * ## Key requirements
 *
 * - Encryption key: exactly 32 bytes (use `generateEncryptionKey()` to generate)
 *   and not the all-zero key, which is what an unset or mis-decoded configuration
 *   value produces.
 * - BLAKE2b key: `null` for an unkeyed hash, otherwise 16-64 bytes. An empty
 *   string is REJECTED rather than read as "unkeyed": libsodium treats an empty
 *   key as no key at all, so accepting it would return a public digest that is
 *   indistinguishable from a MAC and forgeable by anyone holding the message.
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
     * @param string $context   Optional additional authenticated data binding the
     *                          ciphertext to its location, e.g.
     *                          "tenant:42|table:client|column:ssn". Must be
     *                          reproduced verbatim by `decrypt()`.
     * @return string Base64url-encoded ciphertext (nonce prepended).
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
                $context, // Additional authenticated data (AAD).
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
     * @param string $context The same context passed to `encrypt()`. A mismatch
     *                        fails the Poly1305 tag check exactly like tampering,
     *                        so a ciphertext moved to another column or another
     *                        tenant does not open.
     * @return string The decrypted plaintext.
     * @throws CryptographyException If decryption fails (wrong key, wrong context,
     *                               corrupted data).
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
                $context, // AAD
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
     * WARNING: THE PEPPER IS CONCATENATED WITH NO DELIMITER, so the boundary between
     * pepper and password is not recoverable and pairs collide:
     * hashPassword('B', 'A') verifies against verifyPassword('AB', $hash, null),
     * and hashPassword('BC', 'A') verifies against verifyPassword('C', $hash, 'AB').
     * The construction is LEFT AS IS ON PURPOSE. Any delimiter, length prefix or
     * HMAC would change the hashed input and invalidate every peppered hash
     * already stored, which is a password reset for every account that has one.
     * A caller who needs the boundary should pepper with a fixed-length value
     * (e.g. 32 raw bytes) so no other pair can produce the same concatenation.
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
     * parameter changes).
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

    // ─── Hashing (BLAKE2b) ────────────────────────────────────────────

    /**
     * Compute a BLAKE2b hash of the given data.
     *
     * Pass `null` (or omit the key) for an unkeyed digest. An empty string is
     * rejected: see `validateHashKey()`.
     *
     * @param string      $data   The data to hash.
     * @param string|null $key    Optional 16-64 byte key for keyed hashing.
     * @param int         $length Output length in bytes (16-64, default 32).
     * @return string Hex-encoded hash.
     * @throws CryptographyException If the key is present but not 16-64 bytes.
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
     * Compute a BLAKE2b hash of a file's contents.
     *
     * Reads the file in chunks for memory efficiency on large files.
     *
     * Pass `null` (or omit the key) for an unkeyed digest. An empty string is
     * rejected: see `validateHashKey()`.
     *
     * @param string      $path   Absolute path to the file.
     * @param string|null $key    Optional 16-64 byte key for keyed hashing.
     * @param int         $length Output length in bytes (default 32).
     * @return string Hex-encoded hash.
     * @throws CryptographyException If the key is present but not 16-64 bytes.
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
            throw CryptographyException::invalidArgument('Length must be at least 1.');
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
            throw CryptographyException::invalidArgument('Length must be at least 1.');
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
            throw CryptographyException::invalidArgument('Length must be at least 1.');
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
    public static function encodeKey(#[\SensitiveParameter] string $key): string
    {
        return self::base64urlEncode($key);
    }

    /**
     * Decode a base64url-encoded encryption key back to raw bytes.
     *
     * The decoded value is validated as an encryption key. Without that check
     * decodeKey('') returned zero bytes and every caller downstream believed it
     * held a key, which is the shortest route from an unset environment variable
     * to a keyed operation carrying no key at all.
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

    // ─── Internal Helpers ─────────────────────────────────────────────

    /**
     * The all-zero key is rejected alongside the wrong lengths. It is not weaker
     * than any other 32 bytes on its own, but it is the value a configuration
     * mistake produces (an unset variable decoded into a zero-filled buffer, a
     * str_repeat placeholder), and a mistake that boots normally and encrypts
     * real data is worth refusing at the door.
     *
     * hash_equals, not ===, so the comparison does not leak how many leading
     * bytes of a key happened to be zero.
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
     * A BLAKE2b key is either absent or a real key, never an empty string.
     *
     * sodium_crypto_generichash() reads an empty key as "no key", so
     * hash($data, '') returned the plain unkeyed digest of $data: a value that
     * looks exactly like a MAC, passes any length or hex check, and can be
     * recomputed by anyone who knows the message. Every OTHER invalid length was
     * already rejected loudly by libsodium, so the single input that switched
     * authentication off was the single input that was accepted.
     *
     * null keeps its meaning of "unkeyed on purpose" and is untouched.
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
