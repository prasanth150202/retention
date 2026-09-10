<?php
/**
 * Project Odysseus — health checks and alerting.
 *
 * Watches for the failures that are otherwise SILENT. Everything here shares
 * one property: if it happens and nobody notices, data is lost or the
 * dashboard quietly goes stale while appearing to work.
 *
 * The one that justifies the whole file is the shard capacity check. Hostinger
 * caps each database at 3 GB. When a shard fills, MySQL refuses writes, the
 * importer starts failing, and spool files accumulate. Ingest is designed to
 * survive that — nothing is deleted on a failure path — but only if somebody
 * creates the next database before the disk or the inode limit runs out.
 * Discovery weeks later, via a flat dashboard, is not good enough.
 *
 * Alerts are deduplicated in the alerts table so a persistent problem emails
 * once every few hours rather than every run. An alarm that cries wolf gets
 * filtered, and then it is not an alarm at all.
 *
 * Usage:
 *   php app/cron/health_check.php [--env=.env.production] [--quiet]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';

$opts  = getopt('', ['env::', 'quiet']);
$quiet = array_key_exists('quiet', $opts);

odysseus_boot(odysseus_env_arg());

if (!Job::lock('health_check')) {
    exit(0);
}

$jobId    = Job::start('health_check');
$findings = [];

try {
    $findings = array_merge(
        checkShardCapacity(),
        checkSpoolBacklog(),
        checkImportFailures(),
        checkJobFreshness(),
        checkGeoDatabase(),
    );

    foreach ($findings as $f) {
        raise($f['key'], $f['severity'], $f['message']);
    }

    resolveMissing(array_column($findings, 'key'));

    Job::finish($jobId, 'ok', count($findings), 0, json_encode(array_column($findings, 'key')) ?: null);
} catch (Throwable $e) {
    Job::finish($jobId, 'failed', 0, 0, $e->getMessage());
    fwrite(STDERR, 'health_check failed: ' . $e->getMessage() . "\n");
    Job::unlock('health_check');
    exit(1);
}

Job::unlock('health_check');

if (!$quiet) {
    if ($findings === []) {
        echo "health_check: all clear\n";
    } else {
        foreach ($findings as $f) {
            printf("[%s] %s — %s\n", strtoupper($f['severity']), $f['key'], $f['message']);
        }
    }
}

exit($findings === [] ? 0 : 1);


// =====================================================================
// Checks
// =====================================================================

/**
 * Is any shard approaching its size ceiling?
 *
 * The limit is enforced by the host's disk quota rather than by MySQL, so it
 * cannot be read from a variable — it has to be measured from
 * information_schema and compared against the configured ceiling.
 *
 * @return array<int,array{key:string,severity:string,message:string}>
 */
function checkShardCapacity(): array
{
    $out   = [];
    $warn  = (float) Config::get('storage.shard_warn_ratio', 0.80);

    foreach (Shard::all(true) as $shard) {
        try {
            $m = Shard::measure($shard);
        } catch (Throwable $e) {
            $out[] = [
                'key'      => 'shard_unreachable:' . $shard['shard_name'],
                'severity' => 'critical',
                'message'  => "Cannot reach shard {$shard['physical_name']}: " . $e->getMessage()
                            . ' — events for its date range cannot be imported.',
            ];
            continue;
        }

        if ($m['ratio'] >= $warn) {
            $out[] = [
                'key'      => 'shard_full:' . $shard['shard_name'],
                'severity' => $m['ratio'] >= 0.95 ? 'critical' : 'warn',
                'message'  => sprintf(
                    '%s is at %.1f%% of its %.0f GB limit (%.2f GB used). '
                    . 'Create the next shard database and add its credentials to .env '
                    . 'before it fills — writes stop when it does.',
                    $shard['physical_name'], $m['ratio'] * 100,
                    $m['limit'] / 1073741824, $m['bytes'] / 1073741824
                ),
            ];
        }
    }

    // Next year's shard, once the year is nearly over.
    $daysLeft = (int) ((strtotime((gmdate('Y') + 1) . '-01-01') - time()) / 86400);

    if ($daysLeft <= 45) {
        $nextYear = (int) gmdate('Y') + 1;
        [$name]   = Db::shardTarget($nextYear);
        $known    = array_column(Shard::all(), 'physical_name');

        if (!in_array($name, $known, true)) {
            $out[] = [
                'key'      => 'shard_missing:' . $nextYear,
                'severity' => $daysLeft <= 14 ? 'critical' : 'warn',
                'message'  => "The {$nextYear} event database does not exist yet and there are "
                            . "{$daysLeft} days left in the year. Create {$name} in hPanel and add "
                            . "DB_SHARD_{$nextYear}_USER / DB_SHARD_{$nextYear}_PASS to .env. "
                            . 'Ingest halts rather than discarding events when a date cannot be routed.',
            ];
        }
    }

    return $out;
}

