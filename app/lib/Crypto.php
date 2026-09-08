<?php
/**
 * Authenticated encryption for secrets held in the database.
 *
 * Currently used for Shopify Admin API tokens (tenants.admin_token_enc) and
 * for the client secret parked in oauth_state during the install handshake.
 *
 * AES-256-GCM, so a tampered ciphertext fails to decrypt rather than
 * returning corrupted plaintext. The key lives in secrets/master.key, mode
 * 0600, outside every document root, and never in the database - a database
 * dump alone must not yield usable tokens.
 *
 * Blob layout:  [12-byte IV][16-byte GCM tag][ciphertext]
 */

declare(strict_types=1);

final class Crypto
{
    private const CIPHER  = 'aes-256-gcm';
    private const IV_LEN  = 12;
    private const TAG_LEN = 16;

    private static ?string $key = null;

    public static function encrypt(string $plaintext): string
    {
        $iv  = random_bytes(self::IV_LEN);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LEN
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return $iv . $tag . $ciphertext;
    }

    public static function decrypt(string $blob): string
    {
        if (strlen($blob) < self::IV_LEN + self::TAG_LEN) {
            throw new RuntimeException('Ciphertext is too short to be valid.');
        }

        $iv         = substr($blob, 0, self::IV_LEN);
        $tag        = substr($blob, self::IV_LEN, self::TAG_LEN);
        $ciphertext = substr($blob, self::IV_LEN + self::TAG_LEN);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // Either the key changed or the stored bytes were altered. Both
            // are unrecoverable here, and both matter enough to stop for.
            throw new RuntimeException(
                'Decryption failed. Either secrets/master.key is not the key this '
                . 'value was encrypted with, or the stored ciphertext has been '
                . 'modified. Re-onboarding the store will issue a fresh token.'
            );
        }

        return $plaintext;
    }

    /**
     * True when a usable key is present. Lets callers degrade gracefully -
     * ingest, for instance, needs no key at all and should not fail because
     * OAuth has not been set up yet.
     */
    public static function available(): bool
    {
        try {
            self::key();
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        if (!extension_loaded('openssl')) {
            throw new RuntimeException('The openssl extension is required but not loaded.');
        }

        $file = Config::get('secrets.master_key_file');

        if (!is_file($file)) {
            throw new RuntimeException(
                "Encryption key not found: {$file}\nGenerate it with:  php bin/keygen.php"
            );
        }

        $raw = trim((string) file_get_contents($file));
        $key = base64_decode($raw, true);

        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(
                "Encryption key at {$file} is not 32 base64-encoded bytes.\n"
                . 'Do not hand-edit this file - regenerate it with bin/keygen.php, '
                . 'accepting that existing tokens become unreadable.'
            );
        }

        return self::$key = $key;
    }
}
