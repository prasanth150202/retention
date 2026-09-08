<?php
/**
 * Hashing and normalisation.
 *
 * Two distinct jobs:
 *
 *  1. PII digests. Email and phone are never stored in readable form. They
 *     are normalised, then HMAC'd with a per-tenant salt and truncated to 16
 *     bytes. HMAC rather than plain SHA-256 so a stolen database cannot be
 *     rainbow-tabled back into phone numbers; a per-tenant salt so the same
 *     person cannot be correlated across two clients' data.
 *
 *     The salts live in secrets/salts/ and are NOT in the database. A dump of
 *     the database alone therefore yields nothing.
 *
 *  2. Compact surrogate keys. Long identifiers (Shopify event ids, checkout
 *     tokens) are reduced to 63-bit integers so they cost 8 bytes in the
 *     events row rather than 32-64. See the row-size budget in section 6.2.
 */

declare(strict_types=1);

final class Hash
{
    private static array $saltCache = [];

    // -----------------------------------------------------------------
    // Normalisation
    // -----------------------------------------------------------------

    /**
     * Indian mobile numbers to a bare 10 digits.
     *
     * Strips non-digits, drops a leading 91 country code when what remains is
     * 10 digits, and rejects anything shorter. Returns null when the input
     * cannot be trusted to identify a person, because a bad phone key merges
     * two unrelated customers.
     */
    public static function normalisePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        // Drop the country code only if a valid 10-digit number remains.
        if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) > 10 && str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    /**
     * Email to a comparable form: trim and lowercase, nothing else.
     *
     * Deliberately does NOT strip Gmail dots or plus-addressing. Both are
     * guesses about a provider's routing rules, and a wrong guess silently
     * merges two different people into one customer.
     */
    public static function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = strtolower(trim($email));

        return ($email !== '' && str_contains($email, '@')) ? $email : null;
    }

    // -----------------------------------------------------------------
    // PII digests
    // -----------------------------------------------------------------

    /**
     * 16-byte identity key for identity_keys.key_hash.
     *
     * Deterministic for a given tenant, so matching works; irreversible, so a
     * breach exposes nothing.
     */
    public static function pii(int $tenantId, string $value): string
    {
        return substr(
            hash_hmac('sha256', $value, self::salt($tenantId), true),
            0,
            16
        );
    }

    /**
     * Per-tenant salt, created on first use and then permanent.
     *
     * Regenerating a salt orphans every existing hash for that tenant, so the
     * file is written once and never overwritten. If it exists but cannot be
     * read we fail loudly rather than silently generating a new one and
     * quietly breaking every identity match made so far.
     */
    public static function salt(int $tenantId): string
    {
        if (isset(self::$saltCache[$tenantId])) {
            return self::$saltCache[$tenantId];
        }

        $dir  = Config::get('secrets.salt_dir');
        $file = $dir . '/tenant_' . $tenantId . '.salt';

        if (is_file($file)) {
            $raw = file_get_contents($file);
            if ($raw === false || strlen(trim($raw)) === 0) {
                throw new RuntimeException(
                    "Salt file for tenant {$tenantId} exists but could not be read: {$file}\n"
                    . "Refusing to generate a replacement - that would orphan every "
                    . "identity hash already stored for this tenant."
                );
            }
            return self::$saltCache[$tenantId] = trim($raw);
        }

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create salt directory: {$dir}");
        }

        $salt = bin2hex(random_bytes(32));

        // 'x' fails if another process created the file first, so two
        // concurrent imports cannot end up with different salts.
        $fh = @fopen($file, 'x');
        if ($fh === false) {
            return self::salt($tenantId);   // someone else won; read theirs
        }
        fwrite($fh, $salt);
        fclose($fh);
        @chmod($file, 0600);

        return self::$saltCache[$tenantId] = $salt;
    }

    // -----------------------------------------------------------------
    // Surrogate keys
    // -----------------------------------------------------------------

    /**
     * 63-bit positive integer from an arbitrary string.
     *
     * Used for events.event_uid and events.checkout_token. 63 bits rather
     * than 64 so the value is always positive in PHP's signed int and still
     * fits MySQL's BIGINT UNSIGNED. At ~20M rows the collision probability is
     * around 1e-8, and a collision costs one dropped duplicate - benign.
     */
    public static function uid64(string $value): int
    {
        $parts = unpack('J', hash('sha256', $value, true));
        return $parts[1] & PHP_INT_MAX;
    }

    /**
     * 16-byte digest for dimension uniqueness keys (dim_path.path_hash etc).
     *
     * Not PII, so no salt: dimension values are shared, non-identifying
     * strings like URL paths and user agents, and an unsalted digest lets the
     * same value collapse to one row.
     */
    public static function dim(string $value): string
    {
        return substr(hash('sha256', $value, true), 0, 16);
    }

    /**
     * Digest for a visitor id (_shopify_y / pixel clientId).
     *
     * Salted per tenant: a visitor id is a device identifier, so it is
     * personal data even though it carries no name.
     */
    public static function visitor(int $tenantId, string $clientId): string
    {
        return self::pii($tenantId, 'visitor:' . $clientId);
    }
}
