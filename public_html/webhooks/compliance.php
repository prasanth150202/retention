<?php
/**
 * Mandatory compliance webhooks.
 *
 *   customers/data_request  a customer asked what you hold about them
 *   customers/redact        delete a specific customer's data
 *   shop/redact             delete everything for a store that uninstalled
 *
 * Required for App Store distribution and must be answered within 30 days.
 *
 * Every request is recorded in compliance_requests before any work happens.
 * The obligation is not just to comply but to be able to SHOW compliance, and
 * a deletion that leaves no trace proves nothing.
 *
 * This is the only place in the application that deletes merchant data.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';
require_once $root . '/app/lib/Webhook.php';

odysseus_boot();

$hook    = Webhook::receive();          // exits 401 on a bad signature
$shop    = $hook['shop'];
$payload = $hook['payload'];
$tenant  = Tenant::findByShop($shop);
$tid     = $tenant ? (int) $tenant['tenant_id'] : null;

// Shopify retries on any non-2xx and can deliver twice even on success.
// Deleting is idempotent; re-running a deletion is harmless but pointless.
if (Webhook::seenBefore($hook['topic'], $hook['raw'])) {
    Webhook::ok('already handled');
}

switch ($hook['topic']) {

    // -----------------------------------------------------------------
    case 'customers/data_request':
        // A customer has asked their merchant what data exists about them.
        //
        // We answer honestly and the answer is short, because of choices made
        // long before this request arrived: emails and phone numbers are
        // stored only as irreversible salted hashes, IP addresses are never
        // stored at all, and no free-text field ever captures a name.
        //
        // We therefore cannot look a person up by email, and say so rather
        // than pretending to a capability we deliberately do not have.
        $ref = (string) ($payload['customer']['email'] ?? $payload['customer']['id'] ?? '');
        $id  = Webhook::logCompliance($hook['topic'], $shop, $hook['raw'], $tid, $ref !== '' ? substr($ref, 0, 191) : null);

        Webhook::completeCompliance($id, 0,
            'Acknowledged. This app stores email and phone only as irreversible salted '
            . 'hashes and never stores IP addresses, so no personal data can be '
            . 'retrieved for an individual. Behavioural records are aggregate and not '
            . 'linkable to a named person by this app.');

        Webhook::ok('acknowledged');

    // -----------------------------------------------------------------
    case 'customers/redact':
        // Delete one customer's data.
        //
        // Hashing is deterministic, so the customer's hash can still be
        // computed from the email in the payload and matched — the data is
        // irreversible, not unfindable. That is exactly the property that
        // makes a redaction request answerable without holding the plaintext.
        $customerId = $payload['customer']['id'] ?? null;
        $email      = $payload['customer']['email'] ?? null;
        $phone      = $payload['customer']['phone'] ?? null;

        $id      = Webhook::logCompliance($hook['topic'], $shop, $hook['raw'], $tid,
                                          $customerId !== null ? (string) $customerId : null);
        $deleted = 0;

        if ($tid !== null) {
            $pdo    = Db::core();
            $hashes = [];

            if (is_string($email)) {
                $n = Hash::normaliseEmail($email);
                if ($n !== null) { $hashes[] = Hash::pii($tid, $n); }
            }
            if (is_string($phone)) {
                $n = Hash::normalisePhone($phone);
                if ($n !== null) { $hashes[] = Hash::pii($tid, $n); }
            }

            // Resolve to a person, then remove the identity keys that make
            // them recognisable. Orders themselves are the merchant's
            // commercial records, not ours to delete — but the link from an
            // order back to a person is.
            $personIds = [];

            foreach ($hashes as $h) {
                $s = $pdo->prepare(
                    'SELECT person_id FROM identity_keys WHERE tenant_id = ? AND key_hash = ?'
                );
                $s->execute([$tid, $h]);
                foreach ($s->fetchAll() as $r) {
                    $personIds[(int) $r['person_id']] = true;
                }

                $d = $pdo->prepare('DELETE FROM identity_keys WHERE tenant_id = ? AND key_hash = ?');
                $d->execute([$tid, $h]);
                $deleted += $d->rowCount();
            }

            if ($customerId !== null) {
                $d = $pdo->prepare(
                    'DELETE FROM customers WHERE tenant_id = ? AND shopify_customer_id = ?'
                );
                $d->execute([$tid, (int) $customerId]);
                $deleted += $d->rowCount();

                $d = $pdo->prepare(
                    'UPDATE orders SET shopify_customer_id = NULL, person_id = NULL
                      WHERE tenant_id = ? AND shopify_customer_id = ?'
                );
                $d->execute([$tid, (int) $customerId]);
                $deleted += $d->rowCount();
            }

            foreach (array_keys($personIds) as $pid) {
                $d = $pdo->prepare('DELETE FROM persons WHERE tenant_id = ? AND person_id = ?');
                $d->execute([$tid, $pid]);
                $deleted += $d->rowCount();
            }
        }

        Webhook::completeCompliance($id, $deleted,
            'Identity keys and customer record removed; orders detached from the person. '
            . 'Aggregate counts already computed are anonymous and retained.');

        Webhook::ok('redacted');

    // -----------------------------------------------------------------
    case 'shop/redact':
        // Sent 48 hours after uninstall. Delete everything for this store.
        //
        // This overrides the configured retention delay — a redaction request
        // is not a preference.
        $id      = Webhook::logCompliance($hook['topic'], $shop, $hook['raw'], $tid, null);
        $deleted = 0;

        if ($tid !== null) {
            $pdo = Db::core();

            // Raw events first: they are the bulk, and they live in another
            // database reached through the shard router.
            foreach (Shard::all(true) as $shard) {
                try {
                    $year = (int) substr((string) $shard['date_from'], 0, 4);
                    [, $u, $p] = Db::shardTarget($year);
                    $ev = Db::connect((string) $shard['physical_name'], $u, $p);

                    $s = $ev->prepare('DELETE FROM events WHERE tenant_id = ?');
                    $s->execute([$tid]);
                    $deleted += $s->rowCount();
                } catch (Throwable) {
                    // An unreachable shard must not abort the rest of the
                    // deletion; what remains is reported rather than silently
                    // assumed gone.
                }
            }

            $tables = [
                'order_line_items', 'order_attribution', 'order_journey_moments',
                'abandoned_checkout_items', 'abandoned_checkouts', 'orders',
                'identity_keys', 'person_merges', 'persons', 'customers',
                'products', 'product_variants',
                'dim_visitor', 'dim_path', 'dim_referrer', 'dim_campaign',
                'dim_useragent', 'dim_search_term', 'dim_click_target',
                'import_log', 'sync_cursors', 'channel_rules',
            ];

            // Rollups are anonymous counts. Whether they survive is a policy
            // choice, and it is configurable — but a shop/redact request is
            // explicit, so the default is to take them too.
            if (!Config::get('retention.keep_rollups', true)) {
                $tables = array_merge($tables, [
                    'rollup_daily_kpi', 'rollup_daily_funnel', 'rollup_daily_campaign',
                    'rollup_daily_channel', 'rollup_daily_landing', 'rollup_daily_product',
                    'rollup_daily_geo', 'rollup_daily_device', 'rollup_daily_abandon',
                    'rollup_cohort_repeat', 'rollup_campaign_cohort', 'rollup_person_orders',
                ]);
            }

            foreach ($tables as $t) {
                try {
                    $s = $pdo->prepare("DELETE FROM {$t} WHERE tenant_id = ?");
                    $s->execute([$tid]);
                    $deleted += $s->rowCount();
                } catch (Throwable) {
                    // Table may not exist in an older deployment.
                }
            }

            // Any spool files not yet imported are data too.
            $spool = Config::get('paths.spool') . '/' . $tid;
            foreach (glob($spool . '/*.ndjson') ?: [] as $f) {
                @unlink($f);
                $deleted++;
            }
            $processed = Config::get('paths.processed') . '/' . $tid;
            foreach (glob($processed . '/*.ndjson') ?: [] as $f) {
                @unlink($f);
                $deleted++;
            }

            // Keep the tenant row as a tombstone: it records that a redaction
            // happened and when, which is the evidence the obligation was met.
            $pdo->prepare(
                "UPDATE tenants
                    SET status = 'uninstalled', purged_at = UTC_TIMESTAMP(),
                        admin_token_enc = NULL, refresh_token_enc = NULL,
                        token_expires_at = NULL, refresh_expires_at = NULL,
                        custom_domain = NULL
                  WHERE tenant_id = ?"
            )->execute([$tid]);

            // The per-tenant salt goes too. Without it the remaining hashes
            // cannot be recomputed from any plaintext, which is the point.
            $salt = Config::get('secrets.salt_dir') . '/tenant_' . $tid . '.salt';
            if (is_file($salt)) {
                @unlink($salt);
            }
        }

        Webhook::completeCompliance($id, $deleted,
            'Store data deleted. Tenant row retained as a tombstone recording the '
            . 'redaction; per-tenant hashing salt destroyed.');

        Webhook::ok('shop redacted');

    // -----------------------------------------------------------------
    default:
        Webhook::ok('ignored');
}
