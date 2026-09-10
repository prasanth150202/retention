<?php
/**
 * Project Odysseus — identity resolution.
 *
 * Assigns every order to a person and recomputes order_sequence. Runs hourly,
 * after the order sync has caught up.
 *
 * Everything retention measures rests on this: "repeat purchase rate", "days
 * to second order" and every cohort curve are statements about one person
 * placing more than one order.
 *
 * Chunked and resumable. A store with years of history is drained across
 * several runs rather than one that a time limit kills halfway.
 *
 * Usage:
 *   php app/cron/identity.php [--env=.env.production] [--tenant=1] [--verbose]
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
$limit   = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 2000;

odysseus_boot(odysseus_env_arg());

$log = static function (string $m) use ($verbose): void {
    if ($verbose) {
        echo $m, "\n";
    }
};

if (!Job::lock('identity')) {
    $log('Another identity run is in progress; exiting.');
    exit(0);
}

$jobId  = Job::start('identity');
$totals = ['resolved' => 0, 'created' => 0, 'merged' => 0, 'resequenced' => 0, 'failed' => 0];

try {
    $sql = "SELECT tenant_id, display_name FROM tenants WHERE status = 'active'";
    if ($onlyOne !== null) {
        $sql .= ' AND tenant_id = ' . $onlyOne;
    }

    foreach (Db::core()->query($sql)->fetchAll() as $t) {
        $tid = (int) $t['tenant_id'];

        try {
            $orders = Identity::unresolved($tid, $limit);

            if ($orders !== []) {
                $log("--- {$t['display_name']}: " . count($orders) . ' unresolved order(s)');
            }

            foreach ($orders as $order) {
                $r = Identity::resolveOrder($tid, $order);

                $totals['resolved']++;
                $totals['created'] += $r['created'] ? 1 : 0;
                $totals['merged']  += $r['merged'];
            }

            // Resequencing is separate because a merge changes the sequence of
            // every order belonging to the survivor, not just the one being
            // resolved. Doing it per-order would rewrite the same rows
            // repeatedly and still miss merges queued by a later order.
            foreach (Identity::pendingResequence($tid, $limit) as $personId) {
                Identity::resequence($tid, $personId);
                $totals['resequenced']++;
            }
        } catch (Throwable $e) {
            $totals['failed']++;
            $log("    FAILED: " . $e->getMessage());

            Db::core()->prepare(
                "INSERT INTO job_runs (job_name, tenant_id, started_at, finished_at, status, message)
                 VALUES ('identity', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'failed', ?)"
            )->execute([$tid, substr($e->getMessage(), 0, 2000)]);
        }
    }

    Job::finish($jobId, 'ok', $totals['resolved'], $totals['resolved'], json_encode($totals) ?: null);
} catch (Throwable $e) {
    Job::finish($jobId, 'failed', 0, 0, $e->getMessage());
    fwrite(STDERR, 'IDENTITY FAILED: ' . $e->getMessage() . "\n");
    Job::unlock('identity');
    exit(1);
}

Job::unlock('identity');

printf(
    "identity: %d order(s) resolved, %d person(s) created, %d merge(s), %d resequenced, %d failed\n",
    $totals['resolved'], $totals['created'], $totals['merged'],
    $totals['resequenced'], $totals['failed']
);

exit($totals['failed'] > 0 ? 1 : 0);
