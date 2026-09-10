<?php
/**
 * Project Odysseus — retention enforcement.
 *
 * Deletes raw data for stores whose retention clock has run out after an
 * uninstall. Runs daily.
 *
 * shop/redact handles the case where Shopify asks. This handles the case where
 * nobody asks and the data should go anyway — a merchant who trialled the app
 * for three days and left has no ongoing relationship that justifies holding
 * their customers' behaviour indefinitely.
 *
 * Uses the same Purge code the webhook does, deliberately. Two implementations
 * would drift, and the way that drift shows up is data quietly surviving a
 * deletion it was supposed to be caught by.
 *
 * Usage:
 *   php app/cron/purge.php [--env=.env.production] [--dry-run] [--verbose]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';
require_once $root . '/app/lib/Purge.php';

$opts    = getopt('', ['env::', 'dry-run', 'verbose']);
$dryRun  = array_key_exists('dry-run', $opts);
$verbose = array_key_exists('verbose', $opts) || $dryRun;

odysseus_boot(odysseus_env_arg());

$log = static function (string $m) use ($verbose): void {
    if ($verbose) {
        echo $m, "\n";
    }
};

if (!Job::lock('purge')) {
    $log('Another purge is already running; exiting.');
    exit(0);
}

$jobId  = Job::start('purge');
$totals = ['stores' => 0, 'events' => 0, 'core' => 0, 'files' => 0, 'failed' => 0];

try {
    $due = Purge::due();

    if ($due === []) {
        $log('Nothing due.');
    }

    foreach ($due as $store) {
        $tid = (int) $store['tenant_id'];

        $log(sprintf(
            '%s (tenant %d) — uninstalled %s, due %s',
            $store['shop_domain'], $tid,
            substr((string) $store['uninstalled_at'], 0, 16),
            substr((string) $store['purge_after'], 0, 16)
        ));

        if ($dryRun) {
            $log('  dry run: nothing deleted');
            $totals['stores']++;
            continue;
        }

        try {
            $r = Purge::store($tid);

            $totals['stores']++;
            $totals['events'] += $r['events'];
            $totals['core']   += $r['core'];
            $totals['files']  += $r['files'];

            $log(sprintf('  deleted %d event(s), %d core row(s), %d file(s)',
                $r['events'], $r['core'], $r['files']));

            if ($r['shards_failed'] !== []) {
                // purged_at is still set, so this will not retry on its own.
                // Surfacing it is what stops it being forgotten.
                $totals['failed']++;
                $log('  UNREACHABLE: ' . implode(', ', $r['shards_failed']));

                Db::core()->prepare(
                    "INSERT INTO job_runs (job_name, tenant_id, started_at, finished_at, status, message)
                     VALUES ('purge', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 'failed', ?)"
                )->execute([
                    $tid,
                    'Purged, but these shards were unreachable and may still hold events: '
                    . implode(', ', $r['shards_failed']),
                ]);
            }
        } catch (Throwable $e) {
            $totals['failed']++;
            $log('  FAILED: ' . $e->getMessage());
        }
    }

    Job::finish($jobId, 'ok', $totals['stores'], $totals['events'], json_encode($totals) ?: null);
} catch (Throwable $e) {
    Job::finish($jobId, 'failed', 0, 0, $e->getMessage());
    fwrite(STDERR, 'PURGE FAILED: ' . $e->getMessage() . "\n");
    Job::unlock('purge');
    exit(1);
}

Job::unlock('purge');

printf(
    "purge: %d store(s), %d event(s), %d core row(s), %d file(s), %d failed%s\n",
    $totals['stores'], $totals['events'], $totals['core'],
    $totals['files'], $totals['failed'],
    $dryRun ? ' (DRY RUN — nothing deleted)' : ''
);

exit($totals['failed'] > 0 ? 1 : 0);
