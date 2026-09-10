<?php
/**
 * Project Odysseus — rollups.
 *
 * Aggregates events and orders into the tables the dashboard reads. Nothing
 * in the UI queries raw events, so if this stops running the dashboard stops
 * changing while data keeps arriving — it looks like a quiet week, not a
 * fault, which is why health_check watches it.
 *
 * Runs last in the hourly chain: import -> sync -> identity -> attribute ->
 * rollup. Each step uses what the one before it settled.
 *
 * Days inside the reclose window are recomputed on every run. Everything
 * older is written once and left alone.
 *
 * Usage:
 *   php app/cron/rollup.php [--env=.env.production] [--tenant=1]
 *                           [--days=10] [--date=2026-09-01] [--verbose]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';

$opts    = getopt('', ['env::', 'tenant::', 'days::', 'date::', 'verbose']);
$verbose = array_key_exists('verbose', $opts);
$onlyOne = isset($opts['tenant']) ? (int) $opts['tenant'] : null;
$onlyDay = $opts['date'] ?? null;

// A first run on a store with history has a lot of days to get through. The
// cap keeps any single run inside a shared-host time limit; the next run
// carries on where this one stopped.
$maxDays = isset($opts['days']) ? max(1, (int) $opts['days']) : 10;

odysseus_boot(odysseus_env_arg());

$log = static function (string $m) use ($verbose): void {
    if ($verbose) {
        echo $m, "\n";
    }
};

if (!Job::lock('rollup')) {
    $log('Another rollup run is in progress; exiting.');
    exit(0);
}

$jobId  = Job::start('rollup');
$totals = ['days' => 0, 'rows' => 0, 'cohorts' => 0, 'failed' => 0];

try {
    $sql = "SELECT tenant_id, display_name FROM tenants WHERE status = 'active'";
    if ($onlyOne !== null) {
        $sql .= ' AND tenant_id = ' . $onlyOne;
    }

    foreach (Db::core()->query($sql)->fetchAll() as $t) {
        $tid = (int) $t['tenant_id'];

        try {
            Channel::flush($tid);

            $days = $onlyDay !== null
                ? [(string) $onlyDay]
                : Rollup::pendingDays($tid, $maxDays);

            if ($days !== []) {
                $log("--- {$t['display_name']}: " . count($days) . ' day(s) — '
                    . $days[0] . ' to ' . $days[count($days) - 1]);
            }

            foreach ($days as $day) {
                $written = Rollup::day($tid, $day);
                $rows    = array_sum($written);

                $totals['days']++;
                $totals['rows'] += $rows;

                $log(sprintf('    %s  %d row(s)  [%s]', $day, $rows, implode(' ', array_map(
                    static fn($k, $v) => "{$k}={$v}",
                    array_keys($written),
                    $written
                ))));
            }

            // Cohorts are not per-day: a cohort's 180-day figure keeps moving
            // for six months. Recomputed once per run, and only when a day
            // actually changed — nothing else can have moved them.
            if ($days !== []) {
                $totals['cohorts'] += array_sum(Rollup::cohorts($tid));
            }
        } catch (Throwable $e) {
            $totals['failed']++;
            $log('    FAILED: ' . $e->getMessage());

            Db::core()->prepare(
                "INSERT INTO job_runs (job_name, tenant_id, started_at, finished_at, status, message)
                 VALUES ('rollup', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'failed', ?)"
            )->execute([$tid, substr($e->getMessage(), 0, 2000)]);
        }
    }

    Job::finish($jobId, 'ok', $totals['days'], $totals['rows'], json_encode($totals) ?: null);
} catch (Throwable $e) {
    Job::finish($jobId, 'failed', 0, 0, $e->getMessage());
    fwrite(STDERR, 'ROLLUP FAILED: ' . $e->getMessage() . "\n");
    Job::unlock('rollup');
    exit(1);
}

Job::unlock('rollup');

printf(
    "rollup: %d day(s), %d row(s), %d cohort row(s), %d failed\n",
    $totals['days'], $totals['rows'], $totals['cohorts'], $totals['failed']
);

exit($totals['failed'] > 0 ? 1 : 0);
