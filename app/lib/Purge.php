<?php
/**
 * Deletion of merchant data.
 *
 * The only place in the application that removes data, reached from two
 * directions:
 *
 *   shop/redact          Shopify asks, 48 hours after an uninstall
 *   app/cron/purge.php   the retention clock runs out
 *
 * Both must delete exactly the same things. Two implementations would drift,
 * and the way that drift shows up is data quietly surviving a redaction —
 * which is the one failure here with legal consequences rather than merely
 * operational ones.
 *
 * Deletion is deliberately partial in one respect: the tenant row survives as
 * a tombstone. It records that a redaction happened and when, which is the
 * evidence the obligation was met, and it means a merchant who reinstalls is
 * not treated as a brand new store.
 */

declare(strict_types=1);

final class Purge
{
    /**
     * Core tables holding per-store data, in dependency order.
     *
     * Rollups are deliberately absent — they are anonymous counts and whether
     * they survive is a policy choice, handled separately below.
     */
    private const TENANT_TABLES = [
        'order_line_items', 'order_attribution', 'order_journey_moments',
        'abandoned_checkout_items', 'abandoned_checkouts', 'orders',
        'identity_keys', 'person_merges', 'persons', 'customers',
        'products', 'product_variants',
        'dim_visitor', 'dim_path', 'dim_referrer', 'dim_campaign',
        'dim_useragent', 'dim_search_term', 'dim_click_target',
        'import_log', 'sync_cursors', 'channel_rules',
    ];

    private const ROLLUP_TABLES = [
        'rollup_daily_kpi', 'rollup_daily_funnel', 'rollup_daily_campaign',
        'rollup_daily_channel', 'rollup_daily_landing', 'rollup_daily_product',
        'rollup_daily_geo', 'rollup_daily_device', 'rollup_daily_abandon',
        'rollup_cohort_repeat', 'rollup_campaign_cohort', 'rollup_person_orders',
    ];

    /**
     * Delete everything for one store.
     *
     * @param bool|null $includeRollups null follows the configured policy;
     *                                  true or false overrides it, which is
     *                                  what a shop/redact request does.
     * @return array{events:int,core:int,files:int,shards_failed:string[]}
     */
    public static function store(int $tenantId, ?bool $includeRollups = null): array
    {
        $keepRollups = $includeRollups === null
            ? (bool) Config::get('retention.keep_rollups', true)
            : !$includeRollups;

        $result = ['events' => 0, 'core' => 0, 'files' => 0, 'shards_failed' => []];

        // --- raw events, across every shard ---------------------------
        //
        // An unreachable shard must not abort the rest of the deletion: what
        // could not be reached is reported rather than silently assumed gone,
        // so it can be retried rather than forgotten.
        foreach (Shard::all(true) as $shard) {
            try {
                $year = (int) substr((string) $shard['date_from'], 0, 4);
                [, $user, $pass] = Db::shardTarget($year);
                $pdo = Db::connect((string) $shard['physical_name'], $user, $pass);

                $stmt = $pdo->prepare('DELETE FROM events WHERE tenant_id = ?');
                $stmt->execute([$tenantId]);
                $result['events'] += $stmt->rowCount();
            } catch (Throwable) {
                $result['shards_failed'][] = (string) $shard['physical_name'];
            }
        }

        // --- core tables ----------------------------------------------
        $tables = self::TENANT_TABLES;
        if (!$keepRollups) {
            $tables = array_merge($tables, self::ROLLUP_TABLES);
        }

        $pdo = Db::core();

        foreach ($tables as $table) {
            try {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE tenant_id = ?");
                $stmt->execute([$tenantId]);
                $result['core'] += $stmt->rowCount();
            } catch (Throwable) {
                // Table absent in an older deployment; not a reason to stop.
            }
        }

        // --- spooled files --------------------------------------------
        // Events accepted but never imported are data too, and they are the
        // easiest to forget because they are not in any database.
        foreach (['paths.spool', 'paths.processed', 'paths.failed'] as $key) {
            $dir = Config::get($key) . '/' . $tenantId;
            foreach (glob($dir . '/*.ndjson') ?: [] as $file) {
                if (@unlink($file)) {
                    $result['files']++;
                }
            }
            @rmdir($dir);
        }

        // --- the hashing salt -----------------------------------------
        // Hashes are irreversible but deterministic: with the salt, a known
        // email can still be matched against what remains. Destroying it is
        // what makes the remaining data unlinkable to a person.
        $salt = Config::get('secrets.salt_dir') . '/tenant_' . $tenantId . '.salt';
        if (is_file($salt)) {
            @unlink($salt);
            $result['files']++;
        }

        // --- tombstone -------------------------------------------------
        $pdo->prepare(
            "UPDATE tenants
                SET status = 'uninstalled',
                    purged_at = UTC_TIMESTAMP(),
                    admin_token_enc = NULL, refresh_token_enc = NULL,
                    token_expires_at = NULL, refresh_expires_at = NULL,
                    custom_domain = NULL
              WHERE tenant_id = ?"
        )->execute([$tenantId]);

        Tenant::forgetCache();

        return $result;
    }

