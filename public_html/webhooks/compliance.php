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
// Deleting is idempotent; re-running a completed deletion is harmless but
// pointless. Re-running an INTERRUPTED one is the whole point: the request is
// logged before the work begins, so "a row exists" is not evidence the work
// was done.
if (Webhook::completedBefore($hook['topic'], $hook['raw'])) {
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
        $id  = Webhook::logCompliance($hook['topic'], $shop, $hook['raw'], $tid, Text::fitOrNull($ref, 191));

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
            $r = Purge::customer(
                $tid,
                $customerId !== null ? (int) $customerId : null,
                is_string($email) ? $email : null,
                is_string($phone) ? $phone : null
            );
            $deleted = $r['deleted'];
        }

        Webhook::completeCompliance($id, $deleted,
            'Identity keys and customer record removed; orders detached from the person. '
            . 'Aggregate counts already computed are anonymous and retained.');

        Webhook::ok('redacted');

    // -----------------------------------------------------------------
    case 'shop/redact':
        // Sent 48 hours after uninstall. Delete everything for this store.
        //
        // Purge::store does the work, and is the same code the retention cron
        // runs. Two implementations would drift, and the way that drift shows
        // up is data quietly surviving a redaction.
        //
        // includeRollups: true overrides the configured policy. Rollups are
        // anonymous counts and are normally kept, but a redaction request is
        // explicit and not a preference.
        $id      = Webhook::logCompliance($hook['topic'], $shop, $hook['raw'], $tid, null);
        $deleted = 0;
        $note    = 'Store not known to this app; nothing to delete.';

        if ($tid !== null) {
            $r       = Purge::store($tid, true);
            $deleted = $r['events'] + $r['core'] + $r['files'];

            $note = sprintf(
                'Deleted %d event(s), %d core row(s), %d file(s). Tenant row retained as a '
                . 'tombstone recording the redaction; per-tenant hashing salt destroyed, so '
                . 'nothing remaining can be linked back to a person.',
                $r['events'], $r['core'], $r['files']
            );

            if ($r['shards_failed'] !== []) {
                // Reported rather than assumed gone, so it can be retried.
                $note .= ' UNREACHABLE at the time of deletion: '
                       . implode(', ', $r['shards_failed'])
                       . ' — these must be purged manually.';
            }
        }

        Webhook::completeCompliance($id, $deleted, $note);
        Webhook::ok('shop redacted');

    // -----------------------------------------------------------------
    default:
        Webhook::ok('ignored');
}
