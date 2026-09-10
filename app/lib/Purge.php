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

        // The hashes this person is known by. Contact details are never stored
        // in the clear, so redaction works by recomputing the same hashes and
        // removing what they point at.
        $hashes = [];

        foreach ([
            [$email, 'normaliseEmail'],
            [$phone, 'normalisePhone'],
        ] as [$value, $normalise]) {
            if (is_string($value)) {
                $n = Hash::$normalise($value);
                if ($n !== null) {
                    $hashes[] = Hash::pii($tenantId, $n);
                }
            }
        }

        if ($shopifyCustomerId !== null) {
            $hashes[] = Hash::pii($tenantId, (string) $shopifyCustomerId);
        }

        // ---------------------------------------------------------------
        // Who is this, in our terms?
        // ---------------------------------------------------------------
        $persons = [];

        $find = $pdo->prepare(
            'SELECT person_id FROM identity_keys WHERE tenant_id = ? AND key_hash = ?'
        );

        foreach ($hashes as $hash) {
            $find->execute([$tenantId, $hash]);
            foreach ($find->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $persons[(int) $p] = true;
            }
        }

        $persons = array_keys($persons);

        // ---------------------------------------------------------------
        // The identity layer
        // ---------------------------------------------------------------
        $del = $pdo->prepare('DELETE FROM identity_keys WHERE tenant_id = ? AND key_hash = ?');
        foreach ($hashes as $hash) {
            $del->execute([$tenantId, $hash]);
            $deleted += $del->rowCount();
        }

        if ($shopifyCustomerId !== null) {
            $c = $pdo->prepare('DELETE FROM customers WHERE tenant_id = ? AND shopify_customer_id = ?');
            $c->execute([$tenantId, $shopifyCustomerId]);
            $deleted += $c->rowCount();
        }

        // ---------------------------------------------------------------
        // The orders keep their money and lose their owner.
        //
        // CLEARING THE HASHES IS THE PART THAT MATTERS. Removing the person
        // row while leaving email_hash and phone_hash on the orders does not
        // redact anything: those columns are precisely what identity
        // resolution reads, so the next run of the identity job rebuilds the
        // same customer, with the same orders in the same sequence, within the
        // hour. The deletion undoes itself.
        //
        // They are also personal data in their own right while the salt exists
        // — the same address always produces the same hash, so the person
        // remains findable by anyone who can guess or supply the address.
        // ---------------------------------------------------------------
        // Two condition sets, because the two tables do not carry the same
        // columns: abandoned_checkouts has no shopify_customer_id.
        $orderWhere = [];
        $orderArgs  = [$tenantId];
        $cartWhere  = [];
        $cartArgs   = [$tenantId];

        if ($shopifyCustomerId !== null) {
            $orderWhere[] = 'shopify_customer_id = ?';
            $orderArgs[]  = $shopifyCustomerId;
        }

        foreach ($hashes as $hash) {
            foreach (['email_hash = ?', 'phone_hash = ?'] as $clause) {
                $orderWhere[] = $clause;
                $orderArgs[]  = $hash;
                $cartWhere[]  = $clause;
                $cartArgs[]   = $hash;
            }
        }

        foreach ($persons as $personId) {
            $orderWhere[] = 'person_id = ?';
            $orderArgs[]  = $personId;
            $cartWhere[]  = 'person_id = ?';
            $cartArgs[]   = $personId;
        }

        if ($orderWhere !== []) {
            $upd = $pdo->prepare(
                'UPDATE orders
                    SET shopify_customer_id = NULL, person_id = NULL,
                        email_hash = NULL, phone_hash = NULL
                  WHERE tenant_id = ? AND (' . implode(' OR ', $orderWhere) . ')'
            );
            $upd->execute($orderArgs);
            $deleted += $upd->rowCount();
        }

        if ($cartWhere !== []) {
            // Abandoned checkouts are the most sensitive rows here: a cart
            // nobody completed, attached to somebody who has asked to be
            // forgotten.
            $cart = $pdo->prepare(
                'UPDATE abandoned_checkouts
                    SET person_id = NULL, email_hash = NULL, phone_hash = NULL
                  WHERE tenant_id = ? AND (' . implode(' OR ', $cartWhere) . ')'
            );
            $cart->execute($cartArgs);
            $deleted += $cart->rowCount();
        }
        // ---------------------------------------------------------------
        // Anything still pointing at the person
        // ---------------------------------------------------------------
        foreach ($persons as $personId) {
            // A browser attached to this person. Left in place it is a live
            // pointer at a deleted person, and a way back to their history.
            $v = $pdo->prepare(
                'UPDATE dim_visitor SET person_id = NULL WHERE tenant_id = ? AND person_id = ?'
            );
            $v->execute([$tenantId, $personId]);
            $deleted += $v->rowCount();

            $cst = $pdo->prepare(
                'UPDATE customers SET person_id = NULL WHERE tenant_id = ? AND person_id = ?'
            );
            $cst->execute([$tenantId, $personId]);
            $deleted += $cst->rowCount();

            // The merge log records that two identities were the same person.
            // That is a statement about them, so it goes too.
            $m = $pdo->prepare(
                'DELETE FROM person_merges
                  WHERE tenant_id = ? AND (survivor_id = ? OR absorbed_id = ?)'
            );
            $m->execute([$tenantId, $personId, $personId]);
            $deleted += $m->rowCount();

            $q = $pdo->prepare('DELETE FROM resequence_queue WHERE tenant_id = ? AND person_id = ?');
            $q->execute([$tenantId, $personId]);

            $p = $pdo->prepare('DELETE FROM persons WHERE tenant_id = ? AND person_id = ?');
            $p->execute([$tenantId, $personId]);
            $deleted += $p->rowCount();
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