/**
 * Is the importer keeping up?
 *
 * A growing spool means events are arriving but not landing. They are not lost
 * — that is the point of the design — but they are invisible to the dashboard
 * until the backlog clears, and they consume inodes while they wait.
 */
function checkSpoolBacklog(): array
{
    $spool = Config::get('paths.spool');
    $files = glob($spool . '/*/*.ndjson') ?: [];

    // The current hour's file is always present and always open; it is not a
    // backlog.
    $current = gmdate('YmdH');
    $stale   = array_filter($files, static fn($f) => basename($f, '.ndjson') !== $current);

    if ($stale === []) {
        return [];
    }

    $oldest    = min(array_map(static fn($f) => (int) @filemtime($f), $stale));
    $ageMin    = (int) ((time() - $oldest) / 60);
    $fileCount = count($stale);

    if ($fileCount > 500) {
        return [[
            'key'      => 'spool_backlog',
            'severity' => 'critical',
            'message'  => "{$fileCount} unimported spool files are waiting. The importer is not "
                        . 'keeping up or is failing. Check import_log for the most recent errors.',
        ]];
    }

    if ($ageMin > 90) {
        return [[
            'key'      => 'spool_backlog',
            'severity' => 'warn',
            'message'  => "Oldest unimported spool file is {$ageMin} minutes old ({$fileCount} waiting). "
                        . 'Expected under 30 for a five-minute cron. Check that the import cron is running.',
        ]];
    }

    return [];
}

/** Files the importer tried and could not commit. */
function checkImportFailures(): array
{
    $stmt = Db::core()->query(
        "SELECT COUNT(*) AS n, MAX(message) AS last_message
           FROM import_log
          WHERE status = 'failed' AND started_at > UTC_TIMESTAMP() - INTERVAL 6 HOUR"
    );
    $row = $stmt->fetch();

    if ((int) ($row['n'] ?? 0) === 0) {
        return [];
    }

    return [[
        'key'      => 'import_failures',
        'severity' => 'critical',
        'message'  => sprintf(
            '%d spool file(s) failed to import in the last 6 hours. They are retained and will be '
            . 'retried, so nothing is lost yet. Most recent error: %s',
            (int) $row['n'],
            substr((string) ($row['last_message'] ?? 'unknown'), 0, 300)
        ),
    ]];
}

/**
 * Has a scheduled job stopped running?
 *
 * Cron entries get removed, disabled or silently fail on shared hosting. A job
 * that never runs raises no error anywhere — the dashboard simply stops
 * changing, which looks like quiet traffic rather than a fault.
 */
