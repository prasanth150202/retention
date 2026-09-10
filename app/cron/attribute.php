<?php
/**
 * Project Odysseus — attribution.
 *
 * Works out which campaign gets credit for each order, from our own pixel
 * feed, and classifies every attribution row into a channel.
 *
 * Runs after identity.php, which is what decides who a buyer is and which
 * browsers belong to them. Running it first would attribute a repeat buyer
 * using only the device they happened to check out on.
 *
 * Chunked and resumable: an order is picked up on the next run if this one
 * stops halfway.
 *
 * Usage:
 *   php app/cron/attribute.php [--env=.env.production] [--tenant=1]
 *                              [--limit=500] [--verbose]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';

$opts    = getopt('', ['env::', 'tenant::', 'limit::', 'verbose']);
$verbose = array_key_exists('verbose', $opts);
$onlyOne = isset($opts['tenant']) ? (int) $opts['tenant'] : null;
$limit   = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 500;

odysseus_boot(odysseus_env_arg());

$log = static function (string $m) use ($verbose): void {
    if ($verbose) {
        echo $m, "\n";
    }
};

if (!Job::lock('attribute')) {
    $log('Another attribution run is in progress; exiting.');
    exit(0);
}

$jobId  = Job::start('attribute');
$totals = ['orders' => 0, 'with_touch' => 0, 'channels' => 0, 'failed' => 0];

try {
    $sql = "SELECT tenant_id, display_name FROM tenants WHERE status = 'active'";
    if ($onlyOne !== null) {
        $sql .= ' AND tenant_id = ' . $onlyOne;
    }

    foreach (Db::core()->query($sql)->fetchAll() as $t) {
        $tid = (int) $t['tenant_id'];

        try {
            // Rules are per-tenant and editable, so nothing may carry over
            // from the previous store in this loop.
            Channel::flush($tid);

            $orders = Attribution::pending($tid, $limit);

            if ($orders !== []) {
                $log("--- {$t['display_name']}: " . count($orders) . ' order(s) to attribute');
            }

            // One scan of the event shard per batch rather than per order —
            // see the note on Attribution::resolveBatch().
            $r = Attribution::resolveBatch($tid, $orders);

            $totals['orders']     += $r['orders'];
            $totals['with_touch'] += $r['with_touch'];

            // Shopify's own first/last touch is written by sync.php as orders
            // arrive, before any channel exists to classify it into.
            $totals['channels'] += Attribution::backfillChannels($tid);

            if ($r['orders'] > 0) {
                $log(sprintf(
                    '    %d attributed, %d with a pixel touch (%.0f%%)',
                    $r['orders'],
                    $r['with_touch'],
                    $r['orders'] > 0 ? $r['with_touch'] / $r['orders'] * 100 : 0
                ));
            }
        } catch (Throwable $e) {
            $totals['failed']++;
            $log('    FAILED: ' . $e->getMessage());

            Db::core()->prepare(
                "INSERT INTO job_runs (job_name, tenant_id, started_at, finished_at, status, message)
                 VALUES ('attribute', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'failed', ?)"
            )->execute([$tid, substr($e->getMessage(), 0, 2000)]);
        }
    }

    Job::finish($jobId, 'ok', $totals['orders'], $totals['orders'], json_encode($totals) ?: null);
} catch (Throwable $e) {
    Job::finish($jobId, 'failed', 0, 0, $e->getMessage());
    fwrite(STDERR, 'ATTRIBUTION FAILED: ' . $e->getMessage() . "\n");
    Job::unlock('attribute');
    exit(1);
}

Job::unlock('attribute');

printf(
    "attribute: %d order(s), %d with a pixel touch, %d channel(s) filled, %d failed\n",
    $totals['orders'], $totals['with_touch'], $totals['channels'], $totals['failed']
);

exit($totals['failed'] > 0 ? 1 : 0);
