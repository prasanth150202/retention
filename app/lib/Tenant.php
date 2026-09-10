<?php
/**
 * A connected store, and everything about its access token.
 *
 * Token handling lives here rather than in ShopifyApi because it is
 * persistence, not API mechanics — and because the dashboard, the webhooks
 * and the sync job all need the same answer to "give me a working token for
 * this store" without each implementing refresh.
 *
 * PUBLIC APPS CANNOT USE NON-EXPIRING TOKENS.
 * Access tokens last about an hour and carry a refresh token valid 90 days.
 * accessToken() therefore refreshes transparently: callers never see an
 * expired token, and never have to think about it. Getting this wrong shows
 * up as every API call failing exactly one hour after a successful install.
 */

declare(strict_types=1);

final class Tenant
{
    /** Refresh this many seconds before actual expiry, to absorb clock skew. */
    private const REFRESH_MARGIN = 300;

    /** @var array<int,array<string,mixed>> */
    private static array $cache = [];

    /** @return array<string,mixed>|null */
    public static function find(int $tenantId): ?array
    {
        if (isset(self::$cache[$tenantId])) {
            return self::$cache[$tenantId];
        }

        $stmt = Db::core()->prepare('SELECT * FROM tenants WHERE tenant_id = ?');
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();

        return $row ? (self::$cache[$tenantId] = $row) : null;
    }