function checkJobFreshness(): array
{
    // Hours without a success before the job counts as stalled. Each is a few
    // multiples of its cron interval, so an ordinary skipped run stays quiet.
    $expected = [
        'import'    => 2,    // every 5 minutes
        'sync'      => 3,    // hourly
        'identity'  => 3,    // hourly
        'attribute' => 3,    // hourly
        'rollup'    => 3,    // hourly
        'purge'     => 48,   // daily; a missed purge means data kept past its
                             // retention period, which is a compliance problem
                             // rather than a stale dashboard
    ];

    $out = [];

    foreach ($expected as $job => $maxHours) {
        $last = Job::lastSuccess($job);

        if ($last === null) {
            continue;   // never run; nothing to compare against yet
        }

        $ageHours = (time() - strtotime($last . ' UTC')) / 3600;

        if ($ageHours > $maxHours) {
            $out[] = [
                'key'      => 'job_stale:' . $job,
                'severity' => $ageHours > $maxHours * 6 ? 'critical' : 'warn',
                'message'  => sprintf(
                    "The '%s' job last succeeded %.1f hours ago (expected within %d). "
                    . 'Check the cron entry still exists and is enabled in hPanel.',
                    $job, $ageHours, $maxHours
                ),
            ];
        }
    }

    return $out;
}

/**
 * Geo enrichment is optional, so this is a note rather than an alarm: events
 * still store, just without a location.
 */
function checkGeoDatabase(): array
{
    $g = GeoIp::describe();

    if ($g['usable']) {
        return [];
    }

    return [[
        'key'      => 'geoip_missing',
        'severity' => 'warn',
        'message'  => 'No usable geo database at ' . $g['path']
                    . '. Events still import, but without country or city, so the Geography tab '
                    . 'will be empty. Press "Download geo database" on the setup page.',
    ]];
}


// =====================================================================
// Alert plumbing
// =====================================================================

/**
 * Record a finding and email it, at most once per renotify window.
 */
function raise(string $key, string $severity, string $message): void
{
    $pdo = Db::core();

    $pdo->prepare(
        'INSERT INTO alerts (alert_key, severity, first_raised_at, last_raised_at, raise_count, last_message)
         VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 1, ?)
         ON DUPLICATE KEY UPDATE
            severity       = VALUES(severity),
            last_raised_at = UTC_TIMESTAMP(),
            raise_count    = raise_count + 1,
            last_message   = VALUES(last_message),
            resolved_at    = NULL'
    )->execute([$key, $severity, $message]);

    $stmt = $pdo->prepare('SELECT last_notified_at FROM alerts WHERE alert_key = ?');
    $stmt->execute([$key]);
    $lastNotified = $stmt->fetchColumn();

    $windowMin = (int) Config::get('alerts.renotify_after_min', 360);

    if ($lastNotified !== false && $lastNotified !== null
        && (time() - strtotime($lastNotified . ' UTC')) < $windowMin * 60) {
        return;   // already told them recently
    }

    if (notify($key, $severity, $message)) {
        $pdo->prepare('UPDATE alerts SET last_notified_at = UTC_TIMESTAMP() WHERE alert_key = ?')
            ->execute([$key]);
    }
}

/** Close alerts that no longer fire, so the table reflects current state. */
function resolveMissing(array $activeKeys): void
{
    $pdo = Db::core();

    if ($activeKeys === []) {
        $pdo->exec('UPDATE alerts SET resolved_at = UTC_TIMESTAMP() WHERE resolved_at IS NULL');
        return;
    }

    $marks = implode(',', array_fill(0, count($activeKeys), '?'));
    $pdo->prepare(
        "UPDATE alerts SET resolved_at = UTC_TIMESTAMP()
          WHERE resolved_at IS NULL AND alert_key NOT IN ({$marks})"
    )->execute($activeKeys);
}

function notify(string $key, string $severity, string $message): bool
{
    $to = Config::get('alerts.email_to');

    if ($to === null || $to === '') {
        return false;   // alerting disabled; the alerts table still records it
    }

    $subject = sprintf('[Odysseus %s] %s', strtoupper($severity), $key);
    $body    = $message . "\n\n"
             . "Host: " . Config::get('hosts.base') . "\n"
             . "Time: " . gmdate('Y-m-d H:i:s') . " UTC\n";

    $headers = 'From: ' . Config::get('alerts.email_from', 'odysseus@localhost') . "\r\n"
             . "Content-Type: text/plain; charset=utf-8\r\n";

    return @mail($to, $subject, $body, $headers);
}
