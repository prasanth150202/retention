<?php
/**
 * App lifecycle webhooks: app/uninstalled and app/scopes_update.
 *
 * Declared in shopify/shopify.app.toml, so Shopify registers them on deploy.
 *
 * Reached without any session — the merchant is not present. Everything here
 * is authorised by the body signature and nothing else.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';
require_once $root . '/app/lib/Webhook.php';

odysseus_boot();

$hook = Webhook::receive();   // exits 401 on a bad signature

switch ($hook['topic']) {

    case 'app/uninstalled':
        // Shopify revokes the access token the instant an app is uninstalled,
        // so there is nothing to revoke on our side — only state to record.
        //
        // The tenant row survives. A merchant who reinstalls keeps their
        // history rather than appearing as a brand new store, and there needs
        // to be an audit trail if a deletion is ever questioned.
        $tenantId = Tenant::markUninstalled($hook['shop']);

        if ($tenantId !== null) {
            Db::core()->prepare(
                "INSERT INTO job_runs (job_name, tenant_id, started_at, finished_at, status, message)
                 VALUES ('app_uninstalled', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'ok', ?)"
            )->execute([$tenantId, 'Uninstalled by merchant; purge scheduled']);
        }

        Webhook::ok('uninstall recorded');

    case 'app_subscriptions/update':
        // Shopify is the authority on subscription state; this is only how it
        // tells us promptly. The periodic re-read in the sync job is the
        // backstop for a webhook that never lands.
        $tenantId = Billing::applyWebhook($hook['shop'], $hook['payload']);

        Webhook::ok($tenantId === null ? 'unknown shop' : 'subscription state recorded');

    case 'app/scopes_update':
        // Fired when granted scopes change — usually because we added one and
        // the merchant approved it on next open. Worth recording, because
        // read_all_orders arriving late is exactly the event that unlocks
        // historical backfill.
        $granted = (string) ($hook['payload']['current'][0] ?? '');
        if (is_array($hook['payload']['current'] ?? null)) {
            $granted = implode(',', $hook['payload']['current']);
        }

        $tenant = Tenant::findByShop($hook['shop']);

        if ($tenant) {
            $before = ShopifyOAuth::verifyScopes((string) ($tenant['token_scopes'] ?? ''));
            $after  = ShopifyOAuth::verifyScopes($granted);

            Db::core()->prepare('UPDATE tenants SET token_scopes = ? WHERE tenant_id = ?')
                ->execute([$granted, (int) $tenant['tenant_id']]);

            // read_all_orders newly granted means the full order history just
            // became reachable. Re-queue the backfill rather than waiting for
            // somebody to notice the cohort charts are still empty.
            if ($before['history_limited'] && !$after['history_limited']) {
                Db::core()->prepare(
                    "UPDATE tenants SET backfill_state = 'pending' WHERE tenant_id = ?"
                )->execute([(int) $tenant['tenant_id']]);

                Db::core()->prepare(
                    'UPDATE sync_cursors SET watermark_at = NULL, cursor_token = NULL
                      WHERE tenant_id = ? AND resource = ?'
                )->execute([(int) $tenant['tenant_id'], 'orders']);
            }
        }

        Webhook::ok('scopes recorded');

    default:
        // Acknowledge anything else so Shopify stops retrying a topic we
        // simply do not handle.
        Webhook::ok('ignored');
}