    /** @return array<string,mixed>|null */
    public static function findByShop(string $shopDomain): ?array
    {
        $stmt = Db::core()->prepare('SELECT * FROM tenants WHERE shop_domain = ?');
        $stmt->execute([strtolower(trim($shopDomain))]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Create the store if new, or reactivate it if it is reinstalling.
     *
     * A reinstall keeps the existing tenant_id, and therefore its history.
     * Treating it as a new store would orphan every event and order already
     * collected — and merchants who uninstall and reinstall within days are
     * common on the App Store.
     */
    public static function upsert(string $shopDomain, array $tokens, string $scopes): int
    {
        $shop = strtolower(trim($shopDomain));
        $pdo  = Db::core();

        $existing = self::findByShop($shop);

        if ($existing) {
            $id = (int) $existing['tenant_id'];

            $pdo->prepare(
                "UPDATE tenants
                    SET status = 'active',
                        token_scopes = ?,
                        uninstalled_at = NULL,
                        purge_after = NULL,
                        backfill_state = CASE WHEN backfill_state = 'done'
                                              THEN 'done' ELSE 'pending' END
                  WHERE tenant_id = ?"
            )->execute([$scopes, $id]);

            self::storeTokens($id, $tokens);
            unset(self::$cache[$id]);

            return $id;
        }

        require_once __DIR__ . '/Snippet.php';

        $pdo->prepare(
            "INSERT INTO tenants
                (shop_domain, display_name, write_key, pii_salt_ref, token_scopes,
                 currency, iana_timezone, status, installed_at)
             VALUES (?, ?, ?, 'tenant', ?, 'INR', 'Asia/Kolkata', 'active', UTC_TIMESTAMP())"
        )->execute([
            $shop,
            explode('.', $shop)[0],
            Snippet::generateWriteKey(),
            $scopes,
        ]);

        $id = (int) $pdo->lastInsertId();

        // Seed this store's channel rules from the shared defaults so
        // attribution works from the first order.
        $pdo->prepare(
            'INSERT IGNORE INTO channel_rules
                (tenant_id, priority, match_field, match_op, match_value, channel, enabled)
             SELECT ?, priority, match_field, match_op, match_value, channel, enabled
               FROM channel_rules WHERE tenant_id = 0'
        )->execute([$id]);

        self::storeTokens($id, $tokens);

        return $id;
    }

    /**
     * Persist a token set.
     *
     * @param array{access_token:string,refresh_token?:?string,expires_in?:?int,refresh_token_expires_in?:?int} $t
     */
    public static function storeTokens(int $tenantId, array $t): void
    {
        $accessExpires = isset($t['expires_in']) && $t['expires_in']
            ? gmdate('Y-m-d H:i:s', time() + (int) $t['expires_in'])
            : null;

        $refreshExpires = isset($t['refresh_token_expires_in']) && $t['refresh_token_expires_in']
            ? gmdate('Y-m-d H:i:s', time() + (int) $t['refresh_token_expires_in'])
            : null;

        Db::core()->prepare(
            'UPDATE tenants
                SET admin_token_enc   = ?,
                    refresh_token_enc = ?,
                    token_expires_at  = ?,
                    refresh_expires_at= ?
              WHERE tenant_id = ?'
        )->execute([
            Crypto::encrypt($t['access_token']),
            !empty($t['refresh_token']) ? Crypto::encrypt($t['refresh_token']) : null,
            $accessExpires,
            $refreshExpires,
            $tenantId,
        ]);

        unset(self::$cache[$tenantId]);
    }

    /**
     * A token that works right now.
     *
     * Refreshes when the stored one is within REFRESH_MARGIN of expiry. The
     * whole point is that no caller ever has to know tokens expire.
     */
    public static function accessToken(int $tenantId): string
    {
        $row = self::find($tenantId);

        if (!$row) {
            throw new RuntimeException("No such store: {$tenantId}");
        }
        if (empty($row['admin_token_enc'])) {
            throw new RuntimeException(
                "Store {$row['shop_domain']} has no Shopify token. It needs reinstalling."
            );
        }

        $expiresAt = $row['token_expires_at'] ?? null;

        // A non-expiring token (a store connected before the public app, or a
        // custom app) has no expiry and needs no refresh.
        if ($expiresAt === null) {
            return Crypto::decrypt((string) $row['admin_token_enc']);
        }

        if (strtotime($expiresAt . ' UTC') - time() > self::REFRESH_MARGIN) {
            return Crypto::decrypt((string) $row['admin_token_enc']);
        }

        return self::refresh($tenantId, $row);
    }

    /**
     * Exchange the refresh token for a new access token.
     *
     * @param array<string,mixed> $row
     */
    private static function refresh(int $tenantId, array $row): string
    {
        if (empty($row['refresh_token_enc'])) {
            throw new RuntimeException(
                "Store {$row['shop_domain']} has an expired token and no refresh token. "
                . 'The merchant must reinstall the app.'
            );
        }

        // The refresh token itself expires after 90 days. A store whose sync
        // has been broken that long cannot be recovered without the merchant.
        $refreshExpires = $row['refresh_expires_at'] ?? null;
        if ($refreshExpires !== null && strtotime($refreshExpires . ' UTC') < time()) {
            self::markNeedsReinstall($tenantId);
            throw new RuntimeException(
                "Store {$row['shop_domain']} has not been reachable for 90 days, so its "
                . 'refresh token has expired. The merchant must reinstall the app.'
            );
        }

        require_once __DIR__ . '/ShopifyOAuth.php';

        $tokens = ShopifyOAuth::refresh(
            (string) $row['shop_domain'],
            Crypto::decrypt((string) $row['refresh_token_enc'])
        );

        self::storeTokens($tenantId, $tokens);

        return $tokens['access_token'];
    }

    /** Mark a store as needing merchant action, without deleting anything. */
    public static function markNeedsReinstall(int $tenantId): void
    {
        Db::core()->prepare("UPDATE tenants SET status = 'paused' WHERE tenant_id = ?")
            ->execute([$tenantId]);
        unset(self::$cache[$tenantId]);
    }

    /**
     * Record an uninstall.
     *
     * The row survives. A merchant who reinstalls should keep their history,
     * and there needs to be an audit trail if a redaction is ever questioned.
     * purge_after sets the clock for deleting raw events; a shop/redact
     * request overrides it and deletes at once.
     */
    public static function markUninstalled(string $shopDomain): ?int
    {
        $row = self::findByShop($shopDomain);
        if (!$row) {
            return null;
        }

        $id   = (int) $row['tenant_id'];
        $days = (int) Config::get('retention.uninstall_purge_days', 0);

        Db::core()->prepare(
            "UPDATE tenants
                SET status = 'uninstalled',
                    uninstalled_at = UTC_TIMESTAMP(),
                    purge_after = UTC_TIMESTAMP() + INTERVAL ? DAY,
                    admin_token_enc = NULL,
                    refresh_token_enc = NULL,
                    token_expires_at = NULL,
                    refresh_expires_at = NULL
              WHERE tenant_id = ?"
        )->execute([$days, $id]);

        // The token is revoked the moment an app is uninstalled, so keeping
        // the ciphertext serves no purpose and is one more thing to leak.
        unset(self::$cache[$id]);

        return $id;
    }

    /**
     * Rewrite the write-key map that the ingest endpoint reads.
     *
     * c.php resolves a write key to a tenant from a generated PHP file rather
     * than a query, to keep the hot path off the database entirely. On a key
     * it does not recognise it will rebuild — but not if it rebuilt in the
     * last minute, because otherwise anyone posting random keys would force a
     * query and a file write per request.
     *
     * That leaves one gap worth closing: a store onboarded during that minute
     * has its first events rejected, and the pixel uses sendBeacon, which
     * cannot retry. Calling this the moment a store is installed removes the
     * gap at the only point where a new write key comes into existence.
     *
     * Written to a temporary file and renamed, so a concurrent reader sees
     * either the old map or the new one and never a half-written file.
     */
    public static function refreshWriteKeyCache(): void
    {
        $map = [];

        foreach (Db::core()->query(
            "SELECT tenant_id, write_key, shop_domain, custom_domain
               FROM tenants WHERE status = 'active'"
        )->fetchAll() as $r) {
            $map[(string) $r['write_key']] = [
                'id'      => (int) $r['tenant_id'],
                'domains' => array_values(array_filter([
                    strtolower((string) $r['shop_domain']),
                    strtolower((string) ($r['custom_domain'] ?? '')),
                ])),
            ];
        }

        $file = Config::get('paths.storage') . '/tenants.php';
        $tmp  = $file . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, '<?php return ' . var_export($map, true) . ";\n", LOCK_EX) !== false) {
            @rename($tmp, $file);
        }
    }

    public static function forgetCache(): void
    {
        self::$cache = [];
    }
}