    /**
     * Delete one customer's data within a store.
     *
     * Hashing is deterministic, so a customer is still findable from the email
     * or phone in a redaction request — the data is irreversible, not
     * unfindable. That is precisely the property that makes this answerable
     * without ever holding the plaintext.
     *
     * Orders are NOT deleted: they are the merchant's commercial records and
     * their own legal obligation. What is removed is everything that makes an
     * order attributable to a person.
     *
     * @return array{deleted:int,persons:int}
     */
    public static function customer(
        int $tenantId,
        ?int $shopifyCustomerId,
        ?string $email,
        ?string $phone
    ): array {
        $pdo     = Db::core();
        $deleted = 0;
        $persons = [];

        $hashes = [];

        if (is_string($email)) {
            $n = Hash::normaliseEmail($email);
            if ($n !== null) {
                $hashes[] = Hash::pii($tenantId, $n);
            }
        }
        if (is_string($phone)) {
            $n = Hash::normalisePhone($phone);
            if ($n !== null) {
                $hashes[] = Hash::pii($tenantId, $n);
            }
        }
        if ($shopifyCustomerId !== null) {
            $hashes[] = Hash::pii($tenantId, (string) $shopifyCustomerId);
        }

        foreach ($hashes as $hash) {
            $find = $pdo->prepare(
                'SELECT person_id FROM identity_keys WHERE tenant_id = ? AND key_hash = ?'
            );
            $find->execute([$tenantId, $hash]);
            foreach ($find->fetchAll() as $row) {
                $persons[(int) $row['person_id']] = true;
            }

            $del = $pdo->prepare('DELETE FROM identity_keys WHERE tenant_id = ? AND key_hash = ?');
            $del->execute([$tenantId, $hash]);
            $deleted += $del->rowCount();
        }

        if ($shopifyCustomerId !== null) {
            $del = $pdo->prepare(
                'DELETE FROM customers WHERE tenant_id = ? AND shopify_customer_id = ?'
            );
            $del->execute([$tenantId, $shopifyCustomerId]);
            $deleted += $del->rowCount();

            $upd = $pdo->prepare(
                'UPDATE orders SET shopify_customer_id = NULL, person_id = NULL
                  WHERE tenant_id = ? AND shopify_customer_id = ?'
            );
            $upd->execute([$tenantId, $shopifyCustomerId]);
            $deleted += $upd->rowCount();
        }

        foreach (array_keys($persons) as $personId) {
            $upd = $pdo->prepare(
                'UPDATE orders SET person_id = NULL WHERE tenant_id = ? AND person_id = ?'
            );
            $upd->execute([$tenantId, $personId]);
            $deleted += $upd->rowCount();

            $del = $pdo->prepare('DELETE FROM persons WHERE tenant_id = ? AND person_id = ?');
            $del->execute([$tenantId, $personId]);
            $deleted += $del->rowCount();
        }

        return ['deleted' => $deleted, 'persons' => count($persons)];
    }

    /**
     * Stores whose retention clock has run out.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function due(): array
    {
        return Db::core()->query(
            "SELECT tenant_id, shop_domain, uninstalled_at, purge_after
               FROM tenants
              WHERE status = 'uninstalled'
                AND purge_after IS NOT NULL
                AND purge_after <= UTC_TIMESTAMP()
                AND purged_at IS NULL"
        )->fetchAll();
    }
}
